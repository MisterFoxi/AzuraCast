<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use App\Media\Id3GenreCatalog;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260813070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the canonical ID3 genre catalog, aliases and station media reference.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
            CREATE TABLE media_genres (
                id INT AUTO_INCREMENT NOT NULL,
                id3_id SMALLINT DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                normalized_name VARCHAR(255) NOT NULL,
                is_active TINYINT(1) DEFAULT 1 NOT NULL,
                is_custom TINYINT(1) DEFAULT 0 NOT NULL,
                UNIQUE INDEX UNIQ_media_genres_id3_id (id3_id),
                UNIQUE INDEX UNIQ_media_genres_normalized_name (normalized_name),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL
        );

        $this->addSql(
            <<<'SQL'
            CREATE TABLE media_genre_aliases (
                id INT AUTO_INCREMENT NOT NULL,
                genre_id INT NOT NULL,
                alias VARCHAR(255) NOT NULL,
                normalized_alias VARCHAR(255) NOT NULL,
                INDEX IDX_media_genre_aliases_genre_id (genre_id),
                UNIQUE INDEX UNIQ_media_genre_aliases_normalized_alias (normalized_alias),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL
        );
        $this->addSql(
            'ALTER TABLE media_genre_aliases ADD CONSTRAINT FK_media_genre_aliases_genre_id '
            . 'FOREIGN KEY (genre_id) REFERENCES media_genres (id) ON DELETE CASCADE'
        );

        $this->addSql('ALTER TABLE station_media ADD genre_reference_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_station_media_genre_reference_id ON station_media (genre_reference_id)');
        $this->addSql(
            'ALTER TABLE station_media ADD CONSTRAINT FK_station_media_genre_reference_id '
            . 'FOREIGN KEY (genre_reference_id) REFERENCES media_genres (id) ON DELETE SET NULL'
        );

        foreach (Id3GenreCatalog::GENRES as $id3Id => $name) {
            $this->addSql(
                <<<'SQL'
                INSERT INTO media_genres (id3_id, name, normalized_name, is_active, is_custom)
                VALUES (:id3_id, :name, :normalized_name, 1, 0)
                SQL,
                [
                    'id3_id' => $id3Id,
                    'name' => $name,
                    'normalized_name' => Id3GenreCatalog::key($name),
                ]
            );
        }

        foreach (Id3GenreCatalog::ALIASES as $alias => $canonicalName) {
            $this->addSql(
                <<<'SQL'
                INSERT INTO media_genre_aliases (genre_id, alias, normalized_alias)
                SELECT id, :alias, :normalized_alias
                FROM media_genres
                WHERE normalized_name = :canonical_name
                SQL,
                [
                    'alias' => $alias,
                    'normalized_alias' => Id3GenreCatalog::key($alias),
                    'canonical_name' => Id3GenreCatalog::key($canonicalName),
                ]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_media DROP FOREIGN KEY FK_station_media_genre_reference_id'
        );
        $this->addSql('DROP INDEX IDX_station_media_genre_reference_id ON station_media');
        $this->addSql('ALTER TABLE station_media DROP genre_reference_id');
        $this->addSql('DROP TABLE media_genre_aliases');
        $this->addSql('DROP TABLE media_genres');
    }
}
