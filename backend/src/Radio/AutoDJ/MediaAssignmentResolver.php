<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\SmartBlockType;
use App\Entity\Repository\StationPlaylistRepository;
use App\Entity\Repository\StationPlaylistSmartBlockCriteriaRepository;
use App\Entity\Station;
use Doctrine\ORM\QueryBuilder;

/**
 * Resolves whether station media is reachable by an active AutoDJ source.
 *
 * Keep this read-only: callers such as Media -> Unassigned Files must never
 * synchronize Smart Blocks, advance playlist cursors or mutate queue/history.
 */
final class MediaAssignmentResolver
{
    public function __construct(
        private readonly StationPlaylistRepository $playlistRepository,
        private readonly StationPlaylistSmartBlockCriteriaRepository $smartBlockCriteriaRepository
    ) {
    }

    /**
     * Restrict a StationMedia query (alias "sm") to media that cannot be
     * reached through any active song source of the station.
     */
    public function applyUnassignedFilter(QueryBuilder $queryBuilder, Station $station): void
    {
        // Direct playlist membership. Keep this station-scoped even though a
        // StationMedia ID is globally unique, so the rule remains explicit.
        $queryBuilder->andWhere(
            <<<'DQL'
                NOT EXISTS (
                    SELECT spmAssigned.id
                    FROM App\Entity\StationPlaylistMedia spmAssigned
                    JOIN spmAssigned.playlist spAssigned
                    WHERE spmAssigned.media = sm
                    AND spAssigned.station = :assignmentStation
                    AND spAssigned.is_enabled = true
                    AND spAssigned.source = :assignmentSongSource
                )
            DQL
        );

        // Folder assignments are logical memberships: a media item is covered
        // by a folder when its path is the folder path or one of its children.
        $queryBuilder->andWhere(
            <<<'DQL'
                NOT EXISTS (
                    SELECT spfAssigned.id
                    FROM App\Entity\StationPlaylistFolder spfAssigned
                    JOIN spfAssigned.playlist spFolderPlaylist
                    WHERE spfAssigned.station = :assignmentStation
                    AND spFolderPlaylist.is_enabled = true
                    AND spFolderPlaylist.source = :assignmentSongSource
                    AND (
                        sm.path = spfAssigned.path
                        OR sm.path LIKE CONCAT(spfAssigned.path, '/%')
                    )
                )
            DQL
        )
            ->setParameter('assignmentStation', $station)
            ->setParameter('assignmentSongSource', PlaylistSources::Songs->value);

        // Dynamic Smart Blocks are intentionally not synchronized here. Resolve
        // their criteria read-only, and ignore playback limit/sort so every
        // media item that can be selected on a future build counts as assigned.
        $smartBlockMediaIds = $this->getDynamicSmartBlockMediaIds($station);
        if ([] !== $smartBlockMediaIds) {
            $queryBuilder
                ->andWhere('sm.id NOT IN (:dynamicSmartBlockMediaIds)')
                ->setParameter('dynamicSmartBlockMediaIds', $smartBlockMediaIds);
        }
    }

    /** @return list<int> */
    private function getDynamicSmartBlockMediaIds(Station $station): array
    {
        /** @var array<int, true> $assigned */
        $assigned = [];

        foreach ($this->playlistRepository->getAllForStation($station) as $playlist) {
            if (
                !$playlist->is_enabled
                || !$playlist->is_smart_block
                || PlaylistSources::Songs !== $playlist->source
                || SmartBlockType::Dynamic !== $playlist->smart_block_type
            ) {
                continue;
            }

            foreach ($this->smartBlockCriteriaRepository->getPotentialMatchingMediaIds($playlist) as $mediaId) {
                $assigned[$mediaId] = true;
            }
        }

        return array_keys($assigned);
    }
}
