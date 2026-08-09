<?php

declare(strict_types=1);

namespace App\Tests;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\PlaylistOrders;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistMedia;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\QueueBuilder;
use DateTimeImmutable;
use InvalidArgumentException;

final class AutoDjTestHarness
{
    /** @var Station[] */
    private array $stations = [];

    /** @var StationPlaylist[] */
    private array $playlists = [];

    /** @var StationMedia[] */
    private array $media = [];

    /** @var StationPlaylistMedia[] */
    private array $playlistMedia = [];

    public function __construct(
        private readonly ReloadableEntityManagerInterface $em,
        private readonly QueueBuilder $queueBuilder,
    ) {
    }

    /**
     * @param non-empty-list<string> $trackTitles
     * @return array{station: Station, playlist: StationPlaylist, media: list<StationMedia>}
     */
    public function createSequentialPlaylist(array $trackTitles): array
    {
        if ([] === $trackTitles) {
            throw new InvalidArgumentException('At least one track is required.');
        }

        $suffix = substr(uniqid('', true), -8);

        $station = new Station();
        $station->name = 'AutoDJ Test Station';
        $station->short_name = 'autodj_test_' . $suffix;
        $station->timezone = 'UTC';
        $station->ensureDirectoriesExist();

        $playlist = new StationPlaylist($station);
        $playlist->name = 'Sequential Test Playlist';
        $playlist->order = PlaylistOrders::Sequential;
        $playlist->avoid_duplicates = false;
        $station->playlists->add($playlist);

        $this->em->persist($station->media_storage_location);
        $this->em->persist($station->recordings_storage_location);
        $this->em->persist($station->podcasts_storage_location);
        $this->em->persist($station);
        $this->em->persist($playlist);

        $createdMedia = [];
        foreach ($trackTitles as $weight => $title) {
            $media = new StationMedia(
                $station->media_storage_location,
                '/autodj-test-' . $suffix . '-' . $weight . '.mp3'
            );
            $media->title = $title;
            $media->artist = 'AutoDJ Test Artist';
            $media->length = 180.0;
            $media->updateMetaFields();

            $playlistMedia = new StationPlaylistMedia($playlist, $media);
            $playlistMedia->weight = $weight;

            $playlist->media_items->add($playlistMedia);
            $media->playlists->add($playlistMedia);

            $this->em->persist($media);
            $this->em->persist($playlistMedia);

            $createdMedia[] = $media;
            $this->media[] = $media;
            $this->playlistMedia[] = $playlistMedia;
        }

        $this->em->flush();

        $this->stations[] = $station;
        $this->playlists[] = $playlist;

        return [
            'station' => $station,
            'playlist' => $playlist,
            'media' => $createdMedia,
        ];
    }

    public function calculateNextSong(Station $station, DateTimeImmutable $expectedPlayTime): BuildQueue
    {
        $event = new BuildQueue($station, $expectedPlayTime, $expectedPlayTime);
        $this->queueBuilder->calculateNextSong($event);
        $this->em->flush();

        return $event;
    }

    public function cleanUp(): void
    {
        if (!$this->em->isOpen()) {
            $this->em->open();
        }

        foreach ($this->stations as $station) {
            $this->em->createQuery('DELETE FROM App\\Entity\\StationQueue sq WHERE sq.station = :station')
                ->setParameter('station', $station)
                ->execute();
        }

        foreach ($this->playlistMedia as $playlistMedia) {
            $this->removeIfManaged($playlistMedia);
        }
        foreach ($this->playlists as $playlist) {
            $this->removeIfManaged($playlist);
        }
        foreach ($this->media as $media) {
            $this->removeIfManaged($media);
        }
        foreach ($this->stations as $station) {
            $this->removeIfManaged($station);
            $this->removeIfManaged($station->media_storage_location);
            $this->removeIfManaged($station->recordings_storage_location);
            $this->removeIfManaged($station->podcasts_storage_location);
        }

        $this->em->flush();
    }

    private function removeIfManaged(object $entity): void
    {
        if ($this->em->contains($entity)) {
            $this->em->remove($entity);
        }
    }
}
