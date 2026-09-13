<?php

namespace Edge\Tests;

use Edge\Auth;
use Edge\Client;
use Edge\Exception;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ExceptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Client::reset();
        Auth::setApiKey('ept_sandbox_s_test');
    }

    protected function tearDown(): void
    {
        Client::reset();

        parent::tearDown();
    }

    private function willRespondWith(array $responses)
    {
        Client::setHttpClient(new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]));
    }

    /**
     * Run a request that is expected to fail and hand back the exception.
     */
    private function failureFrom(array $responses)
    {
        $this->willRespondWith($responses);

        try {
            Client::get('customers');
        } catch (Exception $e) {
            return $e;
        }

        $this->fail('Expected an Edge\Exception to be thrown.');
    }

    // -- JSON:API error documents ------------------------------------------

    public function testParsesJsonApiErrorDocuments()
    {
        $body = json_encode(['errors' => [[
            'status' => '422',
            'code' => 'invalid_attribute',
            'title' => 'Invalid attribute',
            'detail' => 'amount_cents must be greater than 0',
            'source' => ['pointer' => '/data/attributes/amount_cents'],
        ]]]);

        $e = $this->failureFrom([new Response(422, ['Content-Type' => 'application/vnd.api+json'], $body)]);

        $this->assertSame(422, $e->getStatusCode());
        $this->assertSame('amount_cents must be greater than 0', $e->getMessage());
        $this->assertCount(1, $e->getErrors());
        $this->assertSame('/data/attributes/amount_cents', $e->getErrors()[0]['source']['pointer']);
        $this->assertSame($body, $e->getRawBody());
    }

    public function testFallsBackToTheErrorTitleWhenThereIsNoDetail()
    {
        $body = json_encode(['errors' => [['status' => '409', 'title' => 'Conflict']]]);

        $e = $this->failureFrom([new Response(409, [], $body)]);

        $this->assertSame('Conflict', $e->getMessage());
    }

    // -- responses that are not JSON:API -----------------------------------

    /**
     * The gateway answers authentication failures with plain text.
     */
    public function testHandlesPlainTextAuthenticationFailures()
    {
        $e = $this->failureFrom([new Response(401, ['Content-Type' => 'text/plain'], 'Unauthorized')]);

        $this->assertSame(401, $e->getStatusCode());
        $this->assertSame('Unauthorized', $e->getMessage());
        $this->assertSame([], $e->getErrors());
        $this->assertSame('Unauthorized', $e->getRawBody());
    }

    public function testHandlesAnEmptyBody()
    {
        $e = $this->failureFrom([new Response(403, [], '')]);

        $this->assertSame(403, $e->getStatusCode());
        $this->assertSame('Edge API error (HTTP 403)', $e->getMessage());
        $this->assertSame([], $e->getErrors());
        $this->assertSame('', $e->getRawBody());
    }

    public function testHandlesJsonThatIsNotAnErrorDocument()
    {
        $e = $this->failureFrom([new Response(400, [], '{"unexpected":true}')]);

        $this->assertSame('Edge API error (HTTP 400)', $e->getMessage());
        $this->assertSame([], $e->getErrors());
    }

    /**
     * A proxy error page must not become the exception message.
     */
    public function testDoesNotSurfaceAnHtmlBodyAsTheMessage()
    {
        $html = '<html><body><h1>502 Bad Gateway</h1></body></html>';

        $e = $this->failureFrom([new Response(502, ['Content-Type' => 'text/html'], $html)]);

        $this->assertSame('Edge API error (HTTP 502)', $e->getMessage());
        $this->assertSame($html, $e->getRawBody());
    }

    public function testDoesNotSurfaceAnOversizedBodyAsTheMessage()
    {
        $e = $this->failureFrom([new Response(500, [], str_repeat('a', 5000))]);

        $this->assertSame('Edge API error (HTTP 500)', $e->getMessage());
        $this->assertSame(5000, strlen($e->getRawBody()));
    }

    // -- transport failures ------------------------------------------------

    /**
     * ConnectException is not a RequestException in Guzzle 7, so it needs
     * catching separately or it escapes the SDK entirely.
     */
    public function testConnectionFailuresSurfaceAsEdgeExceptions()
    {
        $e = $this->failureFrom([
            new ConnectException('Could not resolve host', new Request('GET', 'https://api.tryedge.io/v2/customers')),
        ]);

        $this->assertSame(0, $e->getStatusCode());
        $this->assertSame([], $e->getErrors());
        $this->assertSame('', $e->getRawBody());
        $this->assertStringContainsString('Could not resolve host', $e->getMessage());
    }

    public function testTheUnderlyingGuzzleExceptionIsPreserved()
    {
        $e = $this->failureFrom([new Response(422, [], 'Unprocessable Content')]);

        $this->assertInstanceOf(\GuzzleHttp\Exception\RequestException::class, $e->getPrevious());
    }
}
