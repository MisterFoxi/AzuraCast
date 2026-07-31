<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistGroup;
use App\Utilities\Time;
use Carbon\CarbonImmutable;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;

/**
 * @extends AbstractStationBasedRepository<StationPlaylist>
 */
final class StationPlaylistRepository extends AbstractStationBasedRepository
{
    protected string $entityClass = StationPlaylist::class;

    /**
     * @return StationPlaylist[]
     */
    public function getAllForStation(Station $station): array
    {
        return $this->repository->findBy([
            'station' => $station,
        ]);
    }

    public function stationHasActivePlaylists(Station $station): bool
    {
        foreach ($station->playlists as $playlist) {
            if (!$playlist->is_enabled) {
                continue;
            }

            if (PlaylistSources::RemoteUrl === $playlist->source) {
                return true;
            }

            $mediaCount = $this->em->createQuery(
                <<<DQL
                    SELECT COUNT(spm.id) FROM App\Entity\StationPlaylistMedia spm
                    JOIN spm.playlist sp
                    WHERE sp.station = :station
                DQL
            )->setParameter('station', $station)
                ->getSingleScalarResult();

            if ($mediaCount > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the currently "queued" (not-yet-played-this-cycle) members of a Playlist Group,
     * in the order they should be attempted, mirroring how StationPlaylistMediaRepository
     * hands back a song queue for a normal Songs playlist.
     *
     * @param StationPlaylist $playlist A playlist with source = playlists (a Playlist Group)
     *
     * @return StationPlaylistGroup[]
     */
    public function getPlaylistGroupQueue(StationPlaylist $playlist): array
    {
        if (PlaylistSources::Playlists !== $playlist->source) {
            throw new InvalidArgumentException('Playlist must contain playlists.');
        }

        $queuedPlaylistQuery = $this->em->createQueryBuilder()
            ->select('spg')
            ->from(StationPlaylistGroup::class, 'spg')
            ->join('spg.playlist', 'memberPlaylist')
            ->where('spg.playlist_group = :playlistGroup')
            ->andWhere('memberPlaylist.is_enabled = 1')
            ->setParameter('playlistGroup', $playlist);

        if (PlaylistOrders::Random === $playlist->order) {
            $queuedPlaylistQuery = $queuedPlaylistQuery->orderBy('RAND()');
        } else {
            $queuedPlaylistQuery = $queuedPlaylistQuery->andWhere('spg.is_queued = 1')
                ->orderBy('spg.weight', 'ASC');
        }

        return $queuedPlaylistQuery->getQuery()->execute();
    }

    public function isPlaylistGroupQueueCompletelyFilled(StationPlaylist $playlist): bool
    {
        if (PlaylistSources::Playlists !== $playlist->source) {
            throw new InvalidArgumentException('Playlist must contain playlists.');
        }

        if (PlaylistOrders::Random === $playlist->order) {
            return true;
        }

        $notQueuedPlaylistCount = $this->getCountPlaylistGroupBaseQuery($playlist)
            ->andWhere('spg.is_queued = 0')
            ->getQuery()
            ->getSingleScalarResult();

        return 0 === (int)$notQueuedPlaylistCount;
    }

    public function isPlaylistGroupQueueEmpty(StationPlaylist $playlist): bool
    {
        if (PlaylistSources::Playlists !== $playlist->source) {
            return false;
        }

        if (PlaylistOrders::Random === $playlist->order) {
            return false;
        }

        $totalPlaylistCount = (int)$this->getCountPlaylistGroupBaseQuery($playlist)
            ->getQuery()
            ->getSingleScalarResult();

        $notQueuedPlaylistCount = (int)$this->getCountPlaylistGroupBaseQuery($playlist)
            ->andWhere('spg.is_queued = 0')
            ->getQuery()
            ->getSingleScalarResult();

        return $notQueuedPlaylistCount === $totalPlaylistCount;
    }

    private function getCountPlaylistGroupBaseQuery(StationPlaylist $playlist): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('count(spg.id)')
            ->from(StationPlaylist::class, 'sp')
            ->join('sp.playlistGroupMemberships', 'spg')
            ->where('spg.playlist_group = :playlistGroup')
            ->andWhere('sp.is_enabled = 1')
            ->setParameter('playlistGroup', $playlist);
    }

    /**
     * Reset (re-queue) all members of a Playlist Group, e.g. once every member has had a turn.
     *
     * @param StationPlaylist $playlist A playlist with source = playlists (a Playlist Group)
     */
    public function resetPlaylistGroupQueue(
        StationPlaylist $playlist,
        ?CarbonImmutable $now = null
    ): void {
        if (PlaylistSources::Playlists !== $playlist->source) {
            throw new InvalidArgumentException('Playlist must contain playlists.');
        }

        if (PlaylistOrders::Sequential === $playlist->order) {
            $this->em->createQuery(
                <<<'DQL'
                    UPDATE App\Entity\StationPlaylistGroup spg
                    SET spg.is_queued = 1, spg.consecutive_plays_count = 0
                    WHERE spg.playlist_group = :playlistGroup
                DQL
            )->setParameter('playlistGroup', $playlist)
                ->execute();
        } elseif (PlaylistOrders::Shuffle === $playlist->order || PlaylistOrders::SmartShuffle === $playlist->order) {
            $this->em->wrapInTransaction(
                function () use ($playlist): void {
                    $allSpgRecordsQuery = $this->em->createQuery(
                        <<<'DQL'
                            SELECT spg.id
                            FROM App\Entity\StationPlaylistGroup spg
                            WHERE spg.playlist_group = :playlistGroup
                            ORDER BY RAND()
                        DQL
                    )->setParameter('playlistGroup', $playlist);

                    $updateSpgWeightQuery = $this->em->createQuery(
                        <<<'DQL'
                            UPDATE App\Entity\StationPlaylistGroup spg
                            SET spg.weight = :weight, spg.is_queued = 1, spg.consecutive_plays_count = 0
                            WHERE spg.id = :id
                        DQL
                    );

                    $allSpgRecords = $allSpgRecordsQuery->toIterable([], $allSpgRecordsQuery::HYDRATE_SCALAR);
                    $weight = 1;

                    foreach ($allSpgRecords as $spgId) {
                        $updateSpgWeightQuery->setParameter('id', $spgId)
                            ->setParameter('weight', $weight)
                            ->execute();

                        $weight++;
                    }
                }
            );
        }

        $now ??= Time::nowUtc();

        $playlist->queue_reset_at = $now;
        $this->em->persist($playlist);
        $this->em->flush();
    }
}
