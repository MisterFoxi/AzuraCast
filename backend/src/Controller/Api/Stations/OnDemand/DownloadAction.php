<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\OnDemand;

use App\Controller\SingleActionInterface;
use App\Exception\NotFoundException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/ondemand/download/{media_id}',
    operationId: 'getStationOnDemandDownload',
    summary: 'Legacy on-demand download route (downloads are disabled).',
    security: [],
    tags: [OpenApi::TAG_PUBLIC_STATIONS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        new OA\Parameter(
            name: 'media_id',
            description: 'The media unique ID to download.',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'string'),
        ),
    ],
    responses: [
        new OpenApi\Response\NotFound(),
    ]
)]
final readonly class DownloadAction implements SingleActionInterface
{
    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        // Keep the historical route for compatibility, but never expose the
        // source media file through a public, unauthenticated endpoint.
        throw NotFoundException::file();
    }
}
