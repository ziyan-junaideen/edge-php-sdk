<?php

namespace Edge\Tests;

use Edge\Auth;
use Edge\Client;
use Edge\Exception;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    /** @var array Requests recorded by the history middleware. */
    private $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->history = [];
        Client::reset();
        Auth::setApiKey('ept_sandbox_s_test');
    }

    protected function tearDown(): void
    {
        Client::reset();

        parent::tearDown();
    }

    /**
     * Queue up responses and record everything that gets sent.
     */
    private function willRespondWith(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        Client::setHttpClient(new GuzzleClient(['handler' => $stack]));
    }

    private function ok($body = '{"data":{"id":"abc","type":"customers"}}')
    {
        return new Response(200, ['Content-Type' => 'application/vnd.api+json'], $body);
    }

    private function lastRequest()
    {
        $this->assertNotEmpty($this->history, 'No request was sent.');

        return end($this->history)['request'];
    }

    // -- URL resolution ----------------------------------------------------

    public function testResolvesEndpointsAgainstTheVersionedBaseUri()
    {
        $this->willRespondWith([$this->ok(), $this->ok()]);

        Client::get('customers');
        $this->assertSame('https://api.tryedge.io/v2/customers', (string) $this->lastRequest()->getUri());

        Client::get('payment_demands/abc');
        $this->assertSame('https://api.tryedge.io/v2/payment_demands/abc', (string) $this->lastRequest()->getUri());
    }

    /**
     * Guzzle's own base_uri resolution drops the /v2 prefix for a leading slash;
     * both spellings must reach the same URL.
     */
    public function testLeadingSlashDoesNotDropTheVersionPrefix()
    {
        $this->willRespondWith([$this->ok()]);

        Client::get('/customers');

        $this->assertSame('https://api.tryedge.io/v2/customers', (string) $this->lastRequest()->getUri());
    }

    public function testBareHostBaseUriGainsTheVersionPrefix()
    {
        Client::setBaseUri('https://api.tryedge.test:4001');
        $this->willRespondWith([$this->ok()]);

        Client::get('customers');

        $this->assertSame('https://api.tryedge.test:4001/v2/customers', (string) $this->lastRequest()->getUri());
    }

    public function testBaseUriWithAnExplicitPathIsLeftAlone()
    {
        Client::setBaseUri('https://api.tryedge.io/v2');
        $this->willRespondWith([$this->ok()]);

        Client::get('customers');

        $this->assertSame('https://api.tryedge.io/v2/customers', (string) $this->lastRequest()->getUri());
    }

    public function testNestedFiltersAreEncodedAsJsonApiQueryParameters()
    {
        $this->willRespondWith([$this->ok()]);

        Client::get('customers', [
            'filter' => ['email' => 'a@b.com'],
            'page' => ['size' => 25],
        ]);

        $this->assertSame(
            'filter%5Bemail%5D=a%40b.com&page%5Bsize%5D=25',
            $this->lastRequest()->getUri()->getQuery()
        );
    }

    // -- credentials -------------------------------------------------------

    public function testCredentialsAreReadPerRequestRatherThanMemoized()
    {
        $this->willRespondWith([$this->ok(), $this->ok()]);

        Auth::setApiKey('ept_sandbox_s_first');
        Client::get('customers');
        $this->assertSame('Bearer ept_sandbox_s_first', $this->lastRequest()->getHeaderLine('Authorization'));

        Auth::setApiKey('ept_sandbox_s_second');
        Client::get('customers');
        $this->assertSame('Bearer ept_sandbox_s_second', $this->lastRequest()->getHeaderLine('Authorization'));
    }

    public function testSurroundingWhitespaceIsStrippedFromTheKey()
    {
        $this->willRespondWith([$this->ok()]);

        Auth::setApiKey("  ept_sandbox_s_padded\n");
        Client::get('customers');

        $this->assertSame('Bearer ept_sandbox_s_padded', $this->lastRequest()->getHeaderLine('Authorization'));
    }

    // -- headers -----------------------------------------------------------

    public function testGetRequestsCarryNoContentType()
    {
        $this->willRespondWith([$this->ok()]);

        Client::get('customers');

        $this->assertSame('application/vnd.api+json', $this->lastRequest()->getHeaderLine('Accept'));
        $this->assertSame('', $this->lastRequest()->getHeaderLine('Content-Type'));
    }

    public function testWritesUseTheJsonApiMediaTypeRatherThanGuzzlesDefault()
    {
        $this->willRespondWith([$this->ok(), $this->ok()]);

        Client::create('customers', ['data' => ['type' => 'customers']]);
        $this->assertSame('application/vnd.api+json', $this->lastRequest()->getHeaderLine('Content-Type'));

        Client::update('customers/abc', ['data' => ['type' => 'customers']]);
        $this->assertSame('application/vnd.api+json', $this->lastRequest()->getHeaderLine('Content-Type'));
    }

    public function testUserAgentIdentifiesTheSdkAndCanCarryAnApplicationSuffix()
    {
        $this->willRespondWith([$this->ok(), $this->ok()]);

        Client::get('customers');
        $this->assertSame(
            sprintf('Edge PHP SDK %s (PHP %s)', Client::VERSION, PHP_VERSION),
            $this->lastRequest()->getHeaderLine('User-Agent')
        );

        Client::setUserAgentSuffix('WooCommerce/9.1.2');
        Client::get('customers');
        $this->assertStringEndsWith('WooCommerce/9.1.2', $this->lastRequest()->getHeaderLine('User-Agent'));
    }

    // -- verbs -------------------------------------------------------------

    public function testUpdateSendsPatchBecauseTheApiHasNoPut()
    {
        $this->willRespondWith([$this->ok()]);

        Client::update('customers/abc', ['data' => ['type' => 'customers']]);

        $this->assertSame('PATCH', $this->lastRequest()->getMethod());
    }

    public function testCreateSendsPost()
    {
        $this->willRespondWith([$this->ok()]);

        Client::create('customers', ['data' => ['type' => 'customers']]);

        $this->assertSame('POST', $this->lastRequest()->getMethod());
    }

    public function testDeleteIsNotPartOfTheApi()
    {
        $this->assertFalse(
            method_exists(Client::class, 'delete'),
            'The Edge API exposes no DELETE routes, so Client should not offer delete().'
        );
    }

    // -- confirm -----------------------------------------------------------

    public function testConfirmPatchesTheConfirmRouteWithAJsonApiDocument()
    {
        $this->willRespondWith([$this->ok()]);

        Client::confirm('payment_demands', 'abc');

        $request = $this->lastRequest();

        $this->assertSame('PATCH', $request->getMethod());
        $this->assertSame(
            'https://api.tryedge.io/v2/payment_demands/abc/confirm',
            (string) $request->getUri()
        );
    }

    /**
     * An empty PHP array encodes to a JSON list; the server requires an object.
     */
    public function testConfirmEncodesEmptyAttributesAsAnObject()
    {
        $this->willRespondWith([$this->ok()]);

        Client::confirm('payment_demands', 'abc');

        $this->assertSame(
            '{"data":{"id":"abc","type":"payment_demands","attributes":{}}}',
            (string) $this->lastRequest()->getBody()
        );
    }

    public function testConfirmPassesThroughSuppliedAttributes()
    {
        $this->willRespondWith([$this->ok()]);

        Client::confirm('payment_subscriptions', 'xyz', ['confirmed_at' => '2026-08-08T00:00:00Z']);

        $body = json_decode((string) $this->lastRequest()->getBody(), true);

        $this->assertSame('payment_subscriptions', $body['data']['type']);
        $this->assertSame(['confirmed_at' => '2026-08-08T00:00:00Z'], $body['data']['attributes']);
    }

    // -- credential safety -------------------------------------------------

    public function testRefusesToSendCredentialsToAnotherOrigin()
    {
        $this->willRespondWith([$this->ok()]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Refusing to send Edge credentials');

        Client::get('https://evil.test/collect');
    }

    /**
     * @dataProvider foreignEndpoints
     */
    public function testRejectsEveryFlavourOfForeignEndpoint($endpoint)
    {
        $this->willRespondWith([$this->ok()]);

        $this->expectException(Exception::class);

        Client::get($endpoint);
    }

    public function foreignEndpoints()
    {
        return [
            'other host' => ['https://evil.test/collect'],
            'scheme downgrade' => ['http://api.tryedge.io/v2/customers'],
            'other port' => ['https://api.tryedge.io:8443/v2/customers'],
            'non-http scheme' => ['file:///etc/passwd'],
        ];
    }

    public function testAllowsAbsoluteUrlsBelongingToTheConfiguredApi()
    {
        $this->willRespondWith([$this->ok()]);

        Client::get('https://api.tryedge.io/v2/customers?page%5Bnumber%5D=2');

        $this->assertSame(
            'https://api.tryedge.io/v2/customers?page%5Bnumber%5D=2',
            (string) $this->lastRequest()->getUri()
        );
    }

    public function testAnExplicitQueryReplacesOneCarriedByTheEndpoint()
    {
        $this->willRespondWith([$this->ok()]);

        Client::get('customers?page%5Bnumber%5D=2', ['filter' => ['email' => 'a@b.com']]);

        $this->assertSame('filter%5Bemail%5D=a%40b.com', $this->lastRequest()->getUri()->getQuery());
    }

    public function testProtocolRelativeEndpointsCannotEscapeTheConfiguredHost()
    {
        $this->willRespondWith([$this->ok()]);

        Client::get('//evil.test/collect');

        $this->assertSame(
            'https://api.tryedge.io/v2/evil.test/collect',
            (string) $this->lastRequest()->getUri()
        );
    }

    // -- configuration validation -----------------------------------------

    /**
     * @dataProvider invalidBaseUris
     */
    public function testRejectsUnusableBaseUris($baseUri)
    {
        $this->expectException(Exception::class);

        Client::setBaseUri($baseUri);
    }

    public function invalidBaseUris()
    {
        return [
            'not a url' => ['not a url'],
            'no scheme' => ['api.tryedge.io/v2'],
            'unsupported scheme' => ['ftp://api.tryedge.io/v2'],
        ];
    }
}
