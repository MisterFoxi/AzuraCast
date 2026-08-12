<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Playlists;

use App\Controller\SingleActionInterface;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\SmartBlockLimitType;
use App\Entity\Enums\SmartBlockMatchType;
use App\Entity\Enums\SmartBlockType;
use App\Entity\Repository\StationPlaylistRepository;
use App\Entity\StationPlaylistSmartBlockCriteria;
use App\Exception\ValidationException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\SmartBlock\SmartBlockCriteriaPayloadParser;
use App\Radio\SmartBlock\SmartBlockSynchronizerInterface;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Put(
    path: '/station/{station_id}/playlist/{id}/smart-block',
    operationId: 'putStationPlaylistSmartBlock',
    summary: 'Replace Smart Block criteria and synchronize its media membership.',
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
final readonly class PutSmartBlockAction implements SingleActionInterface
{
    public function __construct(
        private StationPlaylistRepository $playlistRepo,
        private SmartBlockCriteriaPayloadParser $criteriaParser,
        private SmartBlockSynchronizerInterface $synchronizer,
        private EntityManagerInterface $em
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $playlist = $this->playlistRepo->requireForStation(
            (string)$params['id'],
            $request->getStation()
        );
        if (PlaylistSources::Songs !== $playlist->source) {
            throw new ValidationException('Smart Blocks are only available for song playlists.');
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ValidationException('A JSON object is required.');
        }

        $enabled = $this->parseBool($body['is_smart_block'] ?? false, 'is_smart_block');
        $type = $this->parseEnum($body['smart_block_type'] ?? SmartBlockType::Dynamic->value, SmartBlockType::class);
        $matchType = $this->parseEnum(
            $body['smart_block_match_type'] ?? SmartBlockMatchType::All->value,
            SmartBlockMatchType::class
        );
        $limitType = $this->parseEnum(
            $body['smart_block_limit_type'] ?? SmartBlockLimitType::Tracks->value,
            SmartBlockLimitType::class
        );
        $limit = $this->parseLimit($body['smart_block_limit'] ?? null);
        $criteria = $this->criteriaParser->parse($playlist, $body['criteria'] ?? null);

        if ($enabled && [] === $criteria) {
            throw new ValidationException('An enabled Smart Block requires at least one criterion.');
        }

        $this->em->wrapInTransaction(function () use (
            $playlist,
            $enabled,
            $type,
            $matchType,
            $limit,
            $limitType,
            $criteria
        ): void {
            $this->em->createQuery(
                'DELETE FROM ' . StationPlaylistSmartBlockCriteria::class . ' criterion '
                . 'WHERE criterion.playlist = :playlist'
            )
                ->setParameter('playlist', $playlist)
                ->execute();

            $playlist->smart_block_criteria->clear();
            foreach ($criteria as $criterion) {
                $playlist->smart_block_criteria->add($criterion);
                $this->em->persist($criterion);
            }

            $playlist->is_smart_block = $enabled;
            $playlist->smart_block_type = $type;
            $playlist->smart_block_match_type = $matchType;
            $playlist->smart_block_limit = $limit;
            $playlist->smart_block_limit_type = $limitType;
            $this->em->persist($playlist);
            $this->em->flush();
        });

        $syncResult = $enabled
            ? $this->synchronizer->synchronize($playlist)
            : ['matched' => 0, 'added' => 0, 'removed' => 0, 'unchanged' => 0, 'changed' => false];

        return $response->withJson([
            'success' => true,
            'matched' => $syncResult['matched'],
            'added' => $syncResult['added'],
            'removed' => $syncResult['removed'],
            'unchanged' => $syncResult['unchanged'],
            'changed' => $syncResult['changed'],
        ]);
    }

    private function parseBool(mixed $value, string $name): bool
    {
        if (!is_bool($value)) {
            throw new ValidationException($name . ' must be a boolean.');
        }

        return $value;
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T
     */
    private function parseEnum(mixed $value, string $enum): \BackedEnum
    {
        if (!is_string($value) || null === ($parsed = $enum::tryFrom($value))) {
            throw new ValidationException('Smart Block option is invalid.');
        }

        return $parsed;
    }

    private function parseLimit(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!is_int($value) || $value <= 0) {
            throw new ValidationException('smart_block_limit must be a positive integer or null.');
        }

        return $value;
    }
}
