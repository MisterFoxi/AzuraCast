<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Enums\PlaylistTypes;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;

/**
 * Shared hour-boundary math for clock wheels and station-wide top-of-hour protection.
 */
final class HourBoundaryPlanner
{
    public const int HOUR_SECONDS = 3600;

    public const int DEFAULT_LOOKAHEAD_MINUTES = 10;

    public const int DEFAULT_FINISH_BUFFER_SECONDS = 15;

    public const int DEFAULT_COMPLIANCE_TOLERANCE_SECONDS = 10;

    public const int DEFAULT_ID_MAX_SECONDS = 60;

    public const int MIN_LOOKAHEAD_MINUTES = 1;

    public const int MAX_LOOKAHEAD_MINUTES = 30;

    public const int MIN_FINISH_BUFFER_SECONDS = 0;

    public const int MAX_FINISH_BUFFER_SECONDS = 120;

    public const int MIN_COMPLIANCE_TOLERANCE_SECONDS = 1;

    public const int MAX_COMPLIANCE_TOLERANCE_SECONDS = 60;

    public const int MIN_ID_MAX_SECONDS = 15;

    public const int MAX_ID_MAX_SECONDS = 120;

    public function __construct(
        private readonly StationQueueRepository $queueRepo,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isTopOfHourProtectionEnabled(Station $station): bool
    {
        $enabled = $station->backend_config->top_of_hour_id_enabled;

        $this->logger->info(
            '[HourBoundary] Protection status.',
            [
                'station_id' => $station->id,
                'enabled' => $enabled,
            ],
        );

        return $enabled;
    }

    public function getComplianceToleranceSeconds(Station $station): int
    {
        $configured = $station->backend_config->top_of_hour_compliance_tolerance_seconds;

        $value = $this->clampInt(
            $configured,
            self::MIN_COMPLIANCE_TOLERANCE_SECONDS,
            self::MAX_COMPLIANCE_TOLERANCE_SECONDS,
            self::DEFAULT_COMPLIANCE_TOLERANCE_SECONDS,
        );

        $this->logger->info(
            '[HourBoundary] Compliance tolerance.',
            [
                'station_id' => $station->id,
                'configured' => $configured,
                'effective' => $value,
            ],
        );

        return $value;
    }

    public function getLookaheadMinutes(Station $station): int
    {
        $configured = $station->backend_config->top_of_hour_lookahead_minutes;

        $value = $this->clampInt(
            $configured,
            self::MIN_LOOKAHEAD_MINUTES,
            self::MAX_LOOKAHEAD_MINUTES,
            self::DEFAULT_LOOKAHEAD_MINUTES,
        );

        $this->logger->info(
            '[HourBoundary] Lookahead.',
            [
                'station_id' => $station->id,
                'configured_minutes' => $configured,
                'effective_minutes' => $value,
            ],
        );

        return $value;
    }

    public function getFinishBufferSeconds(Station $station): int
    {
        $configured = $station->backend_config->top_of_hour_finish_buffer_seconds;

        $value = $this->clampInt(
            $configured,
            self::MIN_FINISH_BUFFER_SECONDS,
            self::MAX_FINISH_BUFFER_SECONDS,
            self::DEFAULT_FINISH_BUFFER_SECONDS,
        );

        $this->logger->info(
            '[HourBoundary] Finish buffer.',
            [
                'station_id' => $station->id,
                'configured_seconds' => $configured,
                'effective_seconds' => $value,
            ],
        );

        return $value;
    }

    public function getIdMaxSeconds(Station $station): int
    {
        $configured = $station->backend_config->top_of_hour_id_max_seconds;

        $value = $this->clampInt(
            $configured,
            self::MIN_ID_MAX_SECONDS,
            self::MAX_ID_MAX_SECONDS,
            self::DEFAULT_ID_MAX_SECONDS,
        );

        $this->logger->info(
            '[HourBoundary] Maximum ID duration.',
            [
                'station_id' => $station->id,
                'configured_seconds' => $configured,
                'effective_seconds' => $value,
            ],
        );

        return $value;
    }

    /**
     * Planned position within the broadcast hour (0–3599), using expected play time
     * and already-queued items in the same hour.
     */
    public function getPlannedSecondsIntoHour(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
        ?DateTimeZone $tz = null,
    ): int {
        $tz ??= $station->getTimezoneObject();

        $local = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);
        $hourStart = $local->startOf('hour');
        $seconds = $local->getTimestamp() - $hourStart->getTimestamp();

        $this->logger->info(
            '[HourBoundary] Calculating planned position.',
            [
                'station_id' => $station->id,
                'expected_play_time' => $expectedPlayTime->format(DATE_ATOM),
                'local_expected_play_time' => $local->format(DATE_ATOM),
                'timezone' => $tz->getName(),
                'initial_seconds_into_hour' => $seconds,
            ],
        );

        foreach ($this->queueRepo->getUnplayedQueue($station) as $row) {
            $playedAt = $row->timestamp_played;

            $this->logger->info(
                '[HourBoundary] Inspecting queue row for planned position.',
                [
                    'station_id' => $station->id,
                    'queue_id' => $row->id,
                    'timestamp_played' => $playedAt?->format(DATE_ATOM),
                    'duration' => $row->duration,
                    'top_of_hour_legal_id' => $row->top_of_hour_legal_id,
                    'clock_wheel_legal_id_substitute' => $row->clock_wheel_legal_id_substitute,
                ],
            );

            if ($playedAt === null) {
                $this->logger->info(
                    '[HourBoundary] Queue row ignored: timestamp_played is null.',
                    [
                        'queue_id' => $row->id,
                    ],
                );

                continue;
            }

            $queuedLocal = CarbonImmutable::instance($playedAt)->setTimezone($tz);

            if ($queuedLocal->format('Y-m-d H') !== $local->format('Y-m-d H')) {
                $this->logger->info(
                    '[HourBoundary] Queue row ignored: different broadcast hour.',
                    [
                        'queue_id' => $row->id,
                        'queued_local' => $queuedLocal->format(DATE_ATOM),
                        'expected_local' => $local->format(DATE_ATOM),
                    ],
                );

                continue;
            }

            if ($queuedLocal->greaterThanOrEqualTo($local)) {
                $this->logger->info(
                    '[HourBoundary] Queue row ignored: not before expected play time.',
                    [
                        'queue_id' => $row->id,
                        'queued_local' => $queuedLocal->format(DATE_ATOM),
                        'expected_local' => $local->format(DATE_ATOM),
                    ],
                );

                continue;
            }

            $queuedHourStart = $queuedLocal->startOf('hour');
            $queuedStartOffset = $queuedLocal->getTimestamp() - $queuedHourStart->getTimestamp();
            $queuedDuration = (int)ceil((float)($row->duration ?? 0));
            $queuedEndOffset = $queuedStartOffset + $queuedDuration;
            $previousSeconds = $seconds;

            $seconds = max(
                $seconds,
                min($queuedEndOffset, self::HOUR_SECONDS - 1),
            );

            $this->logger->info(
                '[HourBoundary] Queue row applied to planned position.',
                [
                    'queue_id' => $row->id,
                    'queued_start_offset' => $queuedStartOffset,
                    'queued_duration' => $queuedDuration,
                    'queued_end_offset' => $queuedEndOffset,
                    'previous_seconds_into_hour' => $previousSeconds,
                    'new_seconds_into_hour' => $seconds,
                ],
            );
        }

        $result = min(
            max(0, $seconds),
            self::HOUR_SECONDS - 1,
        );

        $this->logger->info(
            '[HourBoundary] Planned position calculated.',
            [
                'station_id' => $station->id,
                'seconds_into_hour' => $result,
            ],
        );

        return $result;
    }

    /**
     * Expected wall-clock time for the next mandatory top-of-hour legal ID.
     */
    public function resolveTopOfHourExpectedPlayAt(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): DateTimeImmutable {
        $tz = $station->getTimezoneObject();
        $local = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);
        $hourStart = $local->startOf('hour');
        $secondsIntoHour = $local->getTimestamp() - $hourStart->getTimestamp();

        if ($secondsIntoHour > 30) {
            $result = $hourStart->addHour()->toDateTimeImmutable();

            $this->logger->info(
                '[HourBoundary] Expected legal ID moved to next hour.',
                [
                    'station_id' => $station->id,
                    'expected_play_time' => $expectedPlayTime->format(DATE_ATOM),
                    'local_expected_play_time' => $local->format(DATE_ATOM),
                    'seconds_into_hour' => $secondsIntoHour,
                    'resolved_play_time' => $result->format(DATE_ATOM),
                ],
            );

            return $result;
        }

        $result = $hourStart->toDateTimeImmutable();

        $this->logger->info(
            '[HourBoundary] Expected legal ID kept on current hour boundary.',
            [
                'station_id' => $station->id,
                'expected_play_time' => $expectedPlayTime->format(DATE_ATOM),
                'local_expected_play_time' => $local->format(DATE_ATOM),
                'seconds_into_hour' => $secondsIntoHour,
                'resolved_play_time' => $result->format(DATE_ATOM),
            ],
        );

        return $result;
    }

    public function getNextTopOfHour(
        DateTimeImmutable $expectedPlayTime,
        DateTimeZone $tz,
    ): DateTimeImmutable {
        $local = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);
        $hourStart = $local->startOf('hour');

        if ($local->greaterThan($hourStart)) {
            $result = $hourStart->addHour()->toDateTimeImmutable();

            $this->logger->info(
                '[HourBoundary] Next top of hour selected.',
                [
                    'expected_play_time' => $expectedPlayTime->format(DATE_ATOM),
                    'local_expected_play_time' => $local->format(DATE_ATOM),
                    'timezone' => $tz->getName(),
                    'next_top_of_hour' => $result->format(DATE_ATOM),
                ],
            );

            return $result;
        }

        $result = $hourStart->toDateTimeImmutable();

        $this->logger->info(
            '[HourBoundary] Expected time is exactly on the hour.',
            [
                'expected_play_time' => $expectedPlayTime->format(DATE_ATOM),
                'local_expected_play_time' => $local->format(DATE_ATOM),
                'timezone' => $tz->getName(),
                'next_top_of_hour' => $result->format(DATE_ATOM),
            ],
        );

        return $result;
    }

    public function secondsUntilNextTopOfHour(
        DateTimeImmutable $expectedPlayTime,
        DateTimeZone $tz,
    ): int {
        $local = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);

        $nextTop = CarbonImmutable::instance(
            $this->getNextTopOfHour($expectedPlayTime, $tz),
        );

        $seconds = max(
            0,
            $nextTop->getTimestamp() - $local->getTimestamp(),
        );

        $this->logger->info(
            '[HourBoundary] Seconds until next top of hour.',
            [
                'expected_play_time' => $expectedPlayTime->format(DATE_ATOM),
                'local_expected_play_time' => $local->format(DATE_ATOM),
                'next_top_of_hour' => $nextTop->format(DATE_ATOM),
                'timezone' => $tz->getName(),
                'seconds_until_hour' => $seconds,
            ],
        );

        return $seconds;
    }

    public function isInLookaheadZone(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): bool {
        if (!$this->isTopOfHourProtectionEnabled($station)) {
            $this->logger->info(
                '[HourBoundary] Not in lookahead zone: protection disabled.',
                [
                    'station_id' => $station->id,
                    'expected_play_time' => $expectedPlayTime->format(DATE_ATOM),
                ],
            );

            return false;
        }

        $tz = $station->getTimezoneObject();
        $secondsUntil = $this->secondsUntilNextTopOfHour($expectedPlayTime, $tz);
        $lookaheadMinutes = $this->getLookaheadMinutes($station);
        $lookaheadSeconds = $lookaheadMinutes * 60;

        $result = $secondsUntil > 0
            && $secondsUntil <= $lookaheadSeconds;

        $this->logger->info(
            '[HourBoundary] Lookahead zone evaluated.',
            [
                'station_id' => $station->id,
                'expected_play_time' => $expectedPlayTime->format(DATE_ATOM),
                'seconds_until_hour' => $secondsUntil,
                'lookahead_minutes' => $lookaheadMinutes,
                'lookahead_seconds' => $lookaheadSeconds,
                'in_lookahead_zone' => $result,
            ],
        );

        return $result;
    }

    /**
     * Max music duration (seconds) so playback finishes before `:00` with finish buffer + ID headroom.
     * Returns null when protection is off or outside the lookahead window.
     */
    public function maxMusicDurationBeforeTopOfHour(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): ?float {
        if (!$this->isInLookaheadZone($station, $expectedPlayTime)) {
            $this->logger->info(
                '[HourBoundary] No maximum music duration: outside lookahead zone.',
                [
                    'station_id' => $station->id,
                    'expected_play_time' => $expectedPlayTime->format(DATE_ATOM),
                ],
            );

            return null;
        }

        $tz = $station->getTimezoneObject();
        $secondsUntil = $this->secondsUntilNextTopOfHour($expectedPlayTime, $tz);
        $finishBuffer = $this->getFinishBufferSeconds($station);
        $idMaxSeconds = $this->getIdMaxSeconds($station);
        $buffer = $finishBuffer + $idMaxSeconds;
        $maxDuration = max(1.0, (float)($secondsUntil - $buffer));

        $this->logger->info(
            '[HourBoundary] Maximum music duration calculated.',
            [
                'station_id' => $station->id,
                'expected_play_time' => $expectedPlayTime->format(DATE_ATOM),
                'seconds_until_hour' => $secondsUntil,
                'finish_buffer_seconds' => $finishBuffer,
                'id_max_seconds' => $idMaxSeconds,
                'total_buffer_seconds' => $buffer,
                'max_music_duration_seconds' => $maxDuration,
            ],
        );

        return $maxDuration;
    }

    /**
     * True when AutoDJ should queue the mandatory legal ID for this build tick.
     */
    public function isTopOfHourIdDue(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): bool {
        if (!$this->isTopOfHourProtectionEnabled($station)) {
            $this->logger->info(
                '[HourBoundary] Legal ID not due: protection disabled.',
                [
                    'station_id' => $station->id,
                    'expected_play_time' => $expectedPlayTime->format(DATE_ATOM),
                ],
            );

            return false;
        }

        $tz = $station->getTimezoneObject();
        $secondsUntil = $this->secondsUntilNextTopOfHour($expectedPlayTime, $tz);
        $finishBuffer = $this->getFinishBufferSeconds($station);
        $idMaxSeconds = $this->getIdMaxSeconds($station);
        $buffer = $finishBuffer + $idMaxSeconds;

        $this->logger->info(
            '[HourBoundary] Evaluating legal ID trigger.',
            [
                'station_id' => $station->id,
                'expected_play_time' => $expectedPlayTime->format(DATE_ATOM),
                'timezone' => $tz->getName(),
                'seconds_until_hour' => $secondsUntil,
                'finish_buffer_seconds' => $finishBuffer,
                'id_max_seconds' => $idMaxSeconds,
                'total_buffer_seconds' => $buffer,
            ],
        );

        // Trigger ID when expected play time falls in the buffer window before
        // the hour boundary. This prevents dead air between music ending and
        // the old :00-only trigger. E.g. with buffer=120s, ID fires at :58.
        if (
            $secondsUntil > $buffer
            || $secondsUntil > self::HOUR_SECONDS / 2
        ) {
            $this->logger->info(
                '[HourBoundary] Legal ID not due: outside trigger window.',
                [
                    'station_id' => $station->id,
                    'seconds_until_hour' => $secondsUntil,
                    'total_buffer_seconds' => $buffer,
                    'later_than_buffer' => $secondsUntil > $buffer,
                    'previous_hour_guard' => $secondsUntil > self::HOUR_SECONDS / 2,
                ],
            );

            return false;
        }

        $boundary = $this->resolveTopOfHourExpectedPlayAt($station, $expectedPlayTime);
        $alreadyQueued = $this->hasTopOfHourIdForBoundary($station, $boundary);

        $isDue = !$alreadyQueued;

        $this->logger->info(
            '[HourBoundary] Legal ID trigger result.',
            [
                'station_id' => $station->id,
                'expected_play_time' => $expectedPlayTime->format(DATE_ATOM),
                'seconds_until_hour' => $secondsUntil,
                'already_queued' => $alreadyQueued,
                'is_due' => $isDue,
            ],
        );

        return $isDue;
    }

    /**
     * When station-wide top-of-hour protection is on, legacy once-per-hour playlists
     * pinned to minute :00 are suppressed — TopOfHourIdScheduler queues legal_id instead.
     */
    public function shouldSuppressOncePerHourPlaylist(
        StationPlaylist $playlist,
    ): bool {
        if (!$this->isTopOfHourProtectionEnabled($playlist->station)) {
            $this->logger->info(
                '[HourBoundary] Once-per-hour playlist not suppressed: protection disabled.',
                [
                    'station_id' => $playlist->station->id,
                    'playlist_id' => $playlist->id,
                    'playlist_type' => $playlist->type->value,
                    'play_per_hour_minute' => $playlist->play_per_hour_minute,
                ],
            );

            return false;
        }

        $suppress = $playlist->type === PlaylistTypes::OncePerHour
            && $playlist->play_per_hour_minute === 0;

        $this->logger->info(
            '[HourBoundary] Once-per-hour playlist suppression evaluated.',
            [
                'station_id' => $playlist->station->id,
                'playlist_id' => $playlist->id,
                'playlist_type' => $playlist->type->value,
                'play_per_hour_minute' => $playlist->play_per_hour_minute,
                'suppressed' => $suppress,
            ],
        );

        return $suppress;
    }

    /**
     * True when a mandatory top-of-hour ID is already queued and not yet aired.
     *
     * Deliberately narrow: only rows carrying the top-of-hour markers count. We do
     * NOT treat ordinary station-ID media (type 'id'/'legal_id') coming from normal
     * rotation as "already satisfied" -- doing so let any rotation jingle sitting in
     * the queue suppress the mandatory ID entirely. No timestamp/hour bucketing is
     * needed either: at most one mandatory ID is ever pending unplayed at a time, so
     * the flag alone is the correct, drift-free dedup guard.
     */
    public function hasTopOfHourIdQueued(Station $station): bool
    {
        $inspectedRows = 0;

        $this->logger->info(
            '[HourBoundary] Searching unplayed queue for mandatory legal ID.',
            [
                'station_id' => $station->id,
            ],
        );

        foreach ($this->queueRepo->getUnplayedQueue($station) as $row) {
            ++$inspectedRows;

            $this->logger->info(
                '[HourBoundary] Inspecting unplayed queue row.',
                [
                    'station_id' => $station->id,
                    'queue_id' => $row->id,
                    'timestamp_played' => $row->timestamp_played?->format(DATE_ATOM),
                    'duration' => $row->duration,
                    'top_of_hour_legal_id' => $row->top_of_hour_legal_id,
                    'clock_wheel_legal_id_substitute' => $row->clock_wheel_legal_id_substitute,
                ],
            );

            if (
                $row->top_of_hour_legal_id
                || $row->clock_wheel_legal_id_substitute
            ) {
                $this->logger->info(
                    '[HourBoundary] Mandatory legal ID already queued.',
                    [
                        'station_id' => $station->id,
                        'queue_id' => $row->id,
                        'inspected_rows' => $inspectedRows,
                        'top_of_hour_legal_id' => $row->top_of_hour_legal_id,
                        'clock_wheel_legal_id_substitute' => $row->clock_wheel_legal_id_substitute,
                    ],
                );

                return true;
            }
        }

        $this->logger->info(
            '[HourBoundary] No mandatory legal ID found in unplayed queue.',
            [
                'station_id' => $station->id,
                'inspected_rows' => $inspectedRows,
            ],
        );

        return false;
    }

/**
     * True when a mandatory legal ID has already been served for the given boundary,
     * whether still queued or already aired. Anchored on the hour that ENDS at the
     * boundary -- (boundary - 1h, boundary] -- which is where the ID's timestamp_cued
     * lands. Counting already-aired rows is what stops the pre-:00 window from
     * enqueuing a fresh ID every tick once the previous one has left the live queue.
     */
    public function hasTopOfHourIdForBoundary(Station $station, DateTimeImmutable $boundary): bool
    {
        $boundaryUtc = CarbonImmutable::instance($boundary)->utc();
        $windowStart = $boundaryUtc->subHour()->toDateTimeImmutable();
        $windowEnd = $boundaryUtc->toDateTimeImmutable();

        return $this->queueRepo->hasTopOfHourLegalIdCuedBetween($station, $windowStart, $windowEnd);
    }

    /**
     * Real-time interrupt trigger. Unlike isTopOfHourIdDue(), which is evaluated
     * against the look-ahead queue tail (a future expectedPlayTime that never coincides
     * with the pre-:00 window), this is evaluated against wall-clock `now` so the
     * mandatory ID can be injected into the interrupting queue at the boundary.
     */
    public function isTopOfHourInterruptDue(Station $station, DateTimeImmutable $now): bool
    {
        if (!$this->isTopOfHourProtectionEnabled($station)) {
            return false;
        }

        $tz = $station->getTimezoneObject();
        $secondsUntil = $this->secondsUntilNextTopOfHour($now, $tz);
        $buffer = $this->getFinishBufferSeconds($station) + $this->getIdMaxSeconds($station);

        if ($secondsUntil > $buffer) {
            return false;
        }

        $boundary = $this->resolveTopOfHourExpectedPlayAt($station, $now);

        return !$this->hasTopOfHourIdForBoundary($station, $boundary);
    }

    private function clampInt(
        int $value,
        int $min,
        int $max,
        int $default,
    ): int {
        if ($value < $min || $value > $max) {
            $this->logger->info(
                '[HourBoundary] Configuration value outside allowed range.',
                [
                    'configured_value' => $value,
                    'minimum' => $min,
                    'maximum' => $max,
                    'fallback_value' => $default,
                ],
            );

            return $default;
        }

        return $value;
    }
}