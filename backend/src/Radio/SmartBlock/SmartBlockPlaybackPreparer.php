<?php

declare(strict_types=1);

namespace App\Radio\SmartBlock;

use App\Entity\Enums\SmartBlockType;
use App\Entity\StationPlaylist;
use Psr\Log\LoggerInterface;
use Throwable;

final class SmartBlockPlaybackPreparer
{
    /** @var array<int, bool> */
    private array $preparedPlaylists = [];

    public function __construct(
        private readonly SmartBlockSynchronizerInterface $synchronizer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function beginQueueBuild(): void
    {
        $this->preparedPlaylists = [];
    }

    public function prepare(StationPlaylist $playlist): bool
    {
        if (!$playlist->is_smart_block || SmartBlockType::Dynamic !== $playlist->smart_block_type) {
            return true;
        }

        $key = spl_object_id($playlist);
        if (array_key_exists($key, $this->preparedPlaylists)) {
            return $this->preparedPlaylists[$key];
        }

        // Cache failures too: duplicate-prevention retries must not repeatedly
        // execute a failing synchronization during the same queue build.
        $this->preparedPlaylists[$key] = false;

        try {
            $result = $this->synchronizer->synchronize($playlist);
            $this->preparedPlaylists[$key] = true;

            if ($result['changed']) {
                $this->logger->info(
                    'Dynamic Smart Block synchronized before playback.',
                    [
                        'playlist_id' => $playlist->id,
                        'matched' => $result['matched'],
                        'added' => $result['added'],
                        'removed' => $result['removed'],
                    ]
                );
            }

            return true;
        } catch (Throwable $e) {
            $this->logger->error(
                'Dynamic Smart Block synchronization failed; skipping this playlist.',
                [
                    'playlist_id' => $playlist->id,
                    'exception' => $e->getMessage(),
                ]
            );

            return false;
        }
    }
}
