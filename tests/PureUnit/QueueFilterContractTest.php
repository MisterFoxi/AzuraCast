<?php

declare(strict_types=1);

namespace PureUnit;

use PHPUnit\Framework\TestCase;

final class QueueFilterContractTest extends TestCase
{
    public function testQueueFiltersUseFoxDevGroupPlaylistRelation(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../backend/src/Controller/Api/Stations/QueueController.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString("getQueryParam('playlist_id')", $source);
        self::assertStringContainsString("getQueryParam('group_list_id')", $source);
        self::assertStringContainsString("getQueryParam('via_group_list')", $source);
        self::assertStringContainsString('sq.group_playlist IS NOT NULL', $source);
        self::assertStringNotContainsString('playlist_chain', $source);
    }

    public function testQueueResponseExposesTypedProvenanceIds(): void
    {
        $backendType = file_get_contents(
            __DIR__ . '/../../backend/src/Entity/Api/StationQueueDetailed.php'
        );
        $frontendType = file_get_contents(
            __DIR__ . '/../../frontend/entities/ApiInterfaces.ts'
        );

        self::assertIsString($backendType);
        self::assertIsString($frontendType);
        self::assertStringContainsString('public ?int $playlist_id = null;', $backendType);
        self::assertStringContainsString('public ?int $group_list_id = null;', $backendType);
        self::assertStringContainsString('playlist_id?: number | null;', $frontendType);
        self::assertStringContainsString('group_list_id?: number | null;', $frontendType);
    }
}
