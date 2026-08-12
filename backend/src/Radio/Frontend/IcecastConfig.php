<?php

declare(strict_types=1);

namespace App\Radio\Frontend;

final class IcecastConfig
{
    private const string PUBLIC_SOCKET_ID = 'public';
    private const string PROXY_SOCKET_ID = 'azuracast-proxy';
    private const string PROXY_CLIENT_ADDRESS = '127.0.0.1';

    /**
     * @return array<int, array<string, int|string>>
     */
    public static function getListenSockets(int $port): array
    {
        return [
            [
                '@id' => self::PUBLIC_SOCKET_ID,
                'port' => $port,
                'trusted-proxy' => '#' . self::PROXY_SOCKET_ID,
            ],
            [
                '@id' => self::PROXY_SOCKET_ID,
                '@type' => 'virtual',
                'client-address' => self::PROXY_CLIENT_ADDRESS,
            ],
        ];
    }
}
