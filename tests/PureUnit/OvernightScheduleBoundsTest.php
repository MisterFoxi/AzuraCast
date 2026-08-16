<?php

declare(strict_types=1);

namespace PureUnit;

use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\Backend\Liquidsoap\ConfigWriter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class OvernightScheduleBoundsTest extends TestCase
{
    public function testOneTimeOvernightScheduleIsBoundedToItsDate(): void
    {
        $station = new Station();
        $station->timezone = 'UTC';

        $playlist = new StationPlaylist($station);
        $schedule = new StationSchedule($playlist);
        $schedule->start_time = 2300;
        $schedule->end_time = 100;
        $schedule->start_date = '2026-08-16';
        $schedule->end_date = '2026-08-16';
        (new ReflectionProperty($schedule, 'id'))->setValue($schedule, 42);

        $event = new WriteLiquidsoapConfiguration($station);
        $writerReflection = new ReflectionClass(ConfigWriter::class);
        $writer = $writerReflection->newInstanceWithoutConstructor();
        $method = $writerReflection->getMethod('getScheduledPlaylistPlayTime');

        $predicate = $method->invoke($writer, $event, $schedule);

        self::assertIsString($predicate);
        self::assertStringStartsWith('schedule_42_date_range() and ', $predicate);
        self::assertStringContainsString('23h0m-23h59m59s', $predicate);
        self::assertStringContainsString('00h00m-1h0m', $predicate);
        self::assertStringContainsString('def schedule_42_date_range() =', $event->buildConfiguration());
    }
}
