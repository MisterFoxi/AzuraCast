<?php

declare(strict_types=1);

namespace App\Radio\SmartBlock;

use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\StationPlaylistMediaRepository;
use App\Entity\Repository\StationPlaylistSmartBlockCriteriaRepository;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use InvalidArgumentException;

final class SmartBlockSynchronizer implements SmartBlockSynchronizerInterface
{
    public function __construct(
        private readonly StationPlaylistSmartBlockCriteriaRepository $criteriaRepository,
        private readonly StationPlaylistMediaRepository $playlistMediaRepository
    ) {
    }

    /**
     * @return array{matched: int, added: int, removed: int, unchanged: int, changed: bool}
     */
    public function synchronize(StationPlaylist $playlist): array
    {
        if (!$playlist->is_smart_block) {
            throw new InvalidArgumentException('Playlist is not a Smart Block.');
        }

        if (PlaylistSources::Songs !== $playlist->source) {
            throw new InvalidArgumentException('A Smart Block must be a song playlist.');
        }

        return $this->reconcile(
            $playlist,
            $this->criteriaRepository->getMatchingMedia($playlist)
        );
    }

    /**
     * Reconcile direct playlist memberships with an already resolved selection.
     *
     * This separate entry point keeps the mutation algorithm deterministic and
     * independently testable from the criteria query.
     *
     * @param iterable<StationMedia> $matchingMedia
     * @return array{matched: int, added: int, removed: int, unchanged: int, changed: bool}
     */
    public function reconcile(StationPlaylist $playlist, iterable $matchingMedia): array
    {
        $targetMedia = [];
        foreach ($matchingMedia as $media) {
            $targetMedia[$media->id] = $media;
        }

        $currentMemberships = [];
        $duplicateMemberships = [];
        foreach ($this->playlistMediaRepository->getDirectMediaMemberships($playlist) as $membership) {
            $mediaId = $membership->media->id;
            if (isset($currentMemberships[$mediaId])) {
                $duplicateMemberships[] = $membership;
            } else {
                $currentMemberships[$mediaId] = $membership;
            }
        }

        $added = 0;
        $removed = 0;
        $unchanged = 0;
        $reordered = 0;

        foreach ($duplicateMemberships as $membership) {
            $this->playlistMediaRepository->removeDirectMediaMembership($membership);
            ++$removed;
        }

        foreach ($currentMemberships as $mediaId => $membership) {
            if (isset($targetMedia[$mediaId])) {
                ++$unchanged;
                continue;
            }

            $this->playlistMediaRepository->removeDirectMediaMembership($membership);
            ++$removed;
        }

        $weight = 1;
        foreach ($targetMedia as $mediaId => $media) {
            if (isset($currentMemberships[$mediaId])) {
                $membership = $currentMemberships[$mediaId];
                if ($membership->weight !== $weight) {
                    $membership->weight = $weight;
                    $this->playlistMediaRepository->getEntityManager()->persist($membership);
                    ++$reordered;
                }
                ++$weight;
                continue;
            }

            if ($this->playlistMediaRepository->addMediaToPlaylistIfMissing($media, $playlist, $weight)) {
                ++$added;
            }
            ++$weight;
        }

        $changed = $added > 0 || $removed > 0 || $reordered > 0;
        if ($changed) {
            $this->playlistMediaRepository->getEntityManager()->flush();
        }

        return [
            'matched' => count($targetMedia),
            'added' => $added,
            'removed' => $removed,
            'unchanged' => $unchanged,
            'changed' => $changed,
        ];
    }
}
