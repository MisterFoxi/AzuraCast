<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\SmartBlockType;
use App\Entity\Station;
use App\Radio\SmartBlock\SmartBlockSynchronizerInterface;
use Throwable;

final class SynchronizeSmartBlocksTask extends AbstractTask
{
    public function __construct(
        private readonly SmartBlockSynchronizerInterface $synchronizer
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return self::SCHEDULE_EVERY_FIVE_MINUTES;
    }

    public function run(bool $force = false): void
    {
        foreach ($this->iterateStations() as $station) {
            $this->synchronizeStation($station);
        }
    }

    public function synchronizeStation(Station $station): void
    {
        foreach ($station->playlists as $playlist) {
            if (!$playlist->is_smart_block
                || SmartBlockType::Dynamic !== $playlist->smart_block_type
                || PlaylistSources::Songs !== $playlist->source
            ) {
                continue;
            }

            try {
                $result = $this->synchronizer->synchronize($playlist);
                $this->logger->debug('Dynamic Smart Block synchronized.', [
                    'station' => $station->name,
                    'playlist' => $playlist->name,
                    'result' => $result,
                ]);
            } catch (Throwable $e) {
                $this->logger->error('Dynamic Smart Block synchronization failed.', [
                    'station' => $station->name,
                    'playlist' => $playlist->name,
                    'exception' => $e,
                ]);
            }
        }
    }
}
