<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Radio\AutoDJ\Scheduler;
use App\Tests\Module;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;
use DateTimeZone;
use UnitTester;

class StationPlaylistTest extends Unit
{
    protected UnitTester $tester;

    protected Scheduler $scheduler;

    public function testGroupSourcePreservesAutoDjRotationType(): void
    {
        $playlist = new StationPlaylist(new Station());
        $playlist->type = PlaylistTypes::OncePerXSongs;

        $playlist->source = PlaylistSources::Group;

        self::assertSame(PlaylistTypes::OncePerXSongs, $playlist->type);
    }

    protected function _inject(Module $testsModule): void
    {
        $di = $testsModule->container;
        $this->scheduler = $di->get(Scheduler::class);
    }

    public function testScheduledPlaylist(): void
    {
        $station = $this->makeStation();

        $playlist = new StationPlaylist($station);
        $playlist->name = 'Test Playlist';

        // Sample playlist that plays from 10PM to 4AM the next day.
        $scheduleEntry = new StationSchedule($playlist);
        $scheduleEntry->start_time = 2200;
        $scheduleEntry->end_time = 400;
        $scheduleEntry->days = [1, 2, 3]; // Monday, Tuesday, Wednesday

        $playlist->schedule_items->add($scheduleEntry);

        $utc = new DateTimeZone('UTC');
        $testMonday = CarbonImmutable::create(2018, 1, 15, 0, 0, 0, $utc);
        $testThursday = CarbonImmutable::create(2018, 1, 18, 0, 0, 0, $utc);

        // Sanity check: Jan 15, 2018 is a Monday, and Jan 18, 2018 is a Thursday.
        self::assertTrue($testMonday->isMonday());
        self::assertTrue($testThursday->isThursday());

        // Playlist SHOULD play Monday evening at 10:30PM.
        $testTime = $testMonday->setTime(22, 30);
        self::assertTrue($this->scheduler->shouldPlaylistPlayNow($playlist, $testTime));

        // Playlist SHOULD play Thursday morning at 3:00AM.
        $testTime = $testThursday->setTime(3, 0);
        self::assertTrue($this->scheduler->shouldPlaylistPlayNow($playlist, $testTime));

        // Playlist SHOULD NOT play Monday morning at 3:00AM.
        $testTime = $testMonday->setTime(3, 0);
        self::assertFalse($this->scheduler->shouldPlaylistPlayNow($playlist, $testTime));

        // Playlist SHOULD NOT play Thursday evening at 10:30PM.
        $testTime = $testThursday->setTime(22, 30);
        self::assertFalse($this->scheduler->shouldPlaylistPlayNow($playlist, $testTime));
    }

    public function testOncePerXMinutesPlaylist()
    {
        $station = $this->makeStation();

        $playlist = new StationPlaylist($station);
        $playlist->name = 'Test Playlist';
        $playlist->type = PlaylistTypes::OncePerXMinutes;
        $playlist->play_per_minutes = 30;

        $utc = new DateTimeZone('UTC');
        $testDay = CarbonImmutable::create(2018, 1, 15, 0, 0, 0, $utc);

        // Last played 20 minutes ago, SHOULD NOT play again.
        $lastPlayed = $testDay->addMinutes(0 - 20);
        $playlist->played_at = $lastPlayed;

        self::assertFalse($this->scheduler->shouldPlaylistPlayNow($playlist, $testDay));

        // Last played 40 minutes ago, SHOULD play again.
        $lastPlayed = $testDay->addMinutes(0 - 40);
        $playlist->played_at = $lastPlayed;

        self::assertTrue($this->scheduler->shouldPlaylistPlayNow($playlist, $testDay));
    }

    public function testOncePerHourPlaylistTracksItsTargetOccurrence(): void
    {
        $station = $this->makeStation();

        $playlist = new StationPlaylist($station);
        $playlist->name = 'Test Playlist';
        $playlist->type = PlaylistTypes::OncePerHour;
        $playlist->play_per_hour_minute = 25;

        $utc = new DateTimeZone('UTC');
        $testDay = CarbonImmutable::create(2018, 1, 15, 0, 0, 0, $utc);

        // A new playlist waits for its first :25 occurrence.
        self::assertFalse($this->scheduler->shouldPlaylistPlayNow($playlist, $testDay->setTime(10, 20)));
        self::assertTrue($this->scheduler->shouldPlaylistPlayNow($playlist, $testDay->setTime(10, 25)));
        self::assertTrue($this->scheduler->shouldPlaylistPlayNow($playlist, $testDay->setTime(10, 42)));

        // Once queued, the same occurrence cannot be selected twice.
        $playlist->played_at = $testDay->setTime(10, 42);
        self::assertFalse($this->scheduler->shouldPlaylistPlayNow($playlist, $testDay->setTime(11, 24)));
        self::assertTrue($this->scheduler->shouldPlaylistPlayNow($playlist, $testDay->setTime(11, 25)));
    }

    public function testOncePerHourPlaylistTracksOccurrencesWithTopOfHourProtectionEnabled(): void
    {
        $station = $this->makeStation();
        $station->backend_config->top_of_hour_id_enabled = true;

        $playlist = new StationPlaylist($station);
        $playlist->name = 'Hourly Promo';
        $playlist->type = PlaylistTypes::OncePerHour;
        $playlist->play_per_hour_minute = 25;

        $utc = new DateTimeZone('UTC');
        $testDay = CarbonImmutable::create(2018, 1, 15, 0, 0, 0, $utc);

        $playlist->played_at = $testDay->setTime(11, 30);

        // TOPH only owns :00. The :25 occurrence stays due until queued.
        self::assertFalse($this->scheduler->shouldPlaylistPlayNow($playlist, $testDay->setTime(12, 24)));
        self::assertTrue($this->scheduler->shouldPlaylistPlayNow($playlist, $testDay->setTime(12, 25)));
        self::assertTrue($this->scheduler->shouldPlaylistPlayNow($playlist, $testDay->setTime(12, 42)));

        $playlist->played_at = $testDay->setTime(12, 42);
        self::assertFalse($this->scheduler->shouldPlaylistPlayNow($playlist, $testDay->setTime(13, 24)));
        self::assertTrue($this->scheduler->shouldPlaylistPlayNow($playlist, $testDay->setTime(13, 25)));
    }

    public function testOncePerHourAtTopOfHourSuppressedWhenProtectionEnabled(): void
    {
        $station = $this->makeStation();
        $station->backend_config->top_of_hour_id_enabled = true;

        $playlist = new StationPlaylist($station);
        $playlist->name = 'Legacy ID Playlist';
        $playlist->type = PlaylistTypes::OncePerHour;
        $playlist->play_per_hour_minute = 0;

        $utc = new DateTimeZone('UTC');
        $testDay = CarbonImmutable::create(2018, 1, 15, 10, 0, 0, $utc);

        // Would have matched fuzzy window at :07 with protection off; suppressed with protection on.
        self::assertFalse($this->scheduler->shouldPlaylistPlayNow($playlist, $testDay));
        self::assertFalse($this->scheduler->shouldPlaylistPlayNow($playlist, $testDay->setTime(10, 7)));
    }

    private function makeStation(): Station
    {
        $station = new Station();
        $station->name = 'Playlist Test Station';
        $station->short_name = 'playlist_test';
        $station->timezone = 'UTC';

        return $station;
    }
}
