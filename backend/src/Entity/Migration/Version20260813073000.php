<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use App\Entity\Song;
use App\Media\Id3GenreCatalog;
use App\Media\MetadataTextNormalizer;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260813073000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize existing media metadata and link genres to the reference catalog.';
    }

    public function up(Schema $schema): void
    {
        // The backfill below reads these columns during up(). Execute the DDL immediately;
        // addSql() would defer it until after this method returns.
        $this->connection->executeStatement(
            'ALTER TABLE station_media ADD COLUMN IF NOT EXISTS original_genre VARCHAR(255) DEFAULT NULL'
        );
        $this->connection->executeStatement(
            'ALTER TABLE station_media ADD COLUMN IF NOT EXISTS original_artist VARCHAR(255) DEFAULT NULL'
        );
        $this->connection->executeStatement(
            'ALTER TABLE station_media ADD COLUMN IF NOT EXISTS original_title VARCHAR(255) DEFAULT NULL'
        );

        $canonicalIds = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, normalized_name FROM media_genres') as $row) {
            $canonicalIds[$row['normalized_name']] = (int)$row['id'];
        }

        $aliasIds = [];
        foreach (
            $this->connection->fetchAllAssociative(
                'SELECT mga.normalized_alias, mga.genre_id '
                . 'FROM media_genre_aliases mga INNER JOIN media_genres mg ON mg.id = mga.genre_id '
                . 'WHERE mg.is_active = 1'
            ) as $row
        ) {
            $aliasIds[$row['normalized_alias']] = (int)$row['genre_id'];
        }

        $lastId = 0;
        while (true) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, artist, album, title, genre, original_artist, original_title, original_genre '
                . 'FROM station_media WHERE id > :last_id ORDER BY id ASC LIMIT 500',
                ['last_id' => $lastId]
            );

            if ([] === $rows) {
                break;
            }

            foreach ($rows as $row) {
                $this->normalizeMediaRow($row, $canonicalIds, $aliasIds);
                $lastId = (int)$row['id'];
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $canonicalIds
     * @param array<string, int> $aliasIds
     */
    private function normalizeMediaRow(array $row, array &$canonicalIds, array $aliasIds): void
    {
        $artist = MetadataTextNormalizer::normalize($row['artist']);
        $title = MetadataTextNormalizer::normalize($row['title']);
        $genre = Id3GenreCatalog::normalize($row['genre']);

        $originalArtist = $row['original_artist'];
        if ($row['artist'] !== $artist && null === $originalArtist) {
            $originalArtist = $row['artist'];
        }

        $originalTitle = $row['original_title'];
        if ($row['title'] !== $title && null === $originalTitle) {
            $originalTitle = $row['title'];
        }

        $originalGenre = $row['original_genre'];
        if ($row['genre'] !== $genre && null === $originalGenre) {
            $originalGenre = $row['genre'];
        }

        $genreId = null;
        if (null !== $genre) {
            $key = Id3GenreCatalog::key($genre);
            $genreId = $canonicalIds[$key] ?? $aliasIds[$key] ?? null;

            if (null === $genreId) {
                $this->connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO media_genres (id3_id, name, normalized_name, is_active, is_custom)
                    VALUES (NULL, :name, :normalized_name, 1, 1)
                    ON DUPLICATE KEY UPDATE id = id
                    SQL,
                    ['name' => $genre, 'normalized_name' => $key]
                );

                $genreId = (int)$this->connection->fetchOne(
                    'SELECT id FROM media_genres WHERE normalized_name = :normalized_name',
                    ['normalized_name' => $key]
                );
                $canonicalIds[$key] = $genreId;
            }
        }

        $textParts = array_filter([
            trim($artist ?? ''),
            trim($row['album'] ?? ''),
            trim($title ?? ''),
        ]);
        $text = implode(' - ', $textParts);
        $songId = '' !== $text ? Song::getSongHash($text) : Song::OFFLINE_SONG_ID;

        $this->connection->executeStatement(
            <<<'SQL'
            UPDATE station_media
            SET artist = :artist,
                title = :title,
                genre = :genre,
                genre_reference_id = :genre_reference_id,
                original_artist = :original_artist,
                original_title = :original_title,
                original_genre = :original_genre,
                text = :text,
                song_id = :song_id
            WHERE id = :id
            SQL,
            [
                'artist' => $artist,
                'title' => $title,
                'genre' => $genre,
                'genre_reference_id' => $genreId,
                'original_artist' => $originalArtist,
                'original_title' => $originalTitle,
                'original_genre' => $originalGenre,
                'text' => $text,
                'song_id' => $songId,
                'id' => $row['id'],
            ]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_media DROP original_genre');
        $this->addSql('ALTER TABLE station_media DROP original_artist');
        $this->addSql('ALTER TABLE station_media DROP original_title');
    }
}
