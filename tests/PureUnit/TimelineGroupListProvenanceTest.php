<?php

declare(strict_types=1);

namespace PureUnit;

use PHPUnit\Framework\TestCase;

final class TimelineGroupListProvenanceTest extends TestCase
{
    public function testQueueGroupProvenanceIsCopiedToHistory(): void
    {
        $source = file_get_contents(__DIR__ . '/../../backend/src/Entity/SongHistory.php');

        self::assertIsString($source);
        self::assertStringContainsString('public ?StationPlaylist $group_playlist = null;', $source);
        self::assertStringContainsString('$sh->group_playlist = $queue->group_playlist;', $source);
    }

    public function testTimelineFiltersUsePersistedGroupPlaylist(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../backend/src/Controller/Api/Stations/HistoryAction.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString("leftJoin('sh.group_playlist', 'gp')", $source);
        self::assertStringContainsString("getQueryParam('playlist_id')", $source);
        self::assertStringContainsString("getQueryParam('group_list_id')", $source);
        self::assertStringContainsString("getQueryParam('via_group_list')", $source);
        self::assertStringNotContainsString('playlist_chain', $source);
    }

    public function testMigrationAddsRecoverableHistoryRelation(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../backend/src/Entity/Migration/Version20260816130000.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString('ADD COLUMN group_playlist_id', $source);
        self::assertStringContainsString('ON DELETE SET NULL', $source);
        self::assertStringContainsString('DROP COLUMN group_playlist_id', $source);
    }
}
