<?php

declare(strict_types=1);

namespace App\Tests;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistGroupMember;
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

    /** @var StationPlaylistGroupMember[] */
    private array $groupMembers = [];

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
        $station = $this->createStation($suffix);
        $playlistFixture = $this->createPlaylist($station, 'Sequential Test Playlist', $trackTitles, $suffix);

        $this->em->flush();

        return [
            'station' => $station,
            'playlist' => $playlistFixture['playlist'],
            'media' => $playlistFixture['media'],
        ];
    }

    /**
     * @param array<string, non-empty-list<string>> $playlists
     * @param non-empty-list<string> $memberOrder
     * @return array{station: Station, group: StationPlaylist, playlists: array<string, StationPlaylist>}
     */
    public function createSequentialGroup(array $playlists, array $memberOrder): array
    {
        if ([] === $playlists || [] === $memberOrder) {
            throw new InvalidArgumentException('At least one playlist and one member occurrence are required.');
        }

        $suffix = substr(uniqid('', true), -8);
        $station = $this->createStation($suffix);

        $createdPlaylists = [];
        foreach ($playlists as $name => $trackTitles) {
            if ([] === $trackTitles) {
                throw new InvalidArgumentException('Each child playlist must contain at least one track.');
            }

            $fixture = $this->createPlaylist($station, $name, $trackTitles, $suffix);
            $createdPlaylists[$name] = $fixture['playlist'];
        }

        $group = new StationPlaylist($station);
        $group->name = 'Sequential Test Group';
        $group->source = PlaylistSources::Group;
        $group->avoid_duplicates = false;
        $station->playlists->add($group);
        $this->em->persist($group);
        $this->playlists[] = $group;

        foreach ($memberOrder as $position => $playlistName) {
            $childPlaylist = $createdPlaylists[$playlistName] ?? null;
            if (!$childPlaylist instanceof StationPlaylist) {
                throw new InvalidArgumentException('Unknown child playlist: ' . $playlistName);
            }

            $member = new StationPlaylistGroupMember($group, $childPlaylist, $position);
            $group->group_members->add($member);
            $childPlaylist->group_memberships->add($member);
            $this->em->persist($member);
            $this->groupMembers[] = $member;
        }

        $this->em->flush();

        return [
            'station' => $station,
            'group' => $group,
            'playlists' => $createdPlaylists,
        ];
    }

    private function createStation(string $suffix): Station
    {
        $station = new Station();
        $station->name = 'AutoDJ Test Station';
        $station->short_name = 'autodj_test_' . $suffix;
        $station->timezone = 'UTC';
        $station->ensureDirectoriesExist();

        $this->em->persist($station->media_storage_location);
        $this->em->persist($station->recordings_storage_location);
        $this->em->persist($station->podcasts_storage_location);
        $this->em->persist($station);
        $this->stations[] = $station;

        return $station;
    }

    /**
     * @param non-empty-list<string> $trackTitles
     * @return array{playlist: StationPlaylist, media: list<StationMedia>}
     */
    private function createPlaylist(
        Station $station,
        string $name,
        array $trackTitles,
        string $suffix,
    ): array {
        $playlist = new StationPlaylist($station);
        $playlist->name = $name;
        $playlist->order = PlaylistOrders::Sequential;
        $playlist->avoid_duplicates = false;
        $station->playlists->add($playlist);

        $this->em->persist($playlist);
        $this->playlists[] = $playlist;

        $createdMedia = [];
        foreach ($trackTitles as $weight => $title) {
            $media = new StationMedia(
                $station->media_storage_location,
                '/autodj-test-' . $suffix . '-' . count($this->media) . '.mp3'
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

        return [
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

        foreach ($this->groupMembers as $groupMember) {
            $this->removeIfManaged($groupMember);
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
