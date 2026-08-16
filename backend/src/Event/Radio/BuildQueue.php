<?php

declare(strict_types=1);

namespace App\Event\Radio;

use App\Entity\Station;
use App\Entity\StationQueue;
use App\Utilities\Time;
use Closure;
use DateTimeImmutable;
use Symfony\Contracts\EventDispatcher\Event;

final class BuildQueue extends Event
{
    /** @var StationQueue[] */
    private array $nextSongs = [];

    private DateTimeImmutable $expectedCueTime;

    private DateTimeImmutable $expectedPlayTime;

    /** @var array<string, true> */
    private array $excludedSongIds = [];

    /** @var array<string, true> */
    private array $rejectedSongIds = [];

    private ?string $rejectionReason = null;

    /** @var list<Closure(): void> */
    private array $afterAcceptance = [];

    public function __construct(
        private readonly Station $station,
        ?DateTimeImmutable $expectedCueTime = null,
        ?DateTimeImmutable $expectedPlayTime = null,
        private readonly ?string $lastPlayedSongId = null,
        private readonly bool $isInterrupting = false,
        array $excludedSongIds = [],
    ) {
        $this->expectedCueTime = $expectedCueTime ?? Time::nowUtc();
        $this->expectedPlayTime = $expectedPlayTime ?? Time::nowUtc();
        $this->excludedSongIds = array_fill_keys($excludedSongIds, true);
    }

    public function getStation(): Station
    {
        return $this->station;
    }

    public function getExpectedCueTime(): DateTimeImmutable
    {
        return $this->expectedCueTime;
    }

    public function getExpectedPlayTime(): DateTimeImmutable
    {
        return $this->expectedPlayTime;
    }

    public function getLastPlayedSongId(): ?string
    {
        return $this->lastPlayedSongId;
    }

    public function isInterrupting(): bool
    {
        return $this->isInterrupting;
    }

    /** @return list<string> */
    public function getExcludedSongIds(): array
    {
        return array_keys($this->excludedSongIds);
    }

    public function isSongExcluded(string $songId): bool
    {
        return isset($this->excludedSongIds[$songId]);
    }

    public function wasRejected(): bool
    {
        return null !== $this->rejectionReason;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    /** @return list<string> */
    public function getRejectedSongIds(): array
    {
        return array_keys($this->rejectedSongIds);
    }

    /**
     * @return StationQueue[]
     */
    public function getNextSongs(): array
    {
        return $this->nextSongs;
    }

    /**
     * @param StationQueue|StationQueue[]|null $nextSongs
     *        Pass null to clear a previously selected pick (e.g. DMCA rejection).
     * @return bool True when the selection was updated (set or cleared).
     */
    public function setNextSongs(StationQueue|array|null $nextSongs): bool
    {
        // Clear selection so validators (DMCA) can reject a pick and force a retry.
        // Do not stopPropagation here — later listeners still need to run.
        if (null === $nextSongs) {
            $this->nextSongs = [];
            return true;
        }

        if (!is_array($nextSongs)) {
            if (
                $this->lastPlayedSongId === $nextSongs->song_id
                || $this->isSongExcluded($nextSongs->song_id)
            ) {
                return false;
            }

            $this->nextSongs = [$nextSongs];
        } else {
            foreach ($nextSongs as $nextSong) {
                if ($this->isSongExcluded($nextSong->song_id)) {
                    return false;
                }
            }

            $this->nextSongs = $nextSongs;
        }

        // Intentionally do NOT stopPropagation: lower-priority validators such as
        // DmcaComplianceListener must still run after a successful selector pick.
        // Selectors themselves early-return when getNextSongs() is already non-empty.
        return true;
    }

    public function rejectSelection(string $reason): bool
    {
        if ([] === $this->nextSongs) {
            return false;
        }

        foreach ($this->nextSongs as $nextSong) {
            $this->rejectedSongIds[$nextSong->song_id] = true;
            $this->excludedSongIds[$nextSong->song_id] = true;
        }

        $this->nextSongs = [];
        $this->rejectionReason = $reason;
        $this->afterAcceptance = [];
        $this->stopPropagation();

        return true;
    }

    public function deferUntilAccepted(Closure $callback): void
    {
        $this->afterAcceptance[] = $callback;
    }

    public function commitAcceptedSelection(): void
    {
        if ($this->wasRejected() || [] === $this->nextSongs) {
            $this->afterAcceptance = [];
            return;
        }

        $callbacks = $this->afterAcceptance;
        $this->afterAcceptance = [];

        foreach ($callbacks as $callback) {
            $callback();
        }
    }

    public function __toString(): string
    {
        return !empty($this->nextSongs)
            ? implode(', ', array_map('strval', $this->nextSongs))
            : 'No Song';
    }
}
