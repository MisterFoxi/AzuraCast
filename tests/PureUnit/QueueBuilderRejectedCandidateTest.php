<?php

declare(strict_types=1);

namespace PureUnit;

use PHPUnit\Framework\TestCase;

final class QueueBuilderRejectedCandidateTest extends TestCase
{
    public function testRejectedCandidatesAreFilteredBeforeRotationStateAdvances(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../backend/src/Radio/AutoDJ/QueueBuilder.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString('filterQueueByRejectedCandidates', $source);
        self::assertStringContainsString('isSongExcluded($media->song_id)', $source);

        $queueCreation = strpos($source, '$stationQueueEntry = StationQueue::fromMedia');
        $exclusionCheck = strpos(
            $source,
            '$this->currentBuildEvent?->isSongExcluded($stationQueueEntry->song_id)',
            $queueCreation
        );
        $cursorAdvance = strpos($source, '$spm->played(', $queueCreation);

        self::assertNotFalse($queueCreation);
        self::assertNotFalse($exclusionCheck);
        self::assertNotFalse($cursorAdvance);
        self::assertGreaterThan($exclusionCheck, $cursorAdvance);
    }

    public function testRemotePlaylistCacheAdvanceIsDeferredUntilAcceptance(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../backend/src/Radio/AutoDJ/QueueBuilder.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString('deferUntilAccepted(', $source);
        self::assertStringContainsString(
            'fn () => $this->cache->set($queueCacheKey, $mediaQueue, 6000)',
            $source
        );
    }

    public function testRequestIsAcceptedBeforeItsPlayedAtStateIsChanged(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../backend/src/Radio/AutoDJ/QueueBuilder.php'
        );

        self::assertIsString($source);
        $requestQueue = strrpos($source, '$stationQueueEntry = StationQueue::fromRequest($request);');
        $acceptance = strpos($source, 'if (!$event->setNextSongs($stationQueueEntry))', $requestQueue);
        $playedAt = strpos($source, '$request->played_at = $expectedPlayTime;', $requestQueue);

        self::assertNotFalse($requestQueue);
        self::assertNotFalse($acceptance);
        self::assertNotFalse($playedAt);
        self::assertGreaterThan($acceptance, $playedAt);
    }
}
