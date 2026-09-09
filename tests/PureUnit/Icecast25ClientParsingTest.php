<?php

declare(strict_types=1);

namespace PureUnit;

use App\Radio\Adapter\Icecast;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class Icecast25ClientParsingTest extends TestCase
{
    public function testIcecast25LowercaseClientFieldsAreParsed(): void
    {
        $adapter = $this->createAdapter(<<<'XML'
            <icestats>
              <source mount="/radio.mp3">
                <listener id="212">
                  <id>212</id>
                  <ip>203.0.113.42</ip>
                  <useragent>FMODStudio/2.03.12</useragent>
                  <connected>13</connected>
                </listener>
              </source>
            </icestats>
            XML);

        $clients = $adapter->getClients('/radio.mp3', false);

        self::assertCount(1, $clients);
        self::assertSame('212', $clients[0]->uid);
        self::assertSame('203.0.113.42', $clients[0]->ip);
        self::assertSame('FMODStudio/2.03.12', $clients[0]->userAgent);
        self::assertSame(13, $clients[0]->connectedSeconds);
        self::assertSame('/radio.mp3', $clients[0]->mount);
    }

    public function testHistoricalFieldsAndAttributeIdRemainSupported(): void
    {
        $adapter = $this->createAdapter(<<<'XML'
            <icestats>
              <source mount="/radio.mp3">
                <listener>
                  <ID>legacy-1</ID>
                  <IP>198.51.100.10</IP>
                  <UserAgent>Legacy Player</UserAgent>
                  <Connected>27</Connected>
                </listener>
                <listener id="attribute-2">
                  <ip>192.168.1.14</ip>
                  <useragent>FMODStudio/2.03.12</useragent>
                  <connected>8</connected>
                </listener>
              </source>
            </icestats>
            XML);

        $clients = $adapter->getClients('/radio.mp3', false);

        self::assertCount(2, $clients);
        self::assertSame('legacy-1', $clients[0]->uid);
        self::assertSame('attribute-2', $clients[1]->uid);
        self::assertSame('192.168.1.14', $clients[1]->ip);
    }

    public function testDistinctIpsWithSameUserAgentRemainUnique(): void
    {
        $adapter = $this->createAdapter($this->twoClientXml('203.0.113.10', '203.0.113.11'));

        self::assertCount(2, $adapter->getClients('/radio.mp3', true));
    }

    public function testTotalsRemainConsistentWithTwoDistinctClients(): void
    {
        $adapter = $this->createAdapterFromResponses([
            <<<'XML'
                <icestats>
                  <source mount="/radio.mp3">
                    <listeners>2</listeners>
                    <title>Test track</title>
                    <server_type>audio/mpeg</server_type>
                  </source>
                </icestats>
                XML,
            $this->twoClientXml('203.0.113.10', '203.0.113.11'),
        ]);
        $adapter->setAdminPassword('test-password');

        $result = $adapter->getNowPlaying('/radio.mp3', true);

        self::assertSame(2, $result->listeners->total);
        self::assertSame(2, $result->listeners->unique);
        self::assertNotNull($result->clients);
        self::assertCount(2, $result->clients);
    }

    public function testSameIpAndUserAgentAreStillDeduplicated(): void
    {
        $adapter = $this->createAdapter($this->twoClientXml('203.0.113.10', '203.0.113.10'));

        self::assertCount(1, $adapter->getClients('/radio.mp3', true));
    }

    public function testInvalidClientEntriesAreIgnored(): void
    {
        $adapter = $this->createAdapter(<<<'XML'
            <icestats>
              <source mount="/radio.mp3">
                <listener id="missing-ip"><useragent>Player</useragent></listener>
                <listener id="invalid-ip"><ip>not-an-ip</ip><useragent>Player</useragent></listener>
                <listener id="valid-ip"><ip>2001:db8::42</ip><useragent>Player</useragent></listener>
              </source>
            </icestats>
            XML);

        $clients = $adapter->getClients('/radio.mp3', false);

        self::assertCount(1, $clients);
        self::assertSame('2001:db8::42', $clients[0]->ip);
    }

    public function testZeroListenersReturnsAnEmptyList(): void
    {
        $adapter = $this->createAdapter(
            '<icestats><source mount="/radio.mp3"><listeners>0</listeners></source></icestats>'
        );

        self::assertSame([], $adapter->getClients('/radio.mp3', false));
    }

    private function createAdapter(string $xml): Icecast
    {
        return $this->createAdapterFromResponses([$xml]);
    }

    /**
     * @param string[] $responses
     */
    private function createAdapterFromResponses(array $responses): Icecast
    {
        $httpClient = new HttpClient([
            'handler' => new MockHandler(
                array_map(
                    static fn(string $xml): Response => new Response(200, [], $xml),
                    $responses
                )
            ),
        ]);

        return new Icecast(
            new HttpFactory(),
            $httpClient,
            new NullLogger(),
            new Uri('http://icecast.test')
        );
    }

    private function twoClientXml(string $firstIp, string $secondIp): string
    {
        return <<<XML
            <icestats>
              <source mount="/radio.mp3">
                <listener id="1">
                  <ip>{$firstIp}</ip>
                  <useragent>FMODStudio/2.03.12</useragent>
                  <connected>10</connected>
                </listener>
                <listener id="2">
                  <ip>{$secondIp}</ip>
                  <useragent>FMODStudio/2.03.12</useragent>
                  <connected>20</connected>
                </listener>
              </source>
            </icestats>
            XML;
    }
}
