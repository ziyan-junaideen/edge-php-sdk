<?php

namespace Edge\Tests;

use Edge\ApiClient;
use Edge\Auth;
use Edge\Client;
use Edge\Exception;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ApiClientTest extends TestCase
{
    private $history = [];
    private $originalBaseUri;
    private $originalApiKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBaseUri = getenv('EDGE_API_BASE_URI');
        try {
            $this->originalApiKey = Auth::getApiKey();
        } catch (Exception $e) {
            $this->originalApiKey = null;
        }
        putenv('EDGE_API_BASE_URI');
        Client::reset();
    }

    protected function tearDown(): void
    {
        putenv($this->originalBaseUri === false ? 'EDGE_API_BASE_URI' : 'EDGE_API_BASE_URI=' . $this->originalBaseUri);
        Auth::setApiKey($this->originalApiKey);
        Client::reset();
        parent::tearDown();
    }

    private function httpClient(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new GuzzleClient(['handler' => $stack]);
    }

    private function client(array $responses, array $options = [])
    {
        return new ApiClient('ept_sandbox_s_test', array_merge($options, [
            'http_client' => $this->httpClient($responses),
        ]));
    }

    public function testInstancesAndLegacyCallsKeepTheirConfigurationWhenInterleavedAndReset()
    {
        $first = new ApiClient("  ept_sandbox_s_test_first\n", [
            'base_uri' => ' https://first.test:4001/ ',
            'verify' => false,
            'user_agent_suffix' => ' First/1.0 ',
            'http_client' => $this->httpClient([new Response(200), new Response(200)]),
        ]);
        $second = $this->client([new Response(200), new Response(200)], [
            'base_uri' => 'https://second.test/custom/',
            'verify' => '/test/ca.pem',
            'user_agent_suffix' => 'Second/2.0',
        ]);
        Client::setBaseUri('https://legacy.test');
        Client::setVerifySsl(false);
        Client::setUserAgentSuffix('Legacy/3.0');
        Client::setHttpClient($this->httpClient([new Response(200), new Response(200)]));
        Auth::setApiKey('ept_sandbox_s_test_legacy');

        $first->get('/customers');
        Client::get('customers');
        $second->get('customers');
        Auth::setApiKey('ept_sandbox_s_test_changed');
        Client::get('customers');
        Client::reset();
        Client::setHttpClient($this->httpClient([new Response(200)]));
        $second->get('customers');
        $first->get('customers');
        Client::get('customers');

        $expected = [
            ['https://first.test:4001/v2/', 'ept_sandbox_s_test_first', false, ' First/1.0'],
            ['https://legacy.test/v2/', 'ept_sandbox_s_test_legacy', false, ' Legacy/3.0'],
            ['https://second.test/custom/', 'ept_sandbox_s_test', '/test/ca.pem', ' Second/2.0'],
            ['https://legacy.test/v2/', 'ept_sandbox_s_test_changed', false, ' Legacy/3.0'],
            ['https://second.test/custom/', 'ept_sandbox_s_test', '/test/ca.pem', ' Second/2.0'],
            ['https://first.test:4001/v2/', 'ept_sandbox_s_test_first', false, ' First/1.0'],
            [Client::DEFAULT_BASE_URI, 'ept_sandbox_s_test_changed', true, ''],
        ];
        $this->assertCount(count($expected), $this->history);
        foreach ($expected as $index => $config) {
            list($base, $key, $verify, $suffix) = $config;
            $request = $this->history[$index]['request'];
            $this->assertSame($base . 'customers', (string) $request->getUri());
            $this->assertSame('Bearer ' . $key, $request->getHeaderLine('Authorization'));
            $this->assertSame($verify, $this->history[$index]['options']['verify']);
            $this->assertSame(sprintf('Edge PHP SDK %s (PHP %s)', Client::VERSION, PHP_VERSION) . $suffix, $request->getHeaderLine('User-Agent'));
        }
    }

    public function testInstanceDefaultsDoNotReadLegacyAuthenticationOrEnvironment()
    {
        Auth::setApiKey(null);
        putenv('EDGE_API_BASE_URI=https://legacy.test');
        $client = $this->client([new Response(200)]);
        $this->assertSame(Client::DEFAULT_BASE_URI, $client->getBaseUri());
        $client->get('customers');
        $this->assertTrue($this->history[0]['options']['verify']);
        $this->assertSame('Bearer ept_sandbox_s_test', $this->history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('https://legacy.test/v2/', Client::getBaseUri());
        putenv('EDGE_API_BASE_URI=https://changed.test');
        $this->assertSame('https://legacy.test/v2/', Client::getBaseUri());
        Client::reset();
        $this->assertSame('https://changed.test/v2/', Client::getBaseUri());
        $this->assertSame(Client::DEFAULT_BASE_URI, $client->getBaseUri());
    }

    public function testBlankKeyNeverFallsBackToLegacyAuthentication()
    {
        Auth::setApiKey('ept_sandbox_s_test');
        $this->expectException(Exception::class);
        new ApiClient(" \n ");
    }

    public function testLowLevelOperationsPreserveDocumentsVerbsAndMediaTypes()
    {
        $body = '{"data":{"id":"abc","type":"customers"},"meta":{"total":1}}';
        $client = $this->client(array_fill(0, 5, new Response(200, [], $body)));
        $document = ['data' => ['type' => 'customers', 'attributes' => ['name' => 'Ada']]];
        $results = [
            $client->get('customers'),
            $client->create('customers', $document),
            $client->update('customers/abc', $document),
            $client->patch('customers/abc', $document),
            $client->confirm('/payment_demands/', 'abc/ ?'),
        ];
        foreach (['GET', 'POST', 'PATCH', 'PATCH', 'PATCH'] as $index => $method) {
            $request = $this->history[$index]['request'];
            $this->assertEquals(json_decode($body), $results[$index]);
            $this->assertSame($method, $request->getMethod());
            $this->assertSame(Client::MEDIA_TYPE, $request->getHeaderLine('Accept'));
            $this->assertSame($index === 0 ? '' : Client::MEDIA_TYPE, $request->getHeaderLine('Content-Type'));
            if ($index > 0 && $index < 4) {
                $this->assertSame($document, json_decode((string) $request->getBody(), true));
            }
        }
        $this->assertSame('', (string) $this->history[0]['request']->getBody());
        $this->assertSame('/v2/payment_demands/abc%2F%20%3F/confirm', $this->history[4]['request']->getUri()->getPath());
        $this->assertSame('{"data":{"id":"abc\/ ?","type":"payment_demands","attributes":{}}}', (string) $this->history[4]['request']->getBody());
    }

    public function testQueriesRetainPaginationLinksAndEncodeNestedParameters()
    {
        $client = $this->client([new Response(200), new Response(200), new Response(200)]);
        $client->get('https://api.tryedge.io:443/v2/customers?page%5Bnumber%5D=2');
        $client->get('/customers?page%5Bnumber%5D=2', [
            'filter' => ['email' => 'a@b.com'], 'page' => ['size' => 25],
        ]);
        $client->get('//evil.test/collect');
        $this->assertSame('page%5Bnumber%5D=2', $this->history[0]['request']->getUri()->getQuery());
        $this->assertSame('filter%5Bemail%5D=a%40b.com&page%5Bsize%5D=25', $this->history[1]['request']->getUri()->getQuery());
        $this->assertSame('https://api.tryedge.io/v2/evil.test/collect', (string) $this->history[2]['request']->getUri());
    }

    public function testResponsesRetainTheirOwnStatusHeadersAndDocumentAcrossRequests()
    {
        $body = '{"data":{"id":"abc"},"included":[],"links":{"next":null},"meta":{"total":1}}';
        $client = $this->client([
            new Response(201, ['X-Request-Id' => ['first']], $body),
            new Response(204, ['X-Request-Id' => ['second']]),
            new Response(204),
        ]);
        $first = $client->requestResponse('POST', 'customers', ['json' => ['data' => ['type' => 'customers']]]);
        $second = $client->requestResponse('GET', 'customers');
        $this->assertNull($client->get('customers'));
        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(['first'], $first->getHeaders()['X-Request-Id']);
        $this->assertSame($body, $first->getBody());
        $this->assertEquals(json_decode($body), $first->toObject());
        $this->assertSame(json_decode($body, true), $first->toArray());
        $this->assertSame(204, $second->getStatusCode());
        $this->assertSame(['second'], $second->getHeaders()['X-Request-Id']);
        $this->assertNull($second->toObject());
    }

    /** @dataProvider invalidBaseUris */
    public function testRejectsMalformedBaseUris($baseUri)
    {
        $this->expectException(Exception::class);
        new ApiClient('ept_sandbox_s_test', ['base_uri' => $baseUri]);
    }

    public function invalidBaseUris()
    {
        return (new ClientTest())->invalidBaseUris() + [
            'invalid port' => ['https://api.tryedge.io:99999/v2'],
            'missing host' => ['https:///v2'],
        ];
    }

    /** @dataProvider foreignEndpoints */
    public function testRejectsForeignEndpointsBeforeSendingAnyRequest($endpoint)
    {
        $client = $this->client([]);
        try {
            $client->get($endpoint);
            $this->fail('Expected an Edge exception.');
        } catch (Exception $e) {
            $this->assertSame([], $this->history);
        }
    }

    public function foreignEndpoints()
    {
        return (new ClientTest())->foreignEndpoints();
    }
}
