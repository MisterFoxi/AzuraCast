<?php

declare(strict_types=1);

namespace PureUnit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\StationPlaylistGroupMemberRepository;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistGroupMember;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class PlaylistGroupMemberRepositoryTest extends TestCase
{
    public function testSetMembersPreservesOccurrenceIdsAndRemovesOnlySurplus(): void
    {
        $station = new Station();
        $group = $this->makePlaylist($station, 10, 'Group', PlaylistSources::Group);
        $group->group_next_position = 2;
        $playlistA = $this->makePlaylist($station, 20, 'A');
        $playlistB = $this->makePlaylist($station, 30, 'B');

        $firstA = $this->makeMember($group, $playlistA, 0, 100);
        $onlyB = $this->makeMember($group, $playlistB, 1, 101);
        $secondA = $this->makeMember($group, $playlistA, 2, 102);

        $objectRepository = $this->createMock(EntityRepository::class);
        $objectRepository
            ->expects(self::once())
            ->method('findBy')
            ->with(
                ['group' => $group],
                ['position' => 'ASC', 'id' => 'ASC']
            )
            ->willReturn([$firstA, $onlyB, $secondA]);

        $em = $this->createMock(ReloadableEntityManagerInterface::class);
        $em->method('getRepository')->willReturn($objectRepository);
        $em
            ->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn(callable $callback): array => $callback());
        $em
            ->expects(self::once())
            ->method('remove')
            ->with($onlyB);
        $em
            ->expects(self::once())
            ->method('persist')
            ->with($group);
        $em->expects(self::exactly(2))->method('flush');

        $repository = new StationPlaylistGroupMemberRepository();
        $repository->setEntityManager($em);

        $members = $repository->setMembers($group, [$playlistA, $playlistA]);

        self::assertSame([$firstA, $secondA], $members);
        self::assertSame([100, 102], array_map(
            static fn(StationPlaylistGroupMember $member): int => $member->id,
            $members
        ));
        self::assertSame([0, 1], array_map(
            static fn(StationPlaylistGroupMember $member): int => $member->position,
            $members
        ));
        self::assertSame(0, $group->group_next_position);
    }

    public function testSetMembersCreatesOnlyMissingOccurrences(): void
    {
        $station = new Station();
        $group = $this->makePlaylist($station, 10, 'Group', PlaylistSources::Group);
        $playlistA = $this->makePlaylist($station, 20, 'A');

        $firstA = $this->makeMember($group, $playlistA, 0, 100);

        $objectRepository = $this->createStub(EntityRepository::class);
        $objectRepository->method('findBy')->willReturn([$firstA]);

        $persisted = [];
        $em = $this->createMock(ReloadableEntityManagerInterface::class);
        $em->method('getRepository')->willReturn($objectRepository);
        $em
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn(callable $callback): array => $callback());
        $em
            ->expects(self::exactly(2))
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $em->expects(self::never())->method('remove');
        $em->expects(self::exactly(2))->method('flush');

        $repository = new StationPlaylistGroupMemberRepository();
        $repository->setEntityManager($em);

        $members = $repository->setMembers($group, [$playlistA, $playlistA]);

        self::assertCount(2, $members);
        self::assertSame($firstA, $members[0]);
        self::assertNotSame($firstA, $members[1]);
        self::assertSame($playlistA, $members[1]->playlist);
        self::assertSame([0, 1], array_map(
            static fn(StationPlaylistGroupMember $member): int => $member->position,
            $members
        ));
        self::assertSame([$members[1], $group], $persisted);
    }

    public function testSetMembersRejectsMoreThanMaximumBeforeStartingTransaction(): void
    {
        $station = new Station();
        $group = $this->makePlaylist($station, 10, 'Group', PlaylistSources::Group);
        $playlist = $this->makePlaylist($station, 20, 'A');

        $objectRepository = $this->createStub(EntityRepository::class);
        $em = $this->createMock(ReloadableEntityManagerInterface::class);
        $em->method('getRepository')->willReturn($objectRepository);
        $em->expects(self::never())->method('wrapInTransaction');

        $repository = new StationPlaylistGroupMemberRepository();
        $repository->setEntityManager($em);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot contain more than 32768 members');

        $repository->setMembers(
            $group,
            array_fill(0, StationPlaylistGroupMemberRepository::MAX_MEMBERS + 1, $playlist)
        );
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
