<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Cache\QueueLogCache;
use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Utilities\Time;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Monolog\Handler\TestHandler;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LogLevel;

/**
 * Public methods related to the AutoDJ Queue process.
 */
final class Queue
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    private const int MAX_SELECTION_ATTEMPTS = 5;

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly StationQueueRepository $queueRepo,
        private readonly Scheduler $scheduler,
        private readonly QueueLogCache $queueLogCache
    ) {
    }

    public function buildQueue(Station $station): void
    {
        // Early-fail if the station is disabled.
        if (!$station->supportsAutoDjQueue()) {
            $this->logger->info('Cannot build queue: station does not support AutoDJ queue.');
            return;
        }

        // Adjust "expectedCueTime" time from current queue.
        $expectedCueTime = Time::nowUtc();

        // Get expected play time of each item.
        $currentSong = $station->current_song;
        if (null !== $currentSong) {
            $expectedPlayTime = $this->addDurationToTime(
                $station,
                $currentSong->timestamp_start,
                $currentSong->duration
            );

            if ($expectedPlayTime < $expectedCueTime) {
                $expectedPlayTime = $expectedCueTime;
            }
        } else {
            $expectedPlayTime = $expectedCueTime;
        }

        $maxQueueLength = max($station->backend_config->autodj_queue_length, 2);

        $upcomingQueue = $this->queueRepo->getUnplayedQueue($station);

        $lastSongId = null;
        $queueLength = 0;

        foreach ($upcomingQueue as $queueRow) {
            if ($queueRow->sent_to_autodj) {
                $expectedCueTime = $this->addDurationToTime(
                    $station,
                    $queueRow->timestamp_cued,
                    $queueRow->duration
                );

                if (0 === $queueLength) {
                    $queueLength = 1;
                }
            } else {
                if (!$this->isQueueRowStillValid($queueRow, $expectedPlayTime)) {
                    $this->em->remove($queueRow);
                    continue;
                }

                $queueRow->timestamp_cued = $expectedCueTime;
                $expectedCueTime = $this->addDurationToTime($station, $expectedCueTime, $queueRow->duration);

                // Only append to queue length for uncued songs.
                $queueLength++;
            }

            $queueRow->timestamp_played = $expectedPlayTime;
            $this->em->persist($queueRow);

            $expectedPlayTime = $this->addDurationToTime($station, $expectedPlayTime, $queueRow->duration);

            $lastSongId = $queueRow->song_id;
        }

        $this->em->flush();

        // Build the remainder of the queue.
        while ($queueLength < $maxQueueLength) {
            $this->logger->debug(
                'Adding to station queue.',
                [
                    'now' => (string)$expectedPlayTime,
                ]
            );

            // Push another test handler specifically for this one queue task.
            $testHandler = new TestHandler(LogLevel::DEBUG, true);
            $this->logger->pushHandler($testHandler);

            try {
                $event = $this->dispatchTransactionalBuildQueue(
                    $station,
                    $expectedCueTime,
                    $expectedPlayTime,
                    $lastSongId,
                );
            } finally {
                $this->logger->popHandler();
            }

            $nextSongs = $event->getNextSongs();

            if (empty($nextSongs)) {
                $this->em->flush();
                break;
            }

            foreach ($nextSongs as $queueRow) {
                $queueRow->timestamp_cued = $expectedCueTime;
                $queueRow->timestamp_played = $expectedPlayTime;
                $queueRow->updateVisibility();
                $this->em->persist($queueRow);
                $this->em->flush();

                $this->queueLogCache->setLog($queueRow, $testHandler->getRecords());

                $lastSongId = $queueRow->song_id;

                $expectedCueTime = $this->addDurationToTime(
                    $station,
                    $expectedCueTime,
                    $queueRow->duration
                );
                $expectedPlayTime = $this->addDurationToTime(
                    $station,
                    $expectedPlayTime,
                    $queueRow->duration
                );

                $queueLength++;
            }
        }
    }

    /**
     * @param Station $station
     * @return StationQueue[]|null
     */
    public function getInterruptingQueue(Station $station): ?array
    {
        // Early-fail if the station is disabled.
        if (!$station->supportsAutoDjQueue()) {
            $this->logger->notice('Cannot build queue: station does not support AutoDJ queue.');
            return null;
        }

        $tzObject = $station->getTimezoneObject();
        $expectedPlayTime = CarbonImmutable::now($tzObject);

        $this->logger->debug(
            'Fetching interrupting queue.',
            [
                'now' => (string)$expectedPlayTime,
            ]
        );

        // Push another test handler specifically for this one queue task.
        $testHandler = new TestHandler(LogLevel::DEBUG, true);
        $this->logger->pushHandler($testHandler);

        try {
            $event = $this->dispatchTransactionalBuildQueue(
                $station,
                $expectedPlayTime,
                $expectedPlayTime,
                null,
                true,
            );
        } finally {
            $this->logger->popHandler();
        }

        $nextSongs = $event->getNextSongs();

        if (empty($nextSongs)) {
            $this->em->flush();
            return null;
        }

        foreach ($nextSongs as $queueRow) {
            $queueRow->is_played = true;
            $queueRow->timestamp_cued = $expectedPlayTime;
            $queueRow->timestamp_played = $expectedPlayTime;
            $queueRow->updateVisibility();

            $this->em->persist($queueRow);
            $this->em->flush();

            $this->queueLogCache->setLog($queueRow, $testHandler->getRecords());

            $expectedPlayTime = $this->addDurationToTime(
                $station,
                $expectedPlayTime,
                $queueRow->duration
            );
        }

        return $nextSongs;
    }

    /**
     * Run selection and all selector mutations inside one transaction. A validator
     * may reject the candidate; in that case every database mutation is rolled
     * back and the rejected song IDs are excluded from the next attempt.
     */
    private function dispatchTransactionalBuildQueue(
        Station &$station,
        DateTimeImmutable $expectedCueTime,
        DateTimeImmutable $expectedPlayTime,
        ?string $lastSongId,
        bool $isInterrupting = false,
    ): BuildQueue {
        $excludedSongIds = [];
        $lastEvent = null;

        for ($attempt = 1; $attempt <= self::MAX_SELECTION_ATTEMPTS; $attempt++) {
            $event = new BuildQueue(
                $station,
                $expectedCueTime,
                $expectedPlayTime,
                $lastSongId,
                $isInterrupting,
                $excludedSongIds,
            );
            $lastEvent = $event;
            $connection = $this->em->getConnection();
            $connection->beginTransaction();

            try {
                $this->dispatcher->dispatch($event);

                if ([] !== $event->getNextSongs() && !$event->wasRejected()) {
                    // Flush selector state only after all lower-priority validators
                    // have accepted the candidate, then make it durable atomically.
                    $this->em->flush();
                    $connection->commit();

                    try {
                        $event->commitAcceptedSelection();
                    } catch (\Throwable $e) {
                        $this->logger->warning(
                            'Queue selection was accepted but a deferred side effect failed.',
                            ['exception' => $e->getMessage()]
                        );
                    }

                    return $event;
                }

                $connection->rollBack();
            } catch (\Throwable $e) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                $this->em->clear();
                throw $e;
            }

            // Rollback does not restore already-mutated PHP objects. Detach them
            // and continue from a fresh station graph before another selection.
            $this->em->clear();
            $station = $this->em->refetch($station);

            if (!$event->wasRejected()) {
                return $event;
            }

            $excludedSongIds = array_values(array_unique([
                ...$excludedSongIds,
                ...$event->getRejectedSongIds(),
            ]));

            $this->logger->warning(
                'Queue candidate rejected; retrying selection.',
                [
                    'attempt' => $attempt,
                    'max_attempts' => self::MAX_SELECTION_ATTEMPTS,
                    'reason' => $event->getRejectionReason(),
                    'excluded_song_ids' => $excludedSongIds,
                ]
            );
        }

        $this->logger->error(
            'Queue selection attempts exhausted.',
            [
                'max_attempts' => self::MAX_SELECTION_ATTEMPTS,
                'excluded_song_ids' => $excludedSongIds,
            ]
        );

        return $lastEvent ?? new BuildQueue(
            $station,
            $expectedCueTime,
            $expectedPlayTime,
            $lastSongId,
            $isInterrupting,
            $excludedSongIds,
        );
    }

    private function addDurationToTime(
        Station $station,
        DateTimeInterface $now,
        ?float $duration
    ): CarbonImmutable {
        $duration ??= 1;

        $startNext = $station->backend_config->getCrossfadeDuration();

        $now = CarbonImmutable::instance($now)->addSeconds($duration);
        return ($duration >= $startNext)
            ? $now->subMilliseconds((int)($startNext * 1000))
            : $now;
    }

    private function isQueueRowStillValid(
        StationQueue $queueRow,
        DateTimeImmutable $expectedPlayTime
    ): bool {
        $playlist = $queueRow->playlist;
        if (null === $playlist) {
            return true;
        }

        return $playlist->is_enabled &&
            $this->scheduler->isPlaylistScheduledToPlayNow(
                $playlist,
                $expectedPlayTime,
                true
            );
    }
}
