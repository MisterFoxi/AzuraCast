<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260816130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist Group List provenance in song playback history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE song_history '
            . 'ADD COLUMN group_playlist_id INT DEFAULT NULL, '
            . 'ADD INDEX IDX_song_history_group_playlist (group_playlist_id), '
            . 'ADD CONSTRAINT FK_song_history_group_playlist '
            . 'FOREIGN KEY (group_playlist_id) REFERENCES station_playlists (id) ON DELETE SET NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE song_history '
            . 'DROP FOREIGN KEY FK_song_history_group_playlist, '
            . 'DROP INDEX IDX_song_history_group_playlist, '
            . 'DROP COLUMN group_playlist_id'
        );
    }
}
