<?php

declare(strict_types=1);

namespace Unit;

use App\Radio\AutoDJ\QueueBuilder;
use App\Tests\AutoDjTestHarness;
use App\Tests\Module;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;

final class AutoDjTestHarnessTest extends Unit
{
    private AutoDjTestHarness $harness;

    protected function _inject(Module $testsModule): void
    {
        $this->harness = new AutoDjTestHarness(
            $testsModule->em,
            $testsModule->container->get(QueueBuilder::class),
        );
    }

    public function testSequentialPlaylistSelectsTracksInOrder(): void
    {
        $fixture = $this->harness->createSequentialPlaylist(['A', 'B']);

        try {
            $firstEvent = $this->harness->calculateNextSong(
                $fixture['station'],
                CarbonImmutable::parse('2026-08-09 10:00:00', 'UTC'),
            );
            $secondEvent = $this->harness->calculateNextSong(
                $fixture['station'],
                CarbonImmutable::parse('2026-08-09 10:03:00', 'UTC'),
            );

            self::assertCount(1, $firstEvent->getNextSongs());
            self::assertSame('A', $firstEvent->getNextSongs()[0]->media?->title);

            self::assertCount(1, $secondEvent->getNextSongs());
            self::assertSame('B', $secondEvent->getNextSongs()[0]->media?->title);
        } finally {
            $this->harness->cleanUp();
        }
    }
}
