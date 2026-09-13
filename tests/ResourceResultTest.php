<?php

namespace Edge\Tests;

use Edge\Exception;
use Edge\Linkage;
use Edge\Money;
use Edge\Resource;
use Edge\ResourceDecoder;
use Edge\ResourceResult;
use Edge\Response;
use Edge\Unavailable;
use GuzzleHttp\Psr7\Response as HttpResponse;
use PHPUnit\Framework\TestCase;

class ResourceResultTest extends TestCase
{
    private function decode($document, array $query = [])
    {
        $decoder = new ResourceDecoder();
        $decoder->register('payment_demands', DecodedResource::class, [
            'dates' => ['created_at'],
            'money' => ['amount' => ['amount_cents', 'amount_currency']],
        ], ['description', 'buyer']);

        return $decoder->decode(new Response(new HttpResponse(200, [], json_encode($document))), $query);
    }

    public function testRetainsDocumentHttpMetadataAndDefensiveSnapshots()
    {
        $raw = '{"data":{"type":"unknowns","id":"1","attributes":{"extra":{"value":1}},"links":{},"meta":{"v":1},"extension":[]},"links":{"next":{"href":"/next"}},"meta":{},"extension":{"v":2}}';
        $result = new ResourceResult(new Response(new HttpResponse(201, ['X-Request-Id' => ['a', 'b']], $raw)));
        $this->assertSame(Resource::class, get_class($result->data));
        $this->assertSame(201, $result->status);
        $this->assertSame(['X-Request-Id' => ['a', 'b']], $result->headers);
        $this->assertSame($raw, json_encode($result->raw, JSON_UNESCAPED_SLASHES));
        $this->assertInstanceOf(\stdClass::class, $result->meta);
        $this->assertInstanceOf(\stdClass::class, $result->data->getLinks());
        $this->assertSame(1, $result->data->getMeta()->v);
        $this->assertSame([], $result->data->getMember('extension'));
        $result->raw->extension->v = 9;
        $result->links->next->href = '/changed';
        $result->data->extra->value = 9;
        $this->assertSame(2, $result->raw->extension->v);
        $this->assertSame('/next', $result->links->next->href);
        $this->assertSame(1, $result->data->extra->value);
        foreach (['data', 'included', 'links', 'meta', 'status', 'headers', 'raw', 'query'] as $property) {
            try {
                $result->$property = null;
                $this->fail('Result must be read-only.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('read-only', $e->getMessage());
            }
        }
    }

    public function testEmptySuccessNullAndEmptyCollectionRemainDistinctInRawDocument()
    {
        foreach (['' => null, '{"data":null}' => null, '{"data":[]}' => [], '{}' => null] as $body => $expected) {
            $result = new ResourceResult(new Response(new HttpResponse(204, [], $body)));
            $this->assertSame($expected, $result->data);
            $this->assertSame([], $result->included);
            $this->assertEquals(json_decode($body), $result->raw);
            $this->assertSame(Unavailable::UNDEFINED, $result->links->reason);
        }
        $result = $this->decode(['data' => null, 'links' => null, 'meta' => null]);
        $this->assertNull($result->links);
        $this->assertNull($result->meta);
    }

    public function testNestedCyclesPartialIncludesAndRepeatedIdentitiesResolveLocally()
    {
        $identity = ['type' => 'payment_demands', 'id' => '1'];
        $buyer = ['type' => 'customers', 'id' => '1'];
        $primary = $identity + ['attributes' => ['description' => 'first'], 'relationships' => [
            'buyer' => ['data' => $buyer + ['meta' => ['link' => true]]],
            'empty' => ['data' => []], 'null' => ['data' => null],
            'links_only' => ['links' => ['related' => '/never-fetch']],
            'invalid' => ['data' => false],
        ]];
        $document = ['data' => [$primary, $identity + ['attributes' => ['description' => 'second']]], 'included' => [
            $buyer + ['relationships' => ['demands' => ['data' => [$identity, ['type' => 'payment_demands', 'id' => 'missing']]]]],
            $identity + ['attributes' => ['description' => 'included']],
        ]];
        $result = $this->decode($document);
        $demand = $result->data[0];
        $this->assertInstanceOf(DecodedResource::class, $demand);
        $this->assertSame($demand, $result->data[1]);
        $this->assertSame($demand, $result->included[1]);
        $this->assertSame('first', $demand->description);
        $this->assertSame('second', $result->raw->data[1]->attributes->description);
        $this->assertSame($result->included[0], $demand->getRelated('buyer'));
        $this->assertSame(Resource::class, get_class($demand->getRelated('buyer')));
        for ($i = 0; $i < 100; $i++) {
            $demand = $demand->getRelated('buyer')->getRelated('demands')[0];
            $this->assertSame($result->data[0], $demand);
        }
        $missing = $demand->getRelated('buyer')->getRelated('demands')[1];
        $this->assertInstanceOf(Linkage::class, $missing);
        $this->assertSame('missing', $missing->id);
        $this->assertTrue($demand->buyer->data->meta->link);
        $this->assertSame([], $demand->getRelated('empty'));
        $this->assertNull($demand->getRelated('null'));
        $this->assertSame(Unavailable::UNFETCHED, $demand->getRelated('links_only')->reason);
        $this->assertSame(Unavailable::UNDEFINED, $demand->getRelated('absent')->reason);
        $this->assertSame(Unavailable::DECODING_FAILURE, $demand->getRelated('invalid')->reason);
        $this->assertNotSame($demand, $this->decode($document)->data[0]);
    }

    public function testRegistryAndSparseContextApplyEquallyToPrimaryAndIncluded()
    {
        foreach (['description', ['description']] as $fields) {
            $query = ['fields' => ['payment_demands' => $fields]];
            $result = $this->decode(['data' => ['type' => 'payment_demands', 'id' => '1'],
                'included' => [['type' => 'payment_demands', 'id' => '2']]], $query);
            $this->assertSame($query, $result->query);
            foreach ([$result->data, $result->included[0]] as $resource) {
                $this->assertInstanceOf(DecodedResource::class, $resource);
                $this->assertSame(Unavailable::UNDEFINED, $resource->description->reason);
                $this->assertSame(Unavailable::UNFETCHED, $resource->created_at->reason);
                $this->assertSame(Unavailable::UNFETCHED, $resource->amount->reason);
                $this->assertSame(Unavailable::UNFETCHED, $resource->getRelated('buyer')->reason);
                $this->assertSame(Unavailable::UNDEFINED, $resource->future->reason);
            }
        }
    }

    public function testRegisteredValuesPreserveValidNullInvalidAndUnknownFields()
    {
        $cases = [
            [['amount_cents' => 0, 'amount_currency' => 'future', 'created_at' => '2024-02-29T00:00:00.123456+05:30'], Money::class, \DateTimeImmutable::class],
            [['amount_cents' => PHP_INT_MIN, 'amount_currency' => 'USD', 'created_at' => '2024-02-29T00:00:00Z'], Money::class, \DateTimeImmutable::class],
            [['amount_cents' => PHP_INT_MAX, 'amount_currency' => 'USD', 'created_at' => '2024-02-29T00:00:00.1Z'], Money::class, \DateTimeImmutable::class],
            [['amount_cents' => null, 'amount_currency' => null, 'created_at' => null], null, null],
        ];
        foreach ([[0], [null], [null, 'USD'], [0, null], ['1', 'USD'], [1.5, 'USD'], [true, 'USD'], [1, ''], [1, 123]] as $pair) {
            $attributes = ['amount_cents' => $pair[0], 'created_at' => '2024-02-30T00:00:00Z'];
            if (count($pair) === 2) {
                $attributes['amount_currency'] = $pair[1];
            }
            $cases[] = [$attributes, Unavailable::class, Unavailable::class];
        }
        $cases[] = [['amount_currency' => 'USD', 'created_at' => 'tomorrow'], Unavailable::class, Unavailable::class];
        foreach ($cases as list($attributes, $moneyClass, $dateClass)) {
            $attributes += ['state' => 'future', 'custom_at' => 'opaque'];
            $result = $this->decode(['data' => ['type' => 'payment_demands', 'id' => '1', 'attributes' => $attributes]]);
            foreach (['amount' => $moneyClass, 'created_at' => $dateClass] as $name => $class) {
                if ($class === null) {
                    $this->assertNull($result->data->$name);
                } else {
                    $this->assertInstanceOf($class, $result->data->$name);
                    if ($class === Unavailable::class) {
                        $this->assertSame(Unavailable::DECODING_FAILURE, $result->data->$name->reason);
                    }
                }
            }
            $this->assertEquals((object) $attributes, $result->data->getMember('attributes'));
            $this->assertSame('future', $result->data->state);
            $this->assertSame('opaque', $result->data->custom_at);
        }
    }

    public function testValueFailuresAndPartialSparsePairsSurviveIncludedDecoding()
    {
        foreach (['2023-02-29T00:00:00Z', '2024-04-31T00:00:00Z', '2024-01-01T24:00:00Z',
            '2024-01-01T00:60:00Z', '2024-01-01T00:00:60Z', '2024-01-01T00:00:00+24:00',
            '2024-01-01T00:00:00+00:60', '2024-01-01', 'tomorrow', '', 123,
            '2024-01-01T00:00:00', '2024-01-01T00:00:00.1234567Z', "2024-01-01T00:00:00Z\n"] as $date) {
            $result = $this->decode(['data' => null, 'included' => [[
                'type' => 'payment_demands', 'id' => '1',
                'attributes' => ['amount_cents' => 0, 'created_at' => $date],
            ]]], ['fields' => ['payment_demands' => ['amount_cents']]]);
            $resource = $result->included[0];
            $this->assertSame(Unavailable::DECODING_FAILURE, $resource->amount->reason);
            $this->assertSame(['amount_cents' => 0], $resource->amount->raw);
            $this->assertSame(Unavailable::DECODING_FAILURE, $resource->created_at->reason);
            $this->assertSame($date, $resource->created_at->raw);
        }
    }

    public function testTransportResponseDecodesWithoutFetchingRelationshipsOrPagination()
    {
        $history = [];
        $mock = new \GuzzleHttp\Handler\MockHandler([new HttpResponse(200, ['X-Request-Id' => 'test'], json_encode([
            'data' => ['type' => 'customers', 'id' => '1', 'relationships' => [
                'demand' => ['data' => ['type' => 'payment_demands', 'id' => '2']],
            ]],
            'included' => [['type' => 'payment_demands', 'id' => '2', 'attributes' => [
                'amount_cents' => 10, 'amount_currency' => 'USD',
            ]]],
            'links' => ['next' => '/next'],
        ]))]);
        $stack = \GuzzleHttp\HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = new \Edge\ApiClient('ept_sandbox_s_test', [
            'http_client' => new \GuzzleHttp\Client(['handler' => $stack]),
        ]);
        $decoder = new ResourceDecoder();
        $decoder->register('payment_demands', DecodedResource::class, [
            'money' => ['amount' => ['amount_cents', 'amount_currency']],
        ]);
        $result = $decoder->decode($client->requestResponse('GET', 'customers/1'));
        $this->assertSame($result->included[0], $result->data->getRelated('demand'));
        $this->assertSame(10, $result->data->getRelated('demand')->amount->cents);
        $this->assertSame('/next', $result->links->next);
        $this->assertSame(['test'], $result->headers['X-Request-Id']);
        $this->assertCount(1, $history);
    }

    public function testMalformedDocumentsDoNotMasqueradeAsEmptySuccess()
    {
        foreach (['broken', 'null', '[]', '{"data":false}', '{"data":{"type":"customers","id":1}}', '{"included":{}}'] as $body) {
            try {
                new ResourceResult(new Response(new HttpResponse(200, [], $body)));
                $this->fail('Malformed document should fail.');
            } catch (Exception $e) {
                $this->assertStringContainsString('JSON:API', $e->getMessage());
            }
        }
    }

    public function testRegistryRejectsUnrelatedClasses()
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ResourceDecoder())->register('customers', \stdClass::class);
    }
}
