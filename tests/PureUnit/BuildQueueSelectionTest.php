<?php

declare(strict_types=1);

namespace PureUnit;

use App\Entity\Song;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use PHPUnit\Framework\TestCase;

final class BuildQueueSelectionTest extends TestCase
{
    public function testRejectedCandidateIsRecordedAndCannotBeSelectedAgain(): void
    {
        $station = new Station();
        $candidate = new StationQueue($station, Song::createFromText('Candidate A'));
        $event = new BuildQueue($station);

        self::assertTrue($event->setNextSongs($candidate));
        self::assertTrue($event->rejectSelection('validator refused candidate'));
        self::assertTrue($event->wasRejected());
        self::assertSame('validator refused candidate', $event->getRejectionReason());
        self::assertSame([$candidate->song_id], $event->getRejectedSongIds());
        self::assertSame([], $event->getNextSongs());
        self::assertFalse($event->setNextSongs($candidate));
    }

    public function testExcludedCandidateIsRefusedByNextAttempt(): void
    {
        $station = new Station();
        $candidate = new StationQueue($station, Song::createFromText('Candidate A'));
        $replacement = new StationQueue($station, Song::createFromText('Candidate B'));
        $event = new BuildQueue($station, excludedSongIds: [$candidate->song_id]);

        self::assertFalse($event->setNextSongs($candidate));
        self::assertTrue($event->setNextSongs($replacement));
        self::assertSame([$replacement], $event->getNextSongs());
    }

    public function testDeferredSideEffectRunsOnlyForAcceptedSelection(): void
    {
        $station = new Station();
        $candidate = new StationQueue($station, Song::createFromText('Candidate A'));
        $commits = 0;
        $event = new BuildQueue($station);

        $event->setNextSongs($candidate);
        $event->deferUntilAccepted(static function () use (&$commits): void {
            ++$commits;
        });
        $event->commitAcceptedSelection();
        $event->commitAcceptedSelection();

        self::assertSame(1, $commits);
    }
}
