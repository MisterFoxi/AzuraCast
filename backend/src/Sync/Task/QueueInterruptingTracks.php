<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Event\Radio\AnnotateNextSong;
use App\Radio\Adapters;
use App\Radio\AutoDJ\HourBoundaryLegalIdResolver;
use App\Radio\AutoDJ\HourBoundaryPlanner;
use App\Radio\AutoDJ\Queue;
use App\Radio\AutoDJ\Scheduler;
use App\Radio\AutoDJ\SponsorGuaranteedPlayoutService;
use App\Radio\Backend\Liquidsoap;
use App\Radio\Enums\LiquidsoapQueues;
use Carbon\CarbonImmutable;
use Monolog\LogRecord;
use Psr\EventDispatcher\EventDispatcherInterface;

final class QueueInterruptingTracks extends AbstractTask
{
    public function __construct(
        private readonly Queue $queue,
        private readonly Adapters $adapters,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly Scheduler $scheduler,
        private readonly SponsorGuaranteedPlayoutService $sponsorGuarantee,
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly HourBoundaryLegalIdResolver $legalIdResolver,
        private readonly StationQueueRepository $queueRepo,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return self::SCHEDULE_EVERY_MINUTE;
    }

    /**
     * Manually process any requests for stations that use "Manual AutoDJ" mode.
     *
     * @param bool $force
     */
    public function run(bool $force = false): void
    {
        foreach ($this->iterateStations() as $station) {
            $this->logger->pushProcessor(
                function (LogRecord $record) use ($station) {
                    $record->extra['station'] = [
                        'id' => $station->id,
                        'name' => $station->name,
                    ];
                    return $record;
                }
            );

            try {
                $this->queueForStation($station);
            } finally {
                $this->logger->popProcessor();
            }
        }
    }

    private function queueForStation(Station $station): void
    {
        if (!$station->supportsAutoDjQueue()) {
            return;
        }

        $now = CarbonImmutable::now()->toDateTimeImmutable();

        // Top-of-hour mandatory legal ID: real-time interrupt trigger. The look-ahead
        // BuildQueue flow evaluates the *future* queue tail, so its expected play time
        // never coincides with the real-time pre-:00 window; the ID must be injected
        // here, in wall-clock time, as an interruption.
        $topOfHourId = null;
        if ($this->hourBoundaryPlanner->isTopOfHourInterruptDue($station, $now)) {
            $this->logger->debug('[TOTH-DEBUG] Interrupt due -> resolving legal ID.', [
                'station_id' => $station->id,
                'now' => $now->format(DATE_ATOM),
            ]);

            $recentHistory = $this->queueRepo->getRecentlyPlayedByTimeRange(
                $station,
                $now,
                $station->backend_config->duplicate_prevention_time_range
            );

            $topOfHourId = $this->legalIdResolver->resolveMandatoryLegalId(
                $station,
                $recentHistory,
                $now
            );

            if (null !== $topOfHourId) {
                $this->em->flush();
                $this->logger->debug('[TOTH-DEBUG] Legal ID resolved and persisted.', [
                    'station_id' => $station->id,
                    'queue_id' => $topOfHourId->id,
                    'media_id' => $topOfHourId->media?->id,
                ]);
            } else {
                $this->logger->debug('[TOTH-DEBUG] Interrupt due but resolver returned null.', [
                    'station_id' => $station->id,
                ]);
            }
        }

        // This feature is not useful for stations without interrupting playlists,
        // unless a mandatory top-of-hour ID is due right now.
        $hasInterruptingPlaylist = (null !== $topOfHourId);

        if (!$hasInterruptingPlaylist) {
            $tz = $station->getTimezoneObject();
            foreach ($station->playlists as $playlist) {
                $byPlayable = $playlist->isPlayable(true);
                $byStrict = $this->scheduler->isPlaylistStrictStartDueNow($playlist, $tz);
                if ($byPlayable || $byStrict) {
                    $hasInterruptingPlaylist = true;
                    $this->logger->debug('[TOTH-DEBUG] Interrupting gate tripped by playlist.', [
                        'station_id' => $station->id,
                        'playlist_id' => $playlist->id,
                        'playlist_name' => $playlist->name,
                        'by_is_playable_interrupting' => $byPlayable,
                        'by_strict_start' => $byStrict,
                    ]);
                    break;
                }
            }
        }

        if (!$hasInterruptingPlaylist) {
            $behindPace = $this->sponsorGuarantee->getPlaylistsBehindPace($station);
            if (!empty($behindPace)) {
                $hasInterruptingPlaylist = true;
                $this->logger->debug('[TOTH-DEBUG] Interrupting gate tripped by sponsor-guarantee.', [
                    'station_id' => $station->id,
                    'behind_pace_count' => count($behindPace),
                ]);
            }
        }

        if (!$hasInterruptingPlaylist) {
            return;
        }

        // This feature only works on Liquidsoap.
        $backend = $this->adapters->getBackendAdapter($station);

        if (!($backend instanceof Liquidsoap)) {
            return;
        }

        // A due mandatory top-of-hour ID goes to its own priority queue, which sits
        // above the interrupting queue in the Liquidsoap fallback (track_sensitive =
        // false), so it cuts in immediately regardless of ordinary interrupting
        // content. No empty-check: the boundary dedup already guarantees one per hour.
        if (null !== $topOfHourId) {
            $event = AnnotateNextSong::fromStationQueue($topOfHourId, true);
            $this->eventDispatcher->dispatch($event);
            $track = $event->buildAnnotations();

            $response = $backend->enqueue($station, LiquidsoapQueues::TopOfHour, $track);

            $this->logger->debug('[TOTH-DEBUG] Legal ID enqueued to priority (TopOfHour) queue.', [
                'station_id' => $station->id,
                'queue_id' => $topOfHourId->id,
                'media_id' => $topOfHourId->media?->id,
                'response' => $response,
            ]);

            return;
        }

        // Ordinary interrupting content: only when its queue is empty.
        if (!$backend->isQueueEmpty($station, LiquidsoapQueues::Interrupting)) {
            $this->logger->info('Interrupting queue: Queue is not empty!');
            return;
        }

        $songsToPlay = $this->queue->getInterruptingQueue($station);

        if (empty($songsToPlay)) {
            return;
        }

        foreach ($songsToPlay as $sq) {
            $event = AnnotateNextSong::fromStationQueue($sq, true);
            $this->eventDispatcher->dispatch($event);

            $track = $event->buildAnnotations();

            $this->logger->debug('Submitting request to AutoDJ.', ['track' => $track]);
            $response = $backend->enqueue($station, LiquidsoapQueues::Interrupting, $track);
            $this->logger->debug('AutoDJ request response', ['response' => $response]);
        }
    }
}
