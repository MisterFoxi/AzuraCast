<?php

declare(strict_types=1);

namespace PureUnit;

use PHPUnit\Framework\TestCase;

final class WebStreamSeparationTest extends TestCase
{
    public function testPlaylistApiOffersServerSideSourceFiltering(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../backend/src/Controller/Api/Stations/PlaylistsController.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString("getQueryParam('source')", $source);
        self::assertStringContainsString('PlaylistSources::tryFrom($source)', $source);
        self::assertStringContainsString("andWhere('sp.source = :source')", $source);
    }

    public function testRemoteSourcesAreAbsentFromTheStandardPlaylistTab(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../frontend/components/Stations/Playlists.vue'
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            'playlist.source !== PlaylistSources.RemoteUrl',
            $source
        );
    }

    public function testDedicatedRouteAndTypedApiModelExist(): void
    {
        $routes = file_get_contents(__DIR__ . '/../../frontend/components/Stations/routes.ts');
        $types = file_get_contents(__DIR__ . '/../../frontend/entities/ApiInterfaces.ts');

        self::assertIsString($routes);
        self::assertIsString($types);
        self::assertStringContainsString("name: 'stations:web_streams:index'", $routes);
        self::assertStringContainsString('export type ApiWebStream', $types);
        self::assertStringNotContainsString('export type ApiWebStream = any', $types);
    }
}
