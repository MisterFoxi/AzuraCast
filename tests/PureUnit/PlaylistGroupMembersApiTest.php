<?php

declare(strict_types=1);

namespace PureUnit;

use App\Controller\Api\Stations\Playlists\GetGroupMembersAction;
use App\Controller\Api\Stations\Playlists\PutGroupMembersAction;
use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\StationPlaylistGroupMemberRepository;
use App\Entity\Repository\StationPlaylistRepository;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistGroupMember;
use App\Exception\ValidationException;
use App\Http\HttpFactory;
use App\Http\Response;
use App\Http\ServerRequest;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class PlaylistGroupMembersApiTest extends TestCase
{
    private HttpFactory $httpFactory;

    protected function setUp(): void
    {
        $this->httpFactory = new HttpFactory();
    }

    public function testGetReturnsOrderedOccurrenceData(): void
    {
        $station = new Station();
        $group = $this->makePlaylist($station, 10, 'Group', PlaylistSources::Group);
        $playlistA = $this->makePlaylist($station, 20, 'A');
        $playlistB = $this->makePlaylist($station, 30, 'B');
        $members = [
            $this->makeMember($group, $playlistA, 0, 100),
            $this->makeMember($group, $playlistB, 1, 101),
            $this->makeMember($group, $playlistA, 2, 102),
        ];

        [$playlistRepo, $memberRepo] = $this->makeRepositories(
            [10 => $group],
            $members
        );

        $action = new GetGroupMembersAction($playlistRepo, $memberRepo);
        $result = $action(
            $this->makeRequest($station),
            $this->makeResponse(),
            ['id' => '10']
        );

        self::assertSame(
            [
                [
                    'id' => 100,
                    'playlist_id' => 20,
                    'name' => 'A',
                    'position' => 0,
                    'source' => 'songs',
                    'order' => 'shuffle',
                    'consecutive_plays' => 1,
                    'play_full_cycle' => false,
                    'supports_full_cycle' => true,
                ],
                [
                    'id' => 101,
                    'playlist_id' => 30,
                    'name' => 'B',
                    'position' => 1,
                    'source' => 'songs',
                    'order' => 'shuffle',
                    'consecutive_plays' => 1,
                    'play_full_cycle' => false,
                    'supports_full_cycle' => true,
                ],
                [
                    'id' => 102,
                    'playlist_id' => 20,
                    'name' => 'A',
                    'position' => 2,
                    'source' => 'songs',
                    'order' => 'shuffle',
                    'consecutive_plays' => 1,
                    'play_full_cycle' => false,
                    'supports_full_cycle' => true,
                ],
            ],
            json_decode((string)$result->getBody(), true, flags: JSON_THROW_ON_ERROR)
        );
    }

    public function testGetRejectsNonGroupPlaylist(): void
    {
        $station = new Station();
        $playlist = $this->makePlaylist($station, 10, 'Songs');
        [$playlistRepo, $memberRepo] = $this->makeRepositories([10 => $playlist]);

        $action = new GetGroupMembersAction($playlistRepo, $memberRepo);

        $this->expectValidation('This playlist is not a group.');
        $action(
            $this->makeRequest($station),
            $this->makeResponse(),
            ['id' => '10']
        );
    }

    public function testPutAcceptsPerOccurrencePlaybackSettingsAndReturnsNewOrder(): void
    {
        $station = new Station();
        $group = $this->makePlaylist($station, 10, 'Group', PlaylistSources::Group);
        $group->group_next_position = 1;
        $playlistA = $this->makePlaylist($station, 20, 'A');
        $playlistB = $this->makePlaylist($station, 30, 'B');
        $members = [
            $this->makeMember($group, $playlistA, 0, 100),
            $this->makeMember($group, $playlistB, 1, 101),
        ];

        [$playlistRepo, $memberRepo, $em] = $this->makeRepositories(
            [10 => $group, 20 => $playlistA, 30 => $playlistB],
            $members,
            assignPersistedMemberIds: true
        );
        $em
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn(callable $callback): array => $callback());

        $action = new PutGroupMembersAction($playlistRepo, $memberRepo);
        $result = $action(
            $this->makeRequest($station, [
                'members' => [
                    [
                        'playlist_id' => 20,
                        'order' => 'sequential',
                        'consecutive_plays' => 2,
                        'play_full_cycle' => false,
                    ],
                    [
                        'playlist_id' => 30,
                        'order' => 'shuffle',
                        'consecutive_plays' => 1,
                        'play_full_cycle' => true,
                    ],
                    [
                        'playlist_id' => 20,
                        'order' => 'sequential',
                        'consecutive_plays' => 3,
                        'play_full_cycle' => false,
                    ],
                ],
            ]),
            $this->makeResponse(),
            ['id' => '10']
        );

        self::assertSame(
            [
                [
                    'id' => 100,
                    'playlist_id' => 20,
                    'name' => 'A',
                    'position' => 0,
                    'source' => 'songs',
                    'order' => 'sequential',
                    'consecutive_plays' => 2,
                    'play_full_cycle' => false,
                    'supports_full_cycle' => true,
                ],
                [
                    'id' => 101,
                    'playlist_id' => 30,
                    'name' => 'B',
                    'position' => 1,
                    'source' => 'songs',
                    'order' => 'shuffle',
                    'consecutive_plays' => 1,
                    'play_full_cycle' => true,
                    'supports_full_cycle' => true,
                ],
                [
                    'id' => 1000,
                    'playlist_id' => 20,
                    'name' => 'A',
                    'position' => 2,
                    'source' => 'songs',
                    'order' => 'sequential',
                    'consecutive_plays' => 3,
                    'play_full_cycle' => false,
                    'supports_full_cycle' => true,
                ],
            ],
            json_decode((string)$result->getBody(), true, flags: JSON_THROW_ON_ERROR)
        );
        self::assertSame(0, $group->group_next_position);
        self::assertSame(PlaylistOrders::Sequential, $playlistA->order);
        self::assertSame(PlaylistOrders::Shuffle, $playlistB->order);
    }

    public function testPutRejectsInvalidPlaylistIdWithoutStartingTransaction(): void
    {
        $station = new Station();
        $group = $this->makePlaylist($station, 10, 'Group', PlaylistSources::Group);
        [$playlistRepo, $memberRepo, $em] = $this->makeRepositories(
            [10 => $group],
            useEntityManagerMock: true
        );
        $em->expects(self::never())->method('wrapInTransaction');

        $action = new PutGroupMembersAction($playlistRepo, $memberRepo);

        $this->expectValidation('Every playlist ID must be a positive integer.');
        $action(
            $this->makeRequest($station, ['playlist_ids' => ['20']]),
            $this->makeResponse(),
            ['id' => '10']
        );
    }

    public function testPutRequiresMembers(): void
    {
        $station = new Station();
        $group = $this->makePlaylist($station, 10, 'Group', PlaylistSources::Group);
        [$playlistRepo, $memberRepo] = $this->makeRepositories([10 => $group]);

        $action = new PutGroupMembersAction($playlistRepo, $memberRepo);

        $this->expectValidation('members is required.');
        $action(
            $this->makeRequest($station, []),
            $this->makeResponse(),
            ['id' => '10']
        );
    }

    public function testPutRequiresPlaylistIdsToBeAList(): void
    {
        $station = new Station();
        $group = $this->makePlaylist($station, 10, 'Group', PlaylistSources::Group);
        [$playlistRepo, $memberRepo] = $this->makeRepositories([10 => $group]);

        $action = new PutGroupMembersAction($playlistRepo, $memberRepo);

        $this->expectValidation('playlist_ids must be a list.');
        $action(
            $this->makeRequest($station, ['playlist_ids' => [1 => 20]]),
            $this->makeResponse(),
            ['id' => '10']
        );
    }

    public function testPutRejectsPlaylistFromAnotherStation(): void
    {
        $station = new Station();
        $otherStation = new Station();
        $group = $this->makePlaylist($station, 10, 'Group', PlaylistSources::Group);
        $foreignPlaylist = $this->makePlaylist($otherStation, 20, 'Foreign');
        [$playlistRepo, $memberRepo] = $this->makeRepositories([
            10 => $group,
            20 => $foreignPlaylist,
        ]);

        $action = new PutGroupMembersAction($playlistRepo, $memberRepo);

        $this->expectValidation('A playlist ID is invalid for this station.');
        $action(
            $this->makeRequest($station, ['playlist_ids' => [20]]),
            $this->makeResponse(),
            ['id' => '10']
        );
    }

    public function testPutRejectsSelfReference(): void
    {
        $station = new Station();
        $group = $this->makePlaylist($station, 10, 'Group', PlaylistSources::Group);
        [$playlistRepo, $memberRepo] = $this->makeRepositories([10 => $group]);

        $action = new PutGroupMembersAction($playlistRepo, $memberRepo);

        $this->expectValidation('A playlist group cannot contain itself.');
        $action(
            $this->makeRequest($station, ['playlist_ids' => [10]]),
            $this->makeResponse(),
            ['id' => '10']
        );
    }

    public function testPutRejectsNestedGroup(): void
    {
        $station = new Station();
        $group = $this->makePlaylist($station, 10, 'Group', PlaylistSources::Group);
        $nestedGroup = $this->makePlaylist($station, 20, 'Nested', PlaylistSources::Group);
        [$playlistRepo, $memberRepo] = $this->makeRepositories([
            10 => $group,
            20 => $nestedGroup,
        ]);

        $action = new PutGroupMembersAction($playlistRepo, $memberRepo);

        $this->expectValidation('Nested playlist groups are not supported.');
        $action(
            $this->makeRequest($station, ['playlist_ids' => [20]]),
            $this->makeResponse(),
            ['id' => '10']
        );
    }

    public function testPutRejectsFullCycleForRandomPlaylist(): void
    {
        $station = new Station();
        $group = $this->makePlaylist($station, 10, 'Group', PlaylistSources::Group);
        $randomPlaylist = $this->makePlaylist($station, 20, 'Random');
        [$playlistRepo, $memberRepo] = $this->makeRepositories([
            10 => $group,
            20 => $randomPlaylist,
        ]);

        $action = new PutGroupMembersAction($playlistRepo, $memberRepo);

        $this->expectValidation(
            'play_full_cycle is only supported for Sequential or Shuffle song playlists.'
        );
        $action(
            $this->makeRequest($station, [
                'members' => [[
                    'playlist_id' => 20,
                    'order' => 'random',
                    'consecutive_plays' => 1,
                    'play_full_cycle' => true,
                ]],
            ]),
            $this->makeResponse(),
            ['id' => '10']
        );
    }

    public function testPutRejectsConflictingOrdersForRepeatedPlaylist(): void
    {
        $station = new Station();
        $group = $this->makePlaylist($station, 10, 'Group', PlaylistSources::Group);
        $playlist = $this->makePlaylist($station, 20, 'Songs');
        [$playlistRepo, $memberRepo] = $this->makeRepositories([
            10 => $group,
            20 => $playlist,
        ]);

        $action = new PutGroupMembersAction($playlistRepo, $memberRepo);

        $this->expectValidation('All occurrences of the same playlist must use the same order.');
        $action(
            $this->makeRequest($station, [
                'members' => [
                    [
                        'playlist_id' => 20,
                        'order' => 'sequential',
                        'consecutive_plays' => 1,
                        'play_full_cycle' => false,
                    ],
                    [
                        'playlist_id' => 20,
                        'order' => 'random',
                        'consecutive_plays' => 1,
                        'play_full_cycle' => false,
                    ],
                ],
            ]),
            $this->makeResponse(),
            ['id' => '10']
        );
    }

    /**
     * @param array<int, StationPlaylist> $playlistsById
     * @param list<StationPlaylistGroupMember> $members
     * @return array{
     *     StationPlaylistRepository,
     *     StationPlaylistGroupMemberRepository,
     *     ReloadableEntityManagerInterface
     * }
     */
    private function makeRepositories(
        array $playlistsById,
        array $members = [],
        bool $assignPersistedMemberIds = false,
        bool $useEntityManagerMock = false
    ): array {
        $playlistObjectRepository = $this->createStub(EntityRepository::class);
        $memberObjectRepository = $this->createStub(EntityRepository::class);
        $memberObjectRepository->method('findBy')->willReturn($members);

        $em = $useEntityManagerMock
            ? $this->createMock(ReloadableEntityManagerInterface::class)
            : $this->createStub(ReloadableEntityManagerInterface::class);
        $em
            ->method('getRepository')
            ->willReturnCallback(static function (string $entityClass) use (
                $playlistObjectRepository,
                $memberObjectRepository
            ): EntityRepository {
                return StationPlaylist::class === $entityClass
                    ? $playlistObjectRepository
                    : $memberObjectRepository;
            });
        $em
            ->method('find')
            ->willReturnCallback(static fn(string $entityClass, int|string $id): ?StationPlaylist =>
                StationPlaylist::class === $entityClass
                    ? ($playlistsById[(int)$id] ?? null)
                    : null
            );
        if ($assignPersistedMemberIds) {
            $nextId = 1000;
            $em
                ->method('persist')
                ->willReturnCallback(function (object $entity) use (&$nextId): void {
                    if ($entity instanceof StationPlaylistGroupMember) {
                        $id = new ReflectionProperty($entity, 'id');
                        if (!$id->isInitialized($entity)) {
                            $id->setValue($entity, $nextId++);
                        }
                    }
                });
        }

        $playlistRepo = new StationPlaylistRepository();
        $playlistRepo->setEntityManager($em);

        $memberRepo = new StationPlaylistGroupMemberRepository();
        $memberRepo->setEntityManager($em);

        return [$playlistRepo, $memberRepo, $em];
    }

    /** @param array<mixed>|null $body */
    private function makeRequest(Station $station, ?array $body = null): ServerRequest
    {
        /** @var ServerRequest $request */
        $request = $this->httpFactory
            ->createServerRequest('GET', '/')
            ->withAttribute(ServerRequest::ATTR_STATION, $station);

        if (null !== $body) {
            /** @var ServerRequest $request */
            $request = $request->withParsedBody($body);
        }

        return $request;
    }

    private function makeResponse(): Response
    {
        /** @var Response $response */
        $response = $this->httpFactory->createResponse();
        return $response;
    }

    private function expectValidation(string $message): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessage($message);
    }

    private function makePlaylist(
        Station $station,
        int $id,
        string $name,
        PlaylistSources $source = PlaylistSources::Songs
    ): StationPlaylist {
        $playlist = new StationPlaylist($station);
        $playlist->name = $name;
        $playlist->source = $source;
        $this->setId($playlist, $id);

        return $playlist;
    }

    private function makeMember(
        StationPlaylist $group,
        StationPlaylist $playlist,
        int $position,
        int $id
    ): StationPlaylistGroupMember {
        $member = new StationPlaylistGroupMember($group, $playlist, $position);
        $this->setId($member, $id);

        return $member;
    }

    private function setId(object $entity, int $id): void
    {
        (new ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
