<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\MediaGenres;

use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Media\MediaGenreOptions;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[
    OA\Get(
        path: '/station/{station_id}/media-genres',
        operationId: 'getMediaGenres',
        summary: 'List active canonical and custom media genres.',
        tags: [OpenApi::TAG_STATIONS_MEDIA],
        parameters: [new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED)],
        responses: [
            new OpenApi\Response\Success(),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\GenericError(),
        ]
    )
]
final readonly class GetMediaGenresAction implements SingleActionInterface
{
    public function __construct(private MediaGenreOptions $genreOptions)
    {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $storageLocationId = $request->getStation()->media_storage_location->id;

        return $response->withJson([
            'rows' => $this->genreOptions->getActive($storageLocationId),
        ]);
    }
}
