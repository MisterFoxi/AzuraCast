<?php

declare(strict_types=1);

namespace PureUnit;

use App\Entity\Repository\StationScheduleRepository;
use App\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class StationScheduleRecurrenceValidationTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function invalidDateRanges(): iterable
    {
        yield 'same date' => ['2026-08-16', '2026-08-16'];
        yield 'end before start' => ['2026-08-17', '2026-08-16'];
    }

    #[DataProvider('invalidDateRanges')]
    public function testRecurringEndDateMustBeAfterStartDate(string $startDate, string $endDate): void
    {
        $repositoryReflection = new ReflectionClass(StationScheduleRepository::class);
        $repository = $repositoryReflection->newInstanceWithoutConstructor();
        $method = $repositoryReflection->getMethod('validateRecurrenceItem');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('End date must be after start date');

        $method->invoke($repository, [
            'recurrence_type' => 'weekly',
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }

    public function testRecurringEndDateAfterStartDateIsAccepted(): void
    {
        $repositoryReflection = new ReflectionClass(StationScheduleRepository::class);
        $repository = $repositoryReflection->newInstanceWithoutConstructor();
        $method = $repositoryReflection->getMethod('validateRecurrenceItem');

        $method->invoke($repository, [
            'recurrence_type' => 'weekly',
            'start_date' => '2026-08-16',
            'end_date' => '2026-08-17',
        ]);

        self::assertTrue(true);
    }
}
