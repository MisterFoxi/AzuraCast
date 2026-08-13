<?php

declare(strict_types=1);

namespace App\Media;

use Doctrine\DBAL\Connection;

final readonly class MediaGenreOptions
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array<int, array{id: int, id3_id: ?int, name: string, is_custom: bool}>
     */
    public function getActive(int $storageLocationId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT id, id3_id, name, is_custom
            FROM media_genres mg
            WHERE mg.is_active = 1
              AND (
                  mg.is_custom = 0
                  OR EXISTS (
                      SELECT 1
                      FROM station_media sm
                      WHERE sm.genre_reference_id = mg.id
                        AND sm.storage_location_id = :storage_location_id
                  )
              )
            ORDER BY is_custom ASC, name ASC
            SQL,
            ['storage_location_id' => $storageLocationId]
        );

        return array_map(
            static fn(array $row): array => [
                'id' => (int)$row['id'],
                'id3_id' => null === $row['id3_id'] ? null : (int)$row['id3_id'],
                'name' => (string)$row['name'],
                'is_custom' => (bool)$row['is_custom'],
            ],
            $rows
        );
    }
}
