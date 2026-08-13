<?php

declare(strict_types=1);

namespace App\Media;

use App\Entity\MediaGenre;
use App\Entity\StationMedia;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MediaGenreResolver
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager
    ) {
    }

    public function normalizeAndResolve(StationMedia $media): void
    {
        $media->normalizeMetadataFields();
        $media->genre_reference = $this->resolve($media->genre);
        $media->updateMetaFields();
    }

    public function resolve(?string $rawGenre): ?MediaGenre
    {
        $genre = Id3GenreCatalog::normalize($rawGenre);
        if (null === $genre) {
            return null;
        }

        $key = Id3GenreCatalog::key($genre);
        $id = $this->findActiveGenreId($key);

        if (null === $id) {
            $this->connection->executeStatement(
                <<<'SQL'
                INSERT INTO media_genres (id3_id, name, normalized_name, is_active, is_custom)
                VALUES (NULL, :name, :normalized_name, 1, 1)
                ON DUPLICATE KEY UPDATE id = id
                SQL,
                [
                    'name' => $genre,
                    'normalized_name' => $key,
                ]
            );

            $id = $this->findActiveGenreId($key);
        }

        if (null === $id) {
            return null;
        }

        return $this->entityManager->find(MediaGenre::class, $id);
    }

    private function findActiveGenreId(string $key): ?int
    {
        $id = $this->connection->fetchOne(
            <<<'SQL'
            SELECT mg.id
            FROM media_genres mg
            LEFT JOIN media_genre_aliases mga ON mga.genre_id = mg.id
            WHERE mg.is_active = 1
              AND (mg.normalized_name = :canonical_name OR mga.normalized_alias = :alias_name)
            ORDER BY CASE WHEN mg.normalized_name = :order_name THEN 0 ELSE 1 END
            LIMIT 1
            SQL,
            [
                'canonical_name' => $key,
                'alias_name' => $key,
                'order_name' => $key,
            ]
        );

        return false === $id ? null : (int)$id;
    }
}
