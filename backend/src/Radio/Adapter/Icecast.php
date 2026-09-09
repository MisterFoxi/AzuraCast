<?php

declare(strict_types=1);

namespace App\Radio\Adapter;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use NowPlaying\Adapter\AdapterAbstract;
use NowPlaying\Adapter\Icecast as BaseIcecast;
use NowPlaying\Result\Client;
use NowPlaying\Result\Listeners;
use NowPlaying\Result\Result;
use SimpleXMLElement;

final class Icecast extends AdapterAbstract
{
    public function getNowPlayingAsync(?string $mount = null, bool $includeClients = false): PromiseInterface
    {
        $nowPlayingPromise = $this->createBaseAdapter()->getNowPlayingAsync($mount, false);

        if (!$includeClients || null === $this->adminPassword) {
            return $nowPlayingPromise;
        }

        return Utils::all([
            'now_playing' => $nowPlayingPromise,
            'clients' => $this->getClientsAsync($mount, true),
        ])->then(
            static function (array $results): Result {
                /** @var Result $nowPlaying */
                $nowPlaying = $results['now_playing'];
                /** @var Client[] $clients */
                $clients = $results['clients'];

                $nowPlaying->clients = $clients;
                $nowPlaying->listeners = new Listeners(
                    $nowPlaying->listeners->total,
                    count($clients)
                );

                return $nowPlaying;
            }
        );
    }

    public function getClientsAsync(?string $mount = null, bool $uniqueOnly = true): PromiseInterface
    {
        if (empty($mount)) {
            $this->logError('This adapter requires a mount point name.');
            return Create::promiseFor([]);
        }

        $request = $this->requestFactory->createRequest(
            'GET',
            $this->baseUriWithPathAndQuery(
                '/admin/listclients',
                [
                    'mount' => $mount,
                ]
            )
        );

        return $this->getUrl($request)->then(
            function (?string $payload) use ($mount, $uniqueOnly): array {
                if (empty($payload)) {
                    return [];
                }

                $xml = $this->getSimpleXml($payload);
                if (null === $xml) {
                    return [];
                }

                $clients = [];
                foreach ($xml->source->listener as $listener) {
                    $client = $this->parseClient($listener, $mount);
                    if (null !== $client) {
                        $clients[] = $client;
                    }
                }

                return $uniqueOnly
                    ? $this->getUniqueListeners($clients)
                    : $clients;
            }
        );
    }

    private function createBaseAdapter(): BaseIcecast
    {
        $adapter = new BaseIcecast(
            $this->requestFactory,
            $this->client,
            $this->logger,
            $this->baseUri,
            $this->errorLogLevel
        );

        return $adapter
            ->setAdminUsername($this->adminUsername)
            ->setAdminPassword($this->adminPassword);
    }

    private function parseClient(SimpleXMLElement $listener, string $mount): ?Client
    {
        $id = self::getListenerValue($listener, 'id', 'ID');
        if ('' === $id) {
            $id = trim((string)$listener['id']);
        }

        $ip = self::getListenerValue($listener, 'ip', 'IP');
        if (false === filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->logError('Ignoring an Icecast listener entry with a missing or invalid IP address.');
            return null;
        }

        return new Client(
            $id,
            $ip,
            self::getListenerValue($listener, 'useragent', 'UserAgent'),
            (int)self::getListenerValue($listener, 'connected', 'Connected'),
            $mount
        );
    }

    private static function getListenerValue(
        SimpleXMLElement $listener,
        string $currentName,
        string $legacyName
    ): string {
        $value = trim((string)$listener->{$currentName});

        return ('' !== $value)
            ? $value
            : trim((string)$listener->{$legacyName});
    }
}
