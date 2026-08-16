<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Doctrine\Repository;
use App\Entity\Api\StationSchedule as ApiStationSchedule;
use App\Entity\ApiGenerator\ScheduleApiGenerator;
use App\Entity\Enums\RecurrenceEndType;
use App\Entity\Enums\RecurrenceMonthlyPattern;
use App\Entity\Enums\RecurrenceType;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Entity\StationStreamer;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Radio\AutoDJ\Scheduler;
use App\Radio\Schedule\ScheduleConflictChecker;
use App\Utilities\DateRange;
use App\Utilities\ScheduleRecurrence;
use App\Utilities\Time;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * @extends Repository<StationSchedule>
 */
final class StationScheduleRepository extends Repository
{
    private const array UPDATE_FIELDS = [
        'start_time',
        'end_time',
        'start_date',
        'end_date',
        'days',
        'loop_once',
        'is_emergency',
        'strict_start',
        'recurrence_type',
        'recurrence_interval',
        'recurrence_monthly_pattern',
        'recurrence_monthly_day',
        'recurrence_monthly_week',
        'recurrence_monthly_day_of_week',
        'recurrence_end_type',
        'recurrence_end_after',
        'recurrence_end_date',
    ];

    protected string $entityClass = StationSchedule::class;

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly ScheduleApiGenerator $scheduleApiGenerator,
        private readonly ScheduleConflictChecker $conflictChecker,
    ) {
    }

    /**
     * @param StationPlaylist|StationStreamer $relation
     * @param array $items
     */
    public function setScheduleItems(
        StationPlaylist|StationStreamer $relation,
        array $items = []
    ): void {
        $station = $relation->station;

        $this->conflictChecker->assertBatchHasNoConflicts($station, $relation, $items);

        $rawScheduleItems = $this->findByRelation($relation);

        $scheduleItems = [];
        foreach ($rawScheduleItems as $row) {
            $scheduleItems[$row->id] = $row;
        }

        foreach ($items as $item) {
            $this->validateRecurrenceItem($item);

            if (isset($item['id'], $scheduleItems[$item['id']])) {
                $record = $scheduleItems[$item['id']];
                unset($scheduleItems[$item['id']]);
            } else {
                $record = new StationSchedule($relation);
            }

            $this->populateScheduleItem($record, $item);

            $this->em->persist($record);
        }

        foreach ($scheduleItems as $row) {
            $this->em->remove($row);
        }

        $this->em->flush();
    }

    /**
     * Update one schedule without replacing the relation's full schedule collection.
     *
     * @param array<string, mixed> $changes
     */
    public function updateScheduleItem(
        StationPlaylist $playlist,
        int $scheduleId,
        array $changes,
    ): StationSchedule {
        $record = $this->requireForPlaylist($playlist, $scheduleId);

        $candidate = $this->mergeScheduleItemUpdate($record, $changes);
        $this->validateRecurrenceItem($candidate);

        $items = [];
        foreach ($this->findByRelation($playlist) as $scheduleItem) {
            $items[] = $scheduleItem->id === $scheduleId
                ? $candidate
                : $this->scheduleItemToArray($scheduleItem);
        }

        $this->conflictChecker->assertBatchHasNoConflicts($playlist->station, $playlist, $items);

        $this->populateScheduleItem($record, $candidate);
        $this->em->persist($record);
        $this->em->flush();

        return $record;
    }

    public function deleteScheduleItem(StationPlaylist $playlist, int $scheduleId): void
    {
        $record = $this->requireForPlaylist($playlist, $scheduleId);

        $this->em->remove($record);
        $this->em->flush();
    }

    private function requireForPlaylist(StationPlaylist $playlist, int $scheduleId): StationSchedule
    {
        $record = $this->find($scheduleId);
        if (!$record instanceof StationSchedule || $record->playlist?->id !== $playlist->id) {
            throw NotFoundException::generic();
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function mergeScheduleItemUpdate(StationSchedule $record, array $changes): array
    {
        $allowedChanges = array_intersect_key($changes, array_flip(self::UPDATE_FIELDS));

        return array_replace($this->scheduleItemToArray($record), $allowedChanges);
    }

    /** @return array<string, mixed> */
    private function scheduleItemToArray(StationSchedule $record): array
    {
        return [
            'start_time' => $record->start_time,
            'end_time' => $record->end_time,
            'start_date' => $record->start_date,
            'end_date' => $record->end_date,
            'days' => $record->days,
            'loop_once' => $record->loop_once,
            'is_emergency' => $record->is_emergency,
            'strict_start' => $record->strict_start,
            'recurrence_type' => $record->recurrence_type,
            'recurrence_interval' => $record->recurrence_interval,
            'recurrence_monthly_pattern' => $record->recurrence_monthly_pattern,
            'recurrence_monthly_day' => $record->recurrence_monthly_day,
            'recurrence_monthly_week' => $record->recurrence_monthly_week,
            'recurrence_monthly_day_of_week' => $record->recurrence_monthly_day_of_week,
            'recurrence_end_type' => $record->recurrence_end_type,
            'recurrence_end_after' => $record->recurrence_end_after,
            'recurrence_end_date' => $record->recurrence_end_date,
        ];
    }

    /** @param array<string, mixed> $item */
    private function populateScheduleItem(StationSchedule $record, array $item): void
    {
        $record->start_time = (int)$item['start_time'];
        $record->end_time = (int)$item['end_time'];
        $record->start_date = $item['start_date'] ?? null;
        $record->end_date = $item['end_date'] ?? null;

        $daysInput = $item['days'] ?? [];
        if (!is_array($daysInput)) {
            $daysInput = [];
        }
        $record->days = array_values(array_unique(array_filter(
            array_map(static fn ($d) => (int)$d, $daysInput),
            static fn (int $d) => $d >= 1 && $d <= 7
        )));

        $record->loop_once = $item['loop_once'] ?? false;
        $record->is_emergency = (bool)($item['is_emergency'] ?? false);
        $record->strict_start = (bool)($item['strict_start'] ?? false);
        $record->recurrence_type = isset($item['recurrence_type'])
            ? (is_string($item['recurrence_type']) ? RecurrenceType::tryFrom($item['recurrence_type']) : $item['recurrence_type'])
            : null;
        $record->recurrence_interval = isset($item['recurrence_interval'])
            ? max(1, (int)$item['recurrence_interval'])
            : 1;
        $record->recurrence_monthly_pattern = isset($item['recurrence_monthly_pattern'])
            ? (is_string($item['recurrence_monthly_pattern']) ? RecurrenceMonthlyPattern::tryFrom($item['recurrence_monthly_pattern']) : $item['recurrence_monthly_pattern'])
            : null;
        $record->recurrence_monthly_day = isset($item['recurrence_monthly_day']) ? (int)$item['recurrence_monthly_day'] : null;
        $record->recurrence_monthly_week = isset($item['recurrence_monthly_week']) ? (int)$item['recurrence_monthly_week'] : null;
        $record->recurrence_monthly_day_of_week = isset($item['recurrence_monthly_day_of_week']) ? (int)$item['recurrence_monthly_day_of_week'] : null;
        $record->recurrence_end_type = isset($item['recurrence_end_type'])
            ? (is_string($item['recurrence_end_type']) ? RecurrenceEndType::tryFrom($item['recurrence_end_type']) ?? RecurrenceEndType::Never : $item['recurrence_end_type'])
            : RecurrenceEndType::Never;
        $record->recurrence_end_after = isset($item['recurrence_end_after']) ? (int)$item['recurrence_end_after'] : null;
        $record->recurrence_end_date = $item['recurrence_end_date'] ?? null;
    }

    /**
     * @param array<string, mixed> $item
     * @throws ValidationException
     */
    private function validateRecurrenceItem(array $item): void
    {
        $recurrenceType = isset($item['recurrence_type'])
            ? (is_string($item['recurrence_type']) ? RecurrenceType::tryFrom($item['recurrence_type']) : $item['recurrence_type'])
            : null;
        $endType = isset($item['recurrence_end_type'])
            ? (is_string($item['recurrence_end_type']) ? RecurrenceEndType::tryFrom($item['recurrence_end_type']) : $item['recurrence_end_type'])
            : RecurrenceEndType::Never;

        if (null !== $recurrenceType) {
            $startDateRaw = $item['start_date'] ?? null;
            $endDateRaw = $item['end_date'] ?? null;
            $startDate = $this->parseScheduleDate($startDateRaw, 'Start date');
            $endDate = $this->parseScheduleDate($endDateRaw, 'End date');

            if (null !== $startDate && null !== $endDate && $endDate <= $startDate) {
                throw new ValidationException(__('End date must be after start date for recurring events.'));
            }
        }

        if ($recurrenceType === RecurrenceType::Monthly) {
            $pattern = isset($item['recurrence_monthly_pattern'])
                ? (is_string($item['recurrence_monthly_pattern']) ? RecurrenceMonthlyPattern::tryFrom($item['recurrence_monthly_pattern']) : $item['recurrence_monthly_pattern'])
                : null;
            if ($pattern === null) {
                throw new ValidationException(__('Monthly recurrence requires a pattern (date or day_of_week).'));
            }
            if ($pattern === RecurrenceMonthlyPattern::Date) {
                $day = isset($item['recurrence_monthly_day']) ? (int)$item['recurrence_monthly_day'] : null;
                if ($day === null || $day < 1 || $day > 31) {
                    throw new ValidationException(__('Monthly date pattern requires day of month (1-31).'));
                }
            } else {
                $week = isset($item['recurrence_monthly_week']) ? (int)$item['recurrence_monthly_week'] : null;
                $dow = isset($item['recurrence_monthly_day_of_week']) ? (int)$item['recurrence_monthly_day_of_week'] : null;
                $daysInput = $item['days'] ?? [];
                if (!is_array($daysInput)) {
                    $daysInput = [];
                }
                $validDays = array_values(array_filter(
                    array_map(static fn ($x) => (int) $x, $daysInput),
                    static fn (int $d) => $d >= 1 && $d <= 7
                ));
                if ($week === null || $week < 1 || $week > 5) {
                    throw new ValidationException(__('Monthly day-of-week pattern requires week (1-4 or 5 for last).'));
                }
                if ($validDays === [] && ($dow === null || $dow < 1 || $dow > 7)) {
                    throw new ValidationException(__('Monthly day-of-week pattern requires at least one weekday (1-7), using Scheduled days and/or day of week.'));
                }
            }
        }

        if ($endType === RecurrenceEndType::After) {
            $after = isset($item['recurrence_end_after']) ? (int)$item['recurrence_end_after'] : null;
            if ($after === null || $after < 1) {
                throw new ValidationException(__('Recurrence end "after" requires a positive occurrence count.'));
            }
        }
        if ($endType === RecurrenceEndType::OnDate) {
            $endDate = $item['recurrence_end_date'] ?? null;
            if ($endDate === null || $endDate === '') {
                throw new ValidationException(__('Recurrence end "on date" requires recurrence_end_date.'));
            }
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d', (string)$endDate);
            if ($parsed === false) {
                throw new ValidationException(__('Recurrence end date must be in Y-m-d format.'));
            }
        }
    }

    private function parseScheduleDate(mixed $value, string $label): ?DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$value);
        $errors = DateTimeImmutable::getLastErrors();
        if (false === $date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new ValidationException(__($label . ' must be in Y-m-d format.'));
        }

        return $date;
    }

    /**
     * @param StationPlaylist|StationStreamer $relation
     *
     * @return StationSchedule[]
     */
    public function findByRelation(StationPlaylist|StationStreamer $relation): array
    {
        if ($relation instanceof StationPlaylist) {
            return $this->repository->findBy(['playlist' => $relation]);
        }

        return $this->repository->findBy(['streamer' => $relation]);
    }

    /**
     * @param Station $station
     *
     * @return StationSchedule[]
     */
    public function getAllScheduledItemsForStation(Station $station): array
    {
        return $this->em->createQuery(
            <<<'DQL'
                SELECT ssc, sp, sst
                FROM App\Entity\StationSchedule ssc
                LEFT JOIN ssc.playlist sp
                LEFT JOIN ssc.streamer sst
                WHERE (sp.station = :station AND sp.is_jingle = 0 AND sp.is_enabled = 1)
                OR (sst.station = :station AND sst.is_active = 1)
            DQL
        )->setParameter('station', $station)
            ->execute();
    }

    /**
     * @param Station $station
     * @param DateTimeImmutable|null $now
     *
     * @return ApiStationSchedule[]
     */
    public function getUpcomingSchedule(
        Station $station,
        ?DateTimeImmutable $now = null
    ): array {
        $stationTz = $station->getTimezoneObject();
        $now = CarbonImmutable::instance(Time::nowInTimezone($stationTz, $now));

        $startDate = $now->subDay();
        $endDate = $now->addDay()->addHour();

        $events = [];

        foreach ($this->getAllScheduledItemsForStation($station) as $scheduleItem) {
            /** @var StationSchedule $scheduleItem */
            if (ScheduleRecurrence::hasRecurrence($scheduleItem)) {
                $occurrences = ScheduleRecurrence::getOccurrencesInRange(
                    $scheduleItem,
                    $stationTz,
                    $startDate,
                    $endDate,
                    50
                );
                foreach ($occurrences as $dateRange) {
                    if ($dateRange->end->lessThan($now)) {
                        continue;
                    }
                    $events[] = ($this->scheduleApiGenerator)(
                        $station,
                        $scheduleItem,
                        $dateRange,
                        $now
                    );
                }
                continue;
            }

            $i = $startDate;

            while ($i <= $endDate) {
                $dayOfWeek = $i->dayOfWeekIso;

                if (
                    $this->scheduler->shouldSchedulePlayOnCurrentDate($scheduleItem, $stationTz, $i)
                    && $this->scheduler->isScheduleScheduledToPlayToday($scheduleItem, $dayOfWeek)
                ) {
                    $start = StationSchedule::getDateTime($scheduleItem->start_time, $stationTz, $i);
                    $end = StationSchedule::getDateTime($scheduleItem->end_time, $stationTz, $i);

                    // Handle overnight schedule items
                    if ($end < $start) {
                        $end = $end->addDay();

                        // For overnight schedules, verify the event end doesn't exceed the configured end_date
                        if (!empty($scheduleItem->end_date)) {
                            $configuredEndDate = CarbonImmutable::createFromFormat(
                                'Y-m-d',
                                $scheduleItem->end_date,
                                $stationTz
                            );
                            if (null !== $configuredEndDate) {
                                // Allow one extra day if start_date == end_date (single overnight event)
                                if ($scheduleItem->start_date === $scheduleItem->end_date) {
                                    $configuredEndDate = $configuredEndDate->addDay();
                                }
                                $maxEndDateTime = StationSchedule::getDateTime(
                                    $scheduleItem->end_time,
                                    $stationTz,
                                    $configuredEndDate
                                );

                                if ($end->greaterThan($maxEndDateTime)) {
                                    $i = $i->addDay();
                                    continue; // Skip this event - it exceeds the configured date range
                                }
                            }
                        }
                    }

                    // Skip events that have already happened today.
                    if ($end->lessThan($now)) {
                        $i = $i->addDay();
                        continue;
                    }

                    $events[] = ($this->scheduleApiGenerator)(
                        $station,
                        $scheduleItem,
                        new DateRange($start, $end),
                        $now
                    );
                }

                $i = $i->addDay();
            }
        }

        usort(
            $events,
            static function ($a, $b) {
                return $a->start_timestamp <=> $b->start_timestamp;
            }
        );

        return $events;
    }

    /**
     * Earliest upcoming start timestamp for the given playlist's schedule (for "sync N hours before air").
     * Uses station timezone. Looks up to 35 days ahead.
     *
     * @return int|null Unix timestamp of next start, or null if playlist has no schedule or no future occurrence
     */
    public function getNextStartTimestampForPlaylist(
        Station $station,
        StationPlaylist $playlist,
        ?DateTimeImmutable $now = null
    ): ?int {
        $stationTz = $station->getTimezoneObject();
        $now = CarbonImmutable::instance(Time::nowInTimezone($stationTz, $now));
        $rangeStart = $now;
        $rangeEnd = $now->addDays(35);

        $scheduleItems = $this->findByRelation($playlist);
        if ($scheduleItems === []) {
            return null;
        }

        $candidates = [];

        foreach ($scheduleItems as $scheduleItem) {
            if (ScheduleRecurrence::hasRecurrence($scheduleItem)) {
                $occurrences = ScheduleRecurrence::getOccurrencesInRange(
                    $scheduleItem,
                    $stationTz,
                    $rangeStart,
                    $rangeEnd,
                    20
                );
                foreach ($occurrences as $dateRange) {
                    if ($dateRange->start->getTimestamp() >= $now->getTimestamp()) {
                        $candidates[] = $dateRange->start->getTimestamp();
                        break;
                    }
                }
                continue;
            }

            $i = $rangeStart->startOf('day');
            while ($i <= $rangeEnd) {
                $dayOfWeek = $i->dayOfWeekIso;
                if (
                    $this->scheduler->shouldSchedulePlayOnCurrentDate($scheduleItem, $stationTz, $i)
                    && $this->scheduler->isScheduleScheduledToPlayToday($scheduleItem, $dayOfWeek)
                ) {
                    $start = StationSchedule::getDateTime($scheduleItem->start_time, $stationTz, $i);
                    if ($start->getTimestamp() >= $now->getTimestamp()) {
                        $candidates[] = $start->getTimestamp();
                        break;
                    }
                }
                $i = $i->addDay();
            }
        }

        if ($candidates === []) {
            return null;
        }

        return min($candidates);
    }
}
