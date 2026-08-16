<?php

declare(strict_types=1);

namespace PureUnit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\StorageLocationAdapters;
use App\Entity\Enums\StorageLocationTypes;
use App\Entity\Repository\StationPlaylistMediaRepository;
use App\Entity\Repository\StationPlaylistSmartBlockCriteriaRepository;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistMedia;
use App\Entity\StorageLocation;
use App\Radio\SmartBlock\SmartBlockCriteriaExpressionBuilder;
use App\Radio\SmartBlock\SmartBlockSynchronizer;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class SmartBlockSynchronizerTest extends TestCase
{
    public function testReconcileAddsAndRemovesOnlyTheDifference(): void
    {
        $playlist = $this->createPlaylist();
        $kept = $this->createMedia(1, 'kept.mp3');
        $removed = $this->createMedia(2, 'removed.mp3');
        $added = $this->createMedia(3, 'added.mp3');
        $keptMembership = new StationPlaylistMedia($playlist, $kept);
        $keptMembership->weight = 1;
        $removedMembership = new StationPlaylistMedia($playlist, $removed);

        $objectRepository = $this->createMock(EntityRepository::class);
        $objectRepository
            ->expects(self::once())
            ->method('findBy')
            ->with(
                ['playlist' => $playlist, 'folder' => null],
                ['weight' => 'ASC']
            )
            ->willReturn([$keptMembership, $removedMembership]);
        $objectRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with([
                'media' => $added,
                'playlist' => $playlist,
                'folder' => null,
            ])
            ->willReturn(null);

        $clearQueueQuery = $this->createMock(Query::class);
        $clearQueueQuery->expects(self::exactly(2))->method('setParameter')->willReturnSelf();
        $clearQueueQuery->expects(self::once())->method('execute')->willReturn(1);

        $persisted = null;
        $entityManager = $this->createMock(ReloadableEntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($objectRepository);
        $entityManager
            ->expects(self::once())
            ->method('createQuery')
            ->willReturn($clearQueueQuery);
        $entityManager
            ->expects(self::once())
            ->method('remove')
            ->with($removedMembership);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted = $entity;
            });
        $entityManager->expects(self::once())->method('flush');

        $synchronizer = $this->createSynchronizer($entityManager);
        $result = $synchronizer->reconcile($playlist, [$kept, $added, $added]);

        self::assertSame(
            [
                'matched' => 2,
                'added' => 1,
                'removed' => 1,
                'unchanged' => 1,
                'changed' => true,
            ],
            $result
        );
        self::assertInstanceOf(StationPlaylistMedia::class, $persisted);
        self::assertSame($added, $persisted->media);
        self::assertSame(2, $persisted->weight);
    }

    public function testReconcileIsIdempotentWhenMembershipsAlreadyMatch(): void
    {
        $playlist = $this->createPlaylist();
        $first = $this->createMedia(1, 'first.mp3');
        $second = $this->createMedia(2, 'second.mp3');

        $objectRepository = $this->createMock(EntityRepository::class);
        $firstMembership = new StationPlaylistMedia($playlist, $first);
        $firstMembership->weight = 1;
        $secondMembership = new StationPlaylistMedia($playlist, $second);
        $secondMembership->weight = 2;

        $objectRepository
            ->expects(self::once())
            ->method('findBy')
            ->willReturn([$firstMembership, $secondMembership]);
        $objectRepository->expects(self::never())->method('findOneBy');

        $entityManager = $this->createMock(ReloadableEntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($objectRepository);
        $entityManager->expects(self::never())->method('createQuery');
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('remove');
        $entityManager->expects(self::never())->method('flush');

        $result = $this->createSynchronizer($entityManager)->reconcile(
            $playlist,
            [$first, $second]
        );

        self::assertSame(
            [
                'matched' => 2,
                'added' => 0,
                'removed' => 0,
                'unchanged' => 2,
                'changed' => false,
            ],
            $result
        );
    }

    public function testReconcileRemovesDuplicateDirectMemberships(): void
    {
        $playlist = $this->createPlaylist();
        $media = $this->createMedia(1, 'duplicate.mp3');
        $keptMembership = new StationPlaylistMedia($playlist, $media);
        $keptMembership->weight = 1;
        $duplicateMembership = new StationPlaylistMedia($playlist, $media);

        $objectRepository = $this->createMock(EntityRepository::class);
        $objectRepository
            ->expects(self::once())
            ->method('findBy')
            ->willReturn([$keptMembership, $duplicateMembership]);
        $objectRepository->expects(self::never())->method('findOneBy');

        $clearQueueQuery = $this->createMock(Query::class);
        $clearQueueQuery->method('setParameter')->willReturnSelf();
        $clearQueueQuery->expects(self::once())->method('execute')->willReturn(1);

        $entityManager = $this->createMock(ReloadableEntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($objectRepository);
        $entityManager->expects(self::once())->method('createQuery')->willReturn($clearQueueQuery);
        $entityManager
            ->expects(self::once())
            ->method('remove')
            ->with($duplicateMembership);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $result = $this->createSynchronizer($entityManager)->reconcile($playlist, [$media]);

        self::assertSame(
            [
                'matched' => 1,
                'added' => 0,
                'removed' => 1,
                'unchanged' => 1,
                'changed' => true,
            ],
            $result
        );
    }

    public function testReconcileRecalculatesWeightsWhenSortChanges(): void
    {
        $playlist = $this->createPlaylist();
        $first = $this->createMedia(1, 'first.mp3');
        $second = $this->createMedia(2, 'second.mp3');

        $firstMembership = new StationPlaylistMedia($playlist, $first);
        $firstMembership->weight = 2;
        $secondMembership = new StationPlaylistMedia($playlist, $second);
        $secondMembership->weight = 1;

        $objectRepository = $this->createMock(EntityRepository::class);
        $objectRepository
            ->expects(self::once())
            ->method('findBy')
            ->willReturn([$secondMembership, $firstMembership]);

        $entityManager = $this->createMock(ReloadableEntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($objectRepository);
        $entityManager
            ->expects(self::exactly(2))
            ->method('persist')
            ->with(self::isInstanceOf(StationPlaylistMedia::class));
        $entityManager->expects(self::once())->method('flush');

        $result = $this->createSynchronizer($entityManager)->reconcile(
            $playlist,
            [$first, $second]
        );

        self::assertSame(1, $firstMembership->weight);
        self::assertSame(2, $secondMembership->weight);
        self::assertTrue($result['changed']);
        self::assertSame(2, $result['unchanged']);
    }

    private function createSynchronizer(
        ReloadableEntityManagerInterface $entityManager
    ): SmartBlockSynchronizer {
        $queueRepository = new StationQueueRepository();
        $queueRepository->setEntityManager($entityManager);

        $playlistMediaRepository = new StationPlaylistMediaRepository($queueRepository);
        $playlistMediaRepository->setEntityManager($entityManager);

        $criteriaRepository = new StationPlaylistSmartBlockCriteriaRepository(
            new SmartBlockCriteriaExpressionBuilder()
        );

        return new SmartBlockSynchronizer($criteriaRepository, $playlistMediaRepository);
    }

    private function createPlaylist(): StationPlaylist
    {
        $playlist = new StationPlaylist(new Station());
        $playlist->name = 'Smart Block';
        $playlist->order = PlaylistOrders::Sequential;
        $playlist->is_smart_block = true;
        $this->setId($playlist, 10);

        return $playlist;
    }

    private function createMedia(int $id, string $path): StationMedia
    {
        $storageLocation = new StorageLocation(
            StorageLocationTypes::StationMedia,
            StorageLocationAdapters::Local
        );
        $media = new StationMedia($storageLocation, $path);
        $this->setId($media, $id);

        return $media;
    }

    private function setId(object $entity, int $id): void
    {
        (new ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
