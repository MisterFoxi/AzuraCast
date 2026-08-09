<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the Clock Wheels feature and its persisted data.';
    }

    public function up(Schema $schema): void
    {
        // Remove references from shared tables before dropping Clock Wheel tables.
        $this->addSql('ALTER TABLE station_schedules DROP FOREIGN KEY IF EXISTS FK_B3BFB2955D856AB6');
        $this->addSql(
            'ALTER TABLE station_schedules
                DROP COLUMN IF EXISTS clock_wheel_id,
                DROP COLUMN IF EXISTS clock_wheel_mode'
        );

        $this->addSql('ALTER TABLE station_queue DROP FOREIGN KEY IF EXISTS FK_station_queue_clock_wheel');
        $this->addSql(
            'ALTER TABLE station_queue
                DROP COLUMN IF EXISTS clock_wheel_id,
                DROP COLUMN IF EXISTS clock_wheel_max_play_seconds,
                DROP COLUMN IF EXISTS clock_wheel_schedule_mode,
                DROP COLUMN IF EXISTS clock_wheel_enforce_cap,
                DROP COLUMN IF EXISTS clock_wheel_stretch_ratio,
                DROP COLUMN IF EXISTS clock_wheel_legal_id_substitute'
        );

        $this->addSql('ALTER TABLE song_history DROP FOREIGN KEY IF EXISTS FK_song_history_clock_wheel');
        $this->addSql('ALTER TABLE song_history DROP COLUMN IF EXISTS clock_wheel_id');

        $this->addSql(
            'ALTER TABLE station_holiday_overrides DROP FOREIGN KEY IF EXISTS FK_holiday_clock_wheel'
        );
        $this->addSql('ALTER TABLE station_holiday_overrides DROP COLUMN IF EXISTS clock_wheel_id');

        $this->addSql('DROP TABLE IF EXISTS clock_wheel_events');
        $this->addSql('DROP TABLE IF EXISTS station_clock_wheel_events');
        $this->addSql('DROP TABLE IF EXISTS station_clock_wheel_slots');
        $this->addSql('DROP TABLE IF EXISTS station_clock_wheels');
        $this->addSql('DROP TABLE IF EXISTS station_clock_dayparts');
        $this->addSql('DROP TABLE IF EXISTS station_clock_wheel_template_slots');
        $this->addSql('DROP TABLE IF EXISTS station_clock_wheel_templates');
    }

}
