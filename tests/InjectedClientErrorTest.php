<?php

namespace Edge\Tests;

use Edge\ApiClient;
use Edge\Auth;
use Edge\Client;
use Edge\Customer;
use Edge\Exception;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class InjectedClientErrorTest extends TestCase
{
    protected function tearDown(): void
    {
        Client::reset();
        Auth::setApiKey(null);
        parent::tearDown();
    }

    /** @dataProvider errors */
    public function testErrorsDoNotDependOnGuzzleMiddleware($interface, $bareHandler, $status, $body, $message, $errors)
    {
        $mock = new MockHandler([new Response($status, [], $body)]);
        $httpClient = new GuzzleClient([
            'handler' => $bareHandler ? $mock : HandlerStack::create($mock),
            'http_errors' => $bareHandler,
        ]);
        $client = new ApiClient('ept_sandbox_s_test', ['http_client' => $httpClient]);
        try {
            if ($interface === 'legacy') {
                Auth::setApiKey('ept_sandbox_s_test');
                Client::setHttpClient($httpClient);
                Client::get('customers');
            } elseif ($interface === 'resource') {
                Customer::list($client);
            } else {
                $client->get('customers');
            }
            $this->fail('An HTTP error must throw before resource decoding.');
        } catch (Exception $exception) {
            $this->assertSame($status, $exception->getStatusCode());
            $this->assertSame($body, $exception->getRawBody());
            $this->assertSame($message, $exception->getMessage());
            $this->assertSame($errors, $exception->getErrors());
            $this->assertNull($exception->getPrevious());
        }
    }

    public static function errors()
    {
        $errors = [['status' => '422', 'detail' => 'Invalid amount',
            'source' => ['pointer' => '/data/attributes/amount_cents']]];
        foreach (['legacy', 'instance', 'resource'] as $interface) {
            foreach ([false, true] as $bareHandler) {
                foreach ([
                    [422, json_encode(['errors' => $errors]), 'Invalid amount', $errors],
                    [401, 'Unauthorized', 'Unauthorized', []],
                    [403, '', 'Edge API error (HTTP 403)', []],
                    [502, '<html>Bad Gateway</html>', 'Edge API error (HTTP 502)', []],
                ] as $response) {
                    yield array_merge([$interface, $bareHandler], $response);
                }
            }
        }
    }
}
