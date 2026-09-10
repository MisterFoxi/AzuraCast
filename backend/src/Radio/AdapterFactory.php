<?php

declare(strict_types=1);

namespace App\Radio;

use App\Radio\Adapter\Icecast;
use NowPlaying\Adapter\AdapterInterface;
use NowPlaying\AdapterFactory as BaseAdapterFactory;
use NowPlaying\Enums\AdapterTypes;
use Psr\Http\Message\UriInterface;

final class AdapterFactory extends BaseAdapterFactory
{
    public function getAdapter(
        AdapterTypes $adapterType,
        string|UriInterface $baseUri
    ): AdapterInterface {
        if (AdapterTypes::Icecast !== $adapterType) {
            return parent::getAdapter($adapterType, $baseUri);
        }

        if (!$baseUri instanceof UriInterface) {
            $baseUri = $this->uriFactory->createUri($baseUri);
        }

        return new Icecast(
            $this->requestFactory,
            $this->client,
            $this->logger,
            $baseUri,
            $this->errorLogLevel
        );
    }
}
