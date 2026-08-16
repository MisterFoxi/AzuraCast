<?php

declare(strict_types=1);

namespace PureUnit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\RecurrenceEndType;
use App\Entity\Enums\RecurrenceType;
use App\Entity\Repository\StationScheduleRepository;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class StationScheduleTargetedUpdateTest extends TestCase
{
    public function testTargetedTimeUpdatePreservesRecurrenceFields(): void
    {
        $playlist = new StationPlaylist(new Station());
        $schedule = new StationSchedule($playlist);
        $schedule->start_time = 900;
        $schedule->end_time = 1000;
        $schedule->start_date = '2026-08-17';
        $schedule->end_date = '2026-12-31';
        $schedule->days = [1, 3];
        $schedule->recurrence_type = RecurrenceType::Weekly;
        $schedule->recurrence_interval = 2;
        $schedule->recurrence_end_type = RecurrenceEndType::OnDate;
        $schedule->recurrence_end_date = '2026-12-31';

        $repositoryReflection = new ReflectionClass(StationScheduleRepository::class);
        $repository = $repositoryReflection->newInstanceWithoutConstructor();
        $method = $repositoryReflection->getMethod('mergeScheduleItemUpdate');

        /** @var array<string, mixed> $updated */
        $updated = $method->invoke($repository, $schedule, [
            'start_time' => 1100,
            'end_time' => 1200,
            'unknown_field' => 'ignored',
        ]);

        self::assertSame(1100, $updated['start_time']);
        self::assertSame(1200, $updated['end_time']);
        self::assertSame([1, 3], $updated['days']);
        self::assertSame(RecurrenceType::Weekly, $updated['recurrence_type']);
        self::assertSame(2, $updated['recurrence_interval']);
        self::assertSame(RecurrenceEndType::OnDate, $updated['recurrence_end_type']);
        self::assertSame('2026-12-31', $updated['recurrence_end_date']);
        self::assertArrayNotHasKey('unknown_field', $updated);
    }

    public function testTargetedDeleteRemovesOnlyRequestedSchedule(): void
    {
        $playlist = new StationPlaylist(new Station());
        $schedule = new StationSchedule($playlist);
        $this->setId($playlist, 12);
        $this->setId($schedule, 42);

        $objectRepository = $this->createStub(EntityRepository::class);
        $em = $this->createMock(ReloadableEntityManagerInterface::class);
        $em->method('getRepository')->willReturn($objectRepository);
        $em->expects(self::once())
            ->method('find')
            ->with(StationSchedule::class, 42)
            ->willReturn($schedule);
        $em->expects(self::once())->method('remove')->with($schedule);
        $em->expects(self::once())->method('flush');

        $repositoryReflection = new ReflectionClass(StationScheduleRepository::class);
        /** @var StationScheduleRepository $repository */
        $repository = $repositoryReflection->newInstanceWithoutConstructor();
        $repository->setEntityManager($em);

        $repository->deleteScheduleItem($playlist, 42);
    }

    private function setId(object $entity, int $id): void
    {
        $property = new ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
