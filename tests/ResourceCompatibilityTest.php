<?php

namespace Edge\Tests;

use Edge\ApiClient;
use Edge\Auth;
use Edge\Client;
use Edge\Exception;
use Edge\Customer;
use Edge\PaymentDemand;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ResourceCompatibilityTest extends TestCase
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
            'http_client' => $this->httpClient([new Response(200, [], '{"data":{"type":"customers","id":"same-id","attributes":{"created_at":"2026-09-13T10:00:00Z"}}}'), new Response(200, [], '{"data":{"type":"customers","id":"same-id","attributes":{"created_at":"2026-09-13T10:00:00Z"}}}')]),
        ]);
        $second = $this->client([new Response(200, [], '{"data":{"type":"customers","id":"same-id","attributes":{"created_at":"2026-09-13T10:00:00Z"}}}'), new Response(200, [], '{"data":{"type":"customers","id":"same-id","attributes":{"created_at":"2026-09-13T10:00:00Z"}}}')], [
            'base_uri' => 'https://second.test/custom/',
            'verify' => '/test/ca.pem',
            'user_agent_suffix' => 'Second/2.0',
        ]);
        Client::setBaseUri('https://legacy.test');
        Client::setVerifySsl(false);
        Client::setUserAgentSuffix('Legacy/3.0');
        Client::setHttpClient($this->httpClient([new Response(200, [], '{"data":{"type":"customers","id":"same-id","attributes":{"created_at":"2026-09-13T10:00:00Z"}}}'), new Response(200, [], '{"data":{"type":"customers","id":"same-id","attributes":{"created_at":"2026-09-13T10:00:00Z"}}}')]));
        Auth::setApiKey('ept_sandbox_s_test_legacy');

        $firstResult = Customer::show($first, 'same-id');
        $legacy = Client::get('customers/same-id');
        $secondResult = Customer::show($second, 'same-id');
        Auth::setApiKey('ept_sandbox_s_test_changed');
        $legacy = Client::get('customers/same-id');
        Client::reset();
        Client::setHttpClient($this->httpClient([new Response(200, [], '{"data":{"type":"customers","id":"same-id","attributes":{"created_at":"2026-09-13T10:00:00Z"}}}')]));
        $secondResult = Customer::show($second, 'same-id');
        $again = Customer::show($first, 'same-id');
        $legacy = Client::get('customers/same-id');

        $this->assertInstanceOf(Customer::class, $firstResult->data);
        $this->assertInstanceOf(\DateTimeImmutable::class, $secondResult->data->created_at);
        $this->assertNotSame($firstResult->data, $secondResult->data);
        $this->assertNotSame($firstResult->data, $again->data);
        $this->assertInstanceOf(\stdClass::class, $legacy);
        $this->assertSame('2026-09-13T10:00:00Z', $legacy->data->attributes->created_at);
        $this->assertFalse(isset($legacy->status));

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
            $this->assertSame($base . 'customers/same-id', (string) $request->getUri());
            $this->assertSame('Bearer ' . $key, $request->getHeaderLine('Authorization'));
            $this->assertSame($verify, $this->history[$index]['options']['verify']);
            $this->assertSame(sprintf('Edge PHP SDK %s (PHP %s)', Client::VERSION, PHP_VERSION) . $suffix, $request->getHeaderLine('User-Agent'));
        }
    }

    public function testResourceAndLegacyErrorsRetainTheSameDetails()
    {
        $body = '{"errors":[{"status":"422","detail":"Invalid amount","source":{"pointer":"/data/attributes/amount_cents"}}]}';
        $client = $this->client([new Response(422, [], $body)]);
        Auth::setApiKey('ept_sandbox_s_test_legacy');
        Client::setHttpClient($this->httpClient([new Response(422, [], $body)]));
        $errors = [];
        foreach ([
            function () use ($client) { return PaymentDemand::create($client, ['attributes' => ['amount_cents' => -1]]); },
            function () { return Client::create('payment_demands', ['data' => ['type' => 'payment_demands', 'attributes' => ['amount_cents' => -1]]]); },
        ] as $call) {
            try {
                $call();
                $this->fail('Expected Edge exception.');
            } catch (Exception $e) {
                $this->assertSame(422, $e->getStatusCode());
                $this->assertSame($body, $e->getRawBody());
                $errors[] = $e->getErrors();
            }
        }
        $this->assertSame($errors[0], $errors[1]);
        $this->assertCount(2, $this->history);
    }
}
