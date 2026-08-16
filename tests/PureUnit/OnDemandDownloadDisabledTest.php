<?php

declare(strict_types=1);

namespace PureUnit;

use App\Controller\Api\Stations\OnDemand\DownloadAction;
use App\Exception\NotFoundException;
use App\Http\Response;
use App\Http\ServerRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class OnDemandDownloadDisabledTest extends TestCase
{
    public function testPublicDownloadAlwaysReturnsNotFound(): void
    {
        $request = (new ReflectionClass(ServerRequest::class))->newInstanceWithoutConstructor();
        $response = (new ReflectionClass(Response::class))->newInstanceWithoutConstructor();

        $this->expectException(NotFoundException::class);
        $this->expectExceptionCode(404);
        $this->expectExceptionMessage('File not found.');

        (new DownloadAction())($request, $response, ['media_id' => 'known-media-id']);
    }
}
