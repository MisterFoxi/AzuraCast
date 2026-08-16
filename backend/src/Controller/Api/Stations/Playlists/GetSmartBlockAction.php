<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Playlists;

use App\Controller\SingleActionInterface;
use App\Entity\CustomField;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\StationPlaylistRepository;
use App\Entity\Repository\StationPlaylistSmartBlockCriteriaRepository;
use App\Entity\StationMedia;
use App\Entity\StationMediaCategory;
use App\Exception\ValidationException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/playlist/{id}/smart-block',
    operationId: 'getStationPlaylistSmartBlock',
    summary: 'Get Smart Block criteria and a preview of matching media.',
    tags: [OpenApi::TAG_STATIONS_PLAYLISTS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        new OA\Parameter(
            name: 'id',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'integer', format: 'int64')
        ),
    ],
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\NotFound(),
        new OpenApi\Response\GenericError(),
    ]
)]
final readonly class GetSmartBlockAction implements SingleActionInterface
{
    private const int PREVIEW_SAMPLE_SIZE = 25;

    public function __construct(
        private StationPlaylistRepository $playlistRepo,
        private StationPlaylistSmartBlockCriteriaRepository $criteriaRepo,
        private EntityManagerInterface $em
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $playlist = $this->playlistRepo->requireForStation((string)$params['id'], $station);

        if (PlaylistSources::Songs !== $playlist->source) {
            throw new ValidationException('Smart Blocks are only available for song playlists.');
        }

        $matchingMedia = $playlist->is_smart_block && $playlist->smart_block_criteria->count() > 0
            ? $this->criteriaRepo->getMatchingMedia($playlist)
            : [];

        $categories = $this->em->createQueryBuilder()
            ->select(['category.id', 'category.name'])
            ->from(StationMediaCategory::class, 'category')
            ->where('category.station = :station')
            ->setParameter('station', $station)
            ->orderBy('category.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $customFields = $this->em->createQueryBuilder()
            ->select(['field.id', 'field.name', 'field.short_name'])
            ->from(CustomField::class, 'field')
            ->orderBy('field.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $response->withJson([
            'id' => $playlist->id,
            'name' => $playlist->name,
            'is_smart_block' => $playlist->is_smart_block,
            'smart_block_type' => $playlist->smart_block_type->value,
            'smart_block_match_type' => $playlist->smart_block_match_type->value,
            'smart_block_limit' => $playlist->smart_block_limit,
            'smart_block_limit_type' => $playlist->smart_block_limit_type->value,
            'smart_block_sort' => $playlist->smart_block_sort->value,
            'criteria' => $playlist->smart_block_criteria->toArray(),
            'current_member_count' => $playlist->media_items->count(),
            'matching_count' => count($matchingMedia),
            'matching_duration_seconds' => array_sum(array_map(
                static fn(StationMedia $media): float => $media->length,
                $matchingMedia
            )),
            'preview' => array_map(
                static fn(StationMedia $media): array => [
                    'id' => $media->id,
                    'title' => $media->title,
                    'artist' => $media->artist,
                    'album' => $media->album,
                    'genre' => $media->genre,
                    'length' => $media->length,
                ],
                array_slice($matchingMedia, 0, self::PREVIEW_SAMPLE_SIZE)
            ),
            'available_categories' => $categories,
            'available_custom_fields' => $customFields,
        ]);
    }
}
