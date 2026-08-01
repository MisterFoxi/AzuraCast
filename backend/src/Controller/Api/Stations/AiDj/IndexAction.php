<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiDj;

use App\Controller\SingleActionInterface;
use App\Entity\Repository\AiDjRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Service\AiDjGenerator;
use App\Service\AiDjScheduler;
use App\Service\AiNewsGenerator;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/ai-dj',
    operationId: 'getStationAiDjList',
    summary: 'List all AI DJs for a station.',
    tags: [OpenApi::TAG_STATIONS_BROADCASTING],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
    ],
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\NotFound(),
    ]
)]
final class IndexAction implements SingleActionInterface
{
    public function __construct(
        private readonly AiDjRepository $aiDjRepository,
        private readonly AiDjScheduler $scheduler,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $djList = $this->aiDjRepository->findByStation($station->id);

        $result = array_map(
            static fn(\App\Entity\AiDj $dj): array => $dj->api(),
            $djList
        );

        $kokoroVoices = array_map(
            static fn(array $v): array => [
                'label' => $v['name'],
                'path' => $v['id'],
            ],
            AiDjGenerator::KOKORO_VOICES
        );

        $piperVoices = AiNewsGenerator::getAvailableVoiceModels();

        $activeDj = $this->scheduler->findActiveDj($station->id);

        return $response->withJson([
            'rows' => $result,
            'voice_options' => array_merge($kokoroVoices, $piperVoices),
            'active_dj_id' => $activeDj?->getId(),
        ]);
    }
}
