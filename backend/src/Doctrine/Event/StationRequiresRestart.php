<?php

declare(strict_types=1);

namespace App\Doctrine\Event;

use App\Entity\Attributes\AuditIgnore;
use App\Entity\Enums\AuditLogOperations;
use App\Entity\Enums\PlaylistRemoteTypes;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Station;
use App\Entity\StationHlsStream;
use App\Entity\StationMount;
use App\Entity\StationPlaylist;
use App\Entity\StationRemote;
use App\Entity\StationSchedule;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use ReflectionObject;

/**
 * A hook into Doctrine's event listener to mark a station as
 * needing restart if one of its related entities is changed.
 */
final class StationRequiresRestart implements EventSubscriber
{
    /**
     * @inheritDoc
     */
    public function getSubscribedEvents(): array
    {
        return [
            Events::onFlush,
        ];
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $collectionsToCheck = [
            [
                AuditLogOperations::Insert,
                $uow->getScheduledEntityInsertions(),
            ],
            [
                AuditLogOperations::Update,
                $uow->getScheduledEntityUpdates(),
            ],
            [
                AuditLogOperations::Delete,
                $uow->getScheduledEntityDeletions(),
            ],
        ];

        $stationsToRestart = [];

        foreach ($collectionsToCheck as [$changeType, $collection]) {
            foreach ($collection as $entity) {
                if (
                    ($entity instanceof StationMount)
                    || ($entity instanceof StationHlsStream)
                    || ($entity instanceof StationRemote && $entity->isEditable())
                    || (
                        $entity instanceof StationPlaylist
                        && (
                            $entity->station->backend_config->use_manual_autodj
                            || $this->isRemoteStreamPlaylist($entity)
                        )
                    )
                    || (
                        $entity instanceof StationSchedule
                        && null !== $entity->playlist
                        && $this->isRemoteStreamPlaylist($entity->playlist)
                    )
                ) {
                    if (AuditLogOperations::Update === $changeType) {
                        $changes = $uow->getEntityChangeSet($entity);

                        // Look for the @AuditIgnore annotation on a property.
                        $classReflection = new ReflectionObject($entity);
                        foreach ($changes as $changeField => $changeset) {
                            $ignoreAttr = $classReflection->getProperty($changeField)->getAttributes(
                                AuditIgnore::class
                            );
                            if (!empty($ignoreAttr)) {
                                unset($changes[$changeField]);
                            }
                        }

                        if (empty($changes)) {
                            continue;
                        }
                    }

                    $station = ($entity instanceof StationSchedule)
                        ? $entity->playlist?->station
                        : $entity->station;

                    if (null !== $station && $station->hasLocalServices()) {
                        $stationsToRestart[$station->id] = $station;
                    }
                }
            }
        }

        if (count($stationsToRestart) > 0) {
            foreach ($stationsToRestart as $station) {
                $station->needs_restart = true;
                $em->persist($station);

                $stationMeta = $em->getClassMetadata(Station::class);
                $uow->recomputeSingleEntityChangeSet($stationMeta, $station);
            }
        }
    }

    /**
     * Remote Stream playlists (Remote URL of type "stream") are wired directly
     * into the Liquidsoap configuration and are not handled by the PHP AutoDJ.
     * They therefore require a configuration reload whenever they -- or their
     * schedules -- change, even when Manual AutoDJ is disabled.
     */
    private function isRemoteStreamPlaylist(StationPlaylist $playlist): bool
    {
        return PlaylistSources::RemoteUrl === $playlist->source
            && PlaylistRemoteTypes::Stream === $playlist->remote_type;
    }
}
