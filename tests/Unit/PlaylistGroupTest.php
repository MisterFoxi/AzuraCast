<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
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

    public function testGroupRotatesOccurrencesSequentiallyAndWraps(): void
    {
        $fixture = $this->harness->createSequentialGroup(
            [
                'A' => ['A'],
                'B' => ['B'],
                'C' => ['C'],
            ],
            ['A', 'B', 'C'],
        );

        try {
            self::assertSame(
                ['A', 'B', 'C', 'A', 'B', 'C'],
                $this->selectTitles($fixture['station'], 6),
            );
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
            self::assertSame(
                ['Jingle', 'Hot', 'Music', 'Hot'],
                $this->selectTitles($fixture['station'], 4),
            );
        } finally {
            $this->harness->cleanUp();
        }
    }

    /** @return list<string|null> */
    private function selectTitles(Station $station, int $count): array
    {
        $titles = [];
        $time = CarbonImmutable::parse('2026-08-09 10:00:00', 'UTC');

        for ($i = 0; $i < $count; $i++) {
            $event = $this->harness->calculateNextSong($station, $time->addMinutes($i * 3));
            self::assertCount(1, $event->getNextSongs());
            $titles[] = $event->getNextSongs()[0]->media?->title;
        }

        return $titles;
    }
}
