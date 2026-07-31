<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'string')]
enum PlaylistSources: string
{
    case Songs = 'songs';
    case RemoteUrl = 'remote_url';

    /**
     * A "Playlist Group" -- this playlist's content is a weighted/sequential/shuffled
     * chain of *other* playlists (Clock Wheel-style grouping), rather than songs of its own.
     */
    case Playlists = 'playlists';

    /**
     * This playlist's content is pulled live from the station's Request Queue when its
     * rotation slot comes up, rather than from its own media library.
     */
    case Requests = 'requests';
}
