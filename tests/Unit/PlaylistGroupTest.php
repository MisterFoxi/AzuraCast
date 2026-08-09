<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Enums\PlaylistTypes;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\QueueBuilder;
use App\Tests\AutoDjTestHarness;
use App\Tests\Module;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;

final class PlaylistGroupTest extends Unit
{
    private AutoDjTestHarness $harness;

    protected function _inject(Module $testsModule): void
    {
        $this->harness = new AutoDjTestHarness(
            $testsModule->em,
            $testsModule->container->get(QueueBuilder::class),
        );
    }

    public function testGroupQueuesAllMembersAsOneOrderedBlock(): void
    {
        $fixture = $this->harness->createSequentialGroup(
            [
                'A' => ['A1', 'A2'],
                'B' => ['B'],
                'C' => ['C'],
            ],
            ['A', 'B', 'C'],
        );
        $fixture['playlists']['A']->backend_options = [StationPlaylist::OPTION_MERGE];

        try {
            $event = $this->calculateNextSong($fixture['station']);

            self::assertSame(['A1', 'B', 'C'], $this->getTitles($event->getNextSongs()));

            $nextEvent = $this->calculateNextSong(
                $fixture['station'],
                CarbonImmutable::parse('2026-08-09 10:03:00', 'UTC'),
            );
            self::assertSame(['A2', 'B', 'C'], $this->getTitles($nextEvent->getNextSongs()));
        } finally {
            $this->harness->cleanUp();
        }
    }

    public function testRepeatedPlaylistOccurrenceControlsFrequency(): void
    {
        $fixture = $this->harness->createSequentialGroup(
            [
                'Jingle' => ['Jingle'],
                'Hot' => ['Hot'],
                'Music' => ['Music'],
            ],
            ['Jingle', 'Hot', 'Music', 'Hot'],
        );

        try {
            $event = $this->calculateNextSong($fixture['station']);

            self::assertSame(['Jingle', 'Hot', 'Music', 'Hot'], $this->getTitles($event->getNextSongs()));
        } finally {
            $this->harness->cleanUp();
        }
    }

    public function testGroupBlockIsQueuedAheadOfAnIndependentRootPlaylist(): void
    {
        $fixture = $this->harness->createSequentialGroup(
            [
                'A' => ['A'],
                'B' => ['B'],
                'C' => ['C'],
            ],
            ['A', 'B', 'C'],
        );
        $this->harness->addSequentialPlaylist($fixture['station'], 'Independent Root', ['Root']);

        $fixture['group']->type = PlaylistTypes::OncePerHour;
        $fixture['group']->play_per_hour_minute = 0;

        try {
            $groupEvent = $this->calculateNextSong($fixture['station']);
            self::assertSame(['A', 'B', 'C'], $this->getTitles($groupEvent->getNextSongs()));

            $rootEvent = $this->calculateNextSong(
                $fixture['station'],
                CarbonImmutable::parse('2026-08-09 10:03:00', 'UTC'),
            );
            self::assertSame(['Root'], $this->getTitles($rootEvent->getNextSongs()));
        } finally {
            $this->harness->cleanUp();
        }
    }

    private function calculateNextSong(
        Station $station,
        ?CarbonImmutable $time = null,
    ): BuildQueue {
        $time ??= CarbonImmutable::parse('2026-08-09 10:00:00', 'UTC');

        return $this->harness->calculateNextSong($station, $time);
    }

    /**
     * @param list<StationQueue> $queueEntries
     * @return list<string|null>
     */
    private function getTitles(array $queueEntries): array
    {
        return array_map(
            static fn(StationQueue $queueEntry): ?string => $queueEntry->media?->title,
            $queueEntries,
        );
    }
}
