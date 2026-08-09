<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Playlists;

use App\Controller\SingleActionInterface;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\StationPlaylistGroupMemberRepository;
use App\Entity\Repository\StationPlaylistRepository;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistGroupMember;
use App\Exception\ValidationException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Put(
    path: '/station/{station_id}/playlist/{id}/members',
    operationId: 'putPlaylistGroupMembers',
    summary: 'Replace the ordered members of a playlist group.',
    tags: [OpenApi::TAG_STATIONS_PLAYLISTS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        new OA\Parameter(
            name: 'id',
            description: 'Playlist group ID',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'integer', format: 'int64')
        ),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['playlist_ids'],
            properties: [
                new OA\Property(
                    property: 'playlist_ids',
                    type: 'array',
                    items: new OA\Items(type: 'integer', format: 'int64')
                ),
            ],
            type: 'object'
        )
    ),
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\NotFound(),
        new OpenApi\Response\GenericError(),
    ]
)]
final readonly class PutGroupMembersAction implements SingleActionInterface
{
    public function __construct(
        private StationPlaylistRepository $playlistRepo,
        private StationPlaylistGroupMemberRepository $memberRepo,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        /** @var string $id */
        $id = $params['id'];
        $station = $request->getStation();

        $group = $this->playlistRepo->requireForStation($id, $station);
        if (PlaylistSources::Group !== $group->source) {
            throw new ValidationException('This playlist is not a group.');
        }

        $body = $request->getParsedBody();
        if (!is_array($body) || !array_key_exists('playlist_ids', $body)) {
            throw new ValidationException('playlist_ids is required.');
        }

        $playlistIds = $body['playlist_ids'];
        if (!is_array($playlistIds) || !array_is_list($playlistIds)) {
            throw new ValidationException('playlist_ids must be a list.');
        }
        if (count($playlistIds) > StationPlaylistGroupMemberRepository::MAX_MEMBERS) {
            throw new ValidationException('A playlist group cannot contain more than 32768 members.');
        }

        $playlists = [];
        foreach ($playlistIds as $playlistId) {
            if (!is_int($playlistId) || $playlistId <= 0) {
                throw new ValidationException('Every playlist ID must be a positive integer.');
            }

            $playlist = $this->playlistRepo->findForStation($playlistId, $station);
            if (!$playlist instanceof StationPlaylist) {
                throw new ValidationException('A playlist ID is invalid for this station.');
            }
            if ($playlist->id === $group->id) {
                throw new ValidationException('A playlist group cannot contain itself.');
            }
            if (PlaylistSources::Group === $playlist->source) {
                throw new ValidationException('Nested playlist groups are not supported.');
            }

            $playlists[] = $playlist;
        }

        $members = $this->memberRepo->setMembers($group, $playlists);

        return $response->withJson(array_map(
            static fn(StationPlaylistGroupMember $member): array => [
                'id' => $member->id,
                'playlist_id' => $member->playlist->id,
                'name' => $member->playlist->name,
                'position' => $member->position,
            ],
            $members
        ));
    }
}
