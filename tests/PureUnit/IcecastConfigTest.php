<?php

declare(strict_types=1);

namespace PureUnit;

use App\Radio\Frontend\IcecastConfig;
use App\Xml\Writer;
use PHPUnit\Framework\TestCase;

final class IcecastConfigTest extends TestCase
{
    public function testTrustedProxyVirtualSocketConfiguration(): void
    {
        $xml = Writer::toString(
            ['listen-socket' => IcecastConfig::getListenSockets(8000)],
            'icecast',
            false
        );

        self::assertStringContainsString(
            <<<'XML'
            <listen-socket id="public">
                    <port>8000</port>
                    <trusted-proxy>#azuracast-proxy</trusted-proxy>
                </listen-socket>
            XML,
            $xml
        );
        self::assertStringContainsString(
            <<<'XML'
            <listen-socket id="azuracast-proxy" type="virtual">
                    <client-address>127.0.0.1</client-address>
                </listen-socket>
            XML,
            $xml
        );
    }
}
