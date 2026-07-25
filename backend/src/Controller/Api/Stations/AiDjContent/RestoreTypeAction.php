<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiDjContent;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\AiDjContent;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Post(
    path: '/station/{station_id}/ai-dj-content/restore-type',
    operationId: 'restoreStationAiDjContentType',
    summary: 'Restore a previously removed optional AI DJ content category tab.',
    tags: [OpenApi::TAG_STATIONS_BROADCASTING],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                'type' => new OA\Property(description: 'Content type slug to restore', type: 'string'),
            ],
            required: ['type']
        )
    ),
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\GenericError(),
    ]
)]
final class RestoreTypeAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $body = $request->getParsedBody();

        $type = is_array($body) ? trim((string)($body['type'] ?? '')) : '';
        if ($type === '' || !preg_match('/^[a-z][a-z0-9_]{1,49}$/', $type)) {
            return $response->withStatus(400)->withJson([
                'success' => false,
                'message' => 'Invalid "type".',
            ]);
        }

        if (AiDjContent::isRequiredType($type)) {
            return $response->withJson([
                'success' => true,
                'restored' => false,
                'message' => 'Required categories are always present.',
            ]);
        }

        $backendConfig = $station->backend_config;
        $removed = $backendConfig->ai_dj_removed_content_types;
        $filtered = array_values(array_filter(
            $removed,
            static fn(string $slug): bool => $slug !== $type
        ));

        if ($filtered !== $removed) {
            $backendConfig->ai_dj_removed_content_types = $filtered;
            $station->backend_config = $backendConfig;
            $this->em->persist($station);
            $this->em->flush();
        }

        return $response->withJson([
            'success' => true,
            'restored' => $filtered !== $removed,
            'type' => $type,
        ]);
    }
}
