<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Repository\StationQueueRepository;
use App\Event\Radio\BuildQueue;
use App\Radio\Schedule\ScheduleConflictChecker;
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
        if (!empty($event->getNextSongs()) || $event->isInterrupting()) {
            return;
        }

        $station = $event->getStation();
        $expectedPlayTime = $event->getExpectedPlayTime();

        if (!$this->hourBoundaryPlanner->isTopOfHourProtectionEnabled($station)) {
            return;
        }

        if ($this->conflictChecker->hasEmergencyScheduleActive($station, $expectedPlayTime)) {
            return;
        }

        if (!$this->hourBoundaryPlanner->isTopOfHourIdDue($station, $expectedPlayTime)) {
            return;
        }

        $recentHistory = $this->queueRepo->getRecentlyPlayedByTimeRange(
            $station,
            $expectedPlayTime,
            $station->backend_config->duplicate_prevention_time_range
        );

        $nextSong = $this->legalIdResolver->resolveMandatoryLegalId(
            $station,
            $recentHistory,
            $expectedPlayTime,
        );

        if (null === $nextSong) {
            $this->logger->warning('Top-of-hour ID: could not resolve mandatory legal_id track.');

            return;
        }

        if ($event->setNextSongs($nextSong)) {
            $this->em->flush();
            $this->logger->info('Top-of-hour ID resolved next song.');
        }
    }

}
