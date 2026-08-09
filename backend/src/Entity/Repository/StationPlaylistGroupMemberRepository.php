<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Doctrine\Repository;
use App\Entity\Station;
use App\Entity\StationPlaylistGroupMember;

/**
 * @extends Repository<StationPlaylistGroupMember>
 */
final class StationPlaylistGroupMemberRepository extends Repository
{
    protected string $entityClass = StationPlaylistGroupMember::class;

    /** @return list<int> */
    public function getChildPlaylistIds(Station $station): array
    {
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT DISTINCT IDENTITY(spgm.playlist) AS playlist_id
                FROM App\Entity\StationPlaylistGroupMember spgm
                JOIN spgm.group parentPlaylist
                WHERE parentPlaylist.station = :station
            DQL
        )->setParameter('station', $station)
            ->getScalarResult();

        return array_map(
            static fn(array $row): int => (int)$row['playlist_id'],
            $rows
        );
    }
}
