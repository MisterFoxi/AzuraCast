<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enums\PlaylistGroupAllowedRequests;
use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Interfaces\IdentifiableEntityInterface;
use Doctrine\ORM\Mapping as ORM;
use JsonSerializable;
use OpenApi\Attributes as OA;

/**
 * Membership record linking a "member" playlist to a "Playlist Group" (clock wheel) playlist.
 *
 * This is purely additive to the existing rotation model: a Playlist Group is itself just a
 * StationPlaylist with `source = playlists`. Its actual audio content is resolved at AutoDJ time
 * by picking one of its StationPlaylistGroup members and delegating to that member's own normal
 * selection logic (Songs playlist, nested Playlist Group, Remote URL, or Request Queue).
 */
#[
    OA\Schema(
        type: "object",
        properties: [
            new OA\Property(
                property: 'name',
                description: 'The member playlist name.',
                type: 'string',
                example: 'My Playlist',
                readOnly: true
            ),
        ]
    ),
    ORM\Entity,
    ORM\Table(name: 'station_playlist_group'),
    Attributes\Auditable
]
final class StationPlaylistGroup implements JsonSerializable, IdentifiableEntityInterface
{
    use Traits\HasAutoIncrementId;

    #[ORM\ManyToOne(fetch: 'EAGER', inversedBy: 'playlistGroupMemberships')]
    #[ORM\JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public StationPlaylist $playlist;

    public int $playlist_id {
        get => $this->playlist->id;
    }

    #[ORM\ManyToOne(fetch: 'EAGER', inversedBy: 'playlists')]
    #[ORM\JoinColumn(name: 'playlist_group_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public StationPlaylist $playlist_group;

    public int $playlist_group_id {
        get => $this->playlist_group->id;
    }

    #[
        OA\Property(example: 1),
        ORM\Column
    ]
    public int $weight = 0;

    #[ORM\Column]
    public bool $is_queued = true;

    #[ORM\Column]
    public int $last_played = 0;

    /**
     * How many times in a row this member should play before rotating to the next member.
     * 0 = play once, then advance.
     */
    #[
        OA\Property(example: 0),
        ORM\Column
    ]
    public int $consecutive_plays = 0;

    #[ORM\Column]
    public int $consecutive_plays_count = 0;

    /**
     * If true (and the member is a Sequential/Shuffle Songs playlist), the member's entire
     * song queue must be exhausted before rotating to the next group member, rather than
     * rotating after a single song.
     */
    #[
        OA\Property(example: false),
        ORM\Column
    ]
    public bool $play_full_cycle = false {
        set (bool $value) {
            $this->play_full_cycle = $value
                && PlaylistSources::Songs === $this->playlist->source
                && in_array(
                    $this->playlist->order,
                    [PlaylistOrders::Sequential, PlaylistOrders::Shuffle],
                    true
                );

            if ($this->play_full_cycle) {
                $this->consecutive_plays = 0;
            }
        }
    }

    #[
        OA\Property(example: 'any'),
        ORM\Column(type: 'string', enumType: PlaylistGroupAllowedRequests::class)
    ]
    public PlaylistGroupAllowedRequests $allowed_requests = PlaylistGroupAllowedRequests::Any;

    public function __construct(
        StationPlaylist $playlist,
        StationPlaylist $playlistGroup
    ) {
        $this->playlist = $playlist;
        $this->playlist_group = $playlistGroup;
    }

    /**
     * Record a play of this member and determine whether the group should rotate to the
     * next member afterward.
     *
     * @param ?int $timestamp
     * @param bool $forceAdvance Always rotate to the next member regardless of consecutive_plays.
     * @param bool $keepQueued Keep this member "queued" (used for play_full_cycle members whose
     *                         song queue has not yet been fully exhausted).
     *
     * @return bool True if the member's "turn" is now over and it should leave the active queue.
     */
    public function played(
        ?int $timestamp = null,
        bool $forceAdvance = false,
        bool $keepQueued = false
    ): bool {
        $this->last_played = $timestamp ?? time();

        if ($keepQueued && !$forceAdvance) {
            return false;
        }

        if ($this->consecutive_plays > 0) {
            $this->consecutive_plays_count++;
        }

        if (
            !$forceAdvance
            && $this->consecutive_plays > 0
            && $this->consecutive_plays_count < $this->consecutive_plays
        ) {
            return false;
        }

        $this->is_queued = false;
        $this->consecutive_plays_count = 0;
        return true;
    }

    /**
     * @inheritDoc
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->playlist->id,
            'name' => $this->playlist->name,
            'weight' => $this->weight,
            'consecutive_plays' => $this->consecutive_plays,
            'play_full_cycle' => $this->play_full_cycle,
            'allowed_requests' => $this->allowed_requests->value,
        ];
    }

    public function __clone()
    {
        $this->last_played = 0;
        $this->is_queued = false;
        $this->consecutive_plays_count = 0;
    }
}
