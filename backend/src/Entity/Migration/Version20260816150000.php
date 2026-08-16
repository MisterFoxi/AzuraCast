<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260816150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add configurable sorting to Smart Blocks.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE station_playlists ADD smart_block_sort VARCHAR(10) DEFAULT 'random' NOT NULL"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_playlists DROP smart_block_sort');
    }
}
