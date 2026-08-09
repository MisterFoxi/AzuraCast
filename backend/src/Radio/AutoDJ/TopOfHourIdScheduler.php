<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Repository\StationQueueRepository;
use App\Event\Radio\BuildQueue;
use App\Radio\Schedule\ScheduleConflictChecker;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Queues mandatory legal_id at :00 when station-wide top-of-hour protection is enabled.
 */
final class TopOfHourIdScheduler implements EventSubscriberInterface
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly HourBoundaryLegalIdResolver $legalIdResolver,
        private readonly StationQueueRepository $queueRepo,
        private readonly ScheduleConflictChecker $conflictChecker,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => [
                ['buildTopOfHourId', 2],
            ],
        ];
    }

    public function buildTopOfHourId(BuildQueue $event): void
    {
        $station = $event->getStation();
        $expectedPlayTime = $event->getExpectedPlayTime();
        $nextSongs = $event->getNextSongs();
        $isInterrupting = $event->isInterrupting();

        $this->logger->info('[TOPH DEBUG] BuildQueue received by TopOfHourIdScheduler.', [
            'station_id' => $station->id,
            'expected_play_time' => $expectedPlayTime->format(DateTimeImmutable::ATOM),
            'expected_play_time_local' => $expectedPlayTime
                ->setTimezone($station->getTimezoneObject())
                ->format(DateTimeImmutable::ATOM),
            'existing_next_songs' => count($nextSongs),
            'interrupting' => $isInterrupting,
        ]);

        if (!empty($nextSongs)) {
            $this->logger->info('[TOPH DEBUG] Skipping before TOPH evaluation.', [
                'reason' => 'next_songs_already_selected',
                'existing_next_songs' => count($nextSongs),
            ]);

            return;
        }

        $protectionEnabled = $this->hourBoundaryPlanner->isTopOfHourProtectionEnabled($station);
        $this->logger->info('[TOPH DEBUG] Protection status.', [
            'enabled' => $protectionEnabled,
        ]);

        if (!$protectionEnabled) {
            $this->logger->info('[TOPH DEBUG] Skipping TOPH: protection disabled.');
            return;
        }

        if (!$isInterrupting) {
            $this->logger->info('[TOPH DEBUG] Skipping TOPH: waiting for interrupting queue at hour boundary.');
            return;
        }

        $emergencyActive = $this->conflictChecker->hasEmergencyScheduleActive($station, $expectedPlayTime);
        if ($emergencyActive) {
            $this->logger->info('[TOPH DEBUG] Skipping TOPH: emergency schedule active.');
            return;
        }

        $timezone = $station->getTimezoneObject();
        $local = $expectedPlayTime->setTimezone($timezone);
        $secondsAfterTop = $local->getTimestamp() - $local->setTime((int)$local->format('H'), 0)->getTimestamp();
        $tolerance = $this->hourBoundaryPlanner->getComplianceToleranceSeconds($station);
        $targetTop = $this->hourBoundaryPlanner->resolveTopOfHourExpectedPlayAt($station, $expectedPlayTime);
        $isDue = $this->hourBoundaryPlanner->isTopOfHourInterruptDue($station, $expectedPlayTime);

        $this->logger->info('[TOPH DEBUG] Interrupt due evaluation.', [
            'seconds_after_top' => $secondsAfterTop,
            'tolerance_seconds' => $tolerance,
            'target_top' => $targetTop->format(DateTimeImmutable::ATOM),
            'is_due' => $isDue,
        ]);

        if (!$isDue) {
            $this->logger->info('[TOPH DEBUG] Skipping TOPH: ID is not due.');
            return;
        }

        $recentHistory = $this->queueRepo->getRecentlyPlayedByTimeRange(
            $station,
            $expectedPlayTime,
            $station->backend_config->duplicate_prevention_time_range
        );

        $this->logger->info('[TOPH DEBUG] Resolving mandatory legal ID.', [
            'recent_history_count' => count($recentHistory),
        ]);

        $nextSong = $this->legalIdResolver->resolveMandatoryLegalId(
            $station,
            $recentHistory,
            $expectedPlayTime,
        );

        if (null === $nextSong) {
            $this->logger->warning('[TOPH DEBUG] Top-of-hour ID: could not resolve mandatory legal_id track.');

            return;
        }

        if ($event->setNextSongs($nextSong)) {
            $this->em->flush();
            $this->logger->info('[TOPH DEBUG] Top-of-hour ID resolved and selected.', [
                'media_id' => $nextSong->media?->id,
                'song_id' => $nextSong->song_id,
                'duration' => $nextSong->duration,
                'target_top' => $targetTop->format(DateTimeImmutable::ATOM),
            ]);
        } else {
            $this->logger->warning('[TOPH DEBUG] Legal ID resolved but BuildQueue rejected it.', [
                'song_id' => $nextSong->song_id,
                'last_song_id' => $event->getLastPlayedSongId(),
            ]);
        }
    }

}
