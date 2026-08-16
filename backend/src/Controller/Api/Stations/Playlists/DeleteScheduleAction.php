<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Playlists;

use App\Controller\SingleActionInterface;
use App\Entity\Api\Status;
use App\Entity\Repository\StationPlaylistRepository;
use App\Entity\Repository\StationScheduleRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Delete(
    path: '/station/{station_id}/playlist/{id}/schedule/{schedule_id}',
    operationId: 'deletePlaylistSchedule',
    summary: 'Delete one playlist schedule item.',
    tags: [OpenApi::TAG_STATIONS_PLAYLISTS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        new OA\Parameter(
            name: 'id',
            description: 'Playlist ID',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'integer', format: 'int64')
        ),
        new OA\Parameter(
            name: 'schedule_id',
            description: 'Schedule ID',
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
final readonly class DeleteScheduleAction implements SingleActionInterface
{
    public function __construct(
        private StationPlaylistRepository $playlistRepo,
        private StationScheduleRepository $scheduleRepo,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params,
    ): ResponseInterface {
        $playlist = $this->playlistRepo->requireForStation(
            (int)$params['id'],
            $request->getStation()
        );

        $this->scheduleRepo->deleteScheduleItem(
            $playlist,
            (int)$params['schedule_id']
        );

        return $response->withJson(new Status(true, __('Event deleted.')));
    }
}
