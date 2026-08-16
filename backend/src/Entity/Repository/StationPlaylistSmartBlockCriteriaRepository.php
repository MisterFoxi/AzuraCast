<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Doctrine\Repository;
use App\Entity\Enums\SmartBlockLimitType;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistSmartBlockCriteria;
use App\Radio\SmartBlock\SmartBlockCriteriaExpressionBuilder;

/** @extends Repository<StationPlaylistSmartBlockCriteria> */
final class StationPlaylistSmartBlockCriteriaRepository extends Repository
{
    protected string $entityClass = StationPlaylistSmartBlockCriteria::class;

    public function __construct(
        private readonly SmartBlockCriteriaExpressionBuilder $expressionBuilder
    ) {
    }

    /** @return list<StationMedia> */
    public function getMatchingMedia(StationPlaylist $playlist): array
    {
        $expression = $this->expressionBuilder->build(
            $playlist->smart_block_criteria,
            $playlist->smart_block_match_type
        );

        if (null === $expression) {
            return [];
        }

        $queryBuilder = $this->em->createQueryBuilder()
            ->select('sm')
            ->from(StationMedia::class, 'sm')
            ->where('sm.storage_location = :storageLocation')
            ->andWhere('sm.do_not_play = false')
            ->andWhere($expression['where'])
            ->setParameter('storageLocation', $playlist->station->media_storage_location);

        foreach ($playlist->smart_block_sort->getOrderBy() as $index => $orderBy) {
            if (0 === $index) {
                $queryBuilder->orderBy($orderBy['expression'], $orderBy['direction']);
            } else {
                $queryBuilder->addOrderBy($orderBy['expression'], $orderBy['direction']);
            }
        }

        foreach ($expression['parameters'] as $name => $value) {
            $queryBuilder->setParameter($name, $value);
        }

        $limit = $playlist->smart_block_limit;
        if (null !== $limit && SmartBlockLimitType::Tracks === $playlist->smart_block_limit_type) {
            $queryBuilder->setMaxResults($limit);
        }

        /** @var list<StationMedia> $results */
        $results = $queryBuilder->getQuery()->getResult();

        if (null === $limit || SmartBlockLimitType::Duration !== $playlist->smart_block_limit_type) {
            return $results;
        }

        return $this->limitByDuration($results, $limit);
    }

    /**
     * @param list<StationMedia> $media
     * @return list<StationMedia>
     */
    public function limitByDuration(array $media, int $maximumSeconds): array
    {
        $selected = [];
        $totalSeconds = 0.0;

        foreach ($media as $item) {
            if (($totalSeconds + $item->length) > $maximumSeconds) {
                continue;
            }

            $selected[] = $item;
            $totalSeconds += $item->length;
        }

        return $selected;
    }
}
