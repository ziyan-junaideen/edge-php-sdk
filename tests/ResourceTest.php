<?php

namespace Edge\Tests;

use Edge\Resource;
use Edge\Money;
use Edge\Unavailable;
use PHPUnit\Framework\TestCase;

class ResourceTest extends TestCase
{
    public function testConvertsOnlyExplicitMappingsAndRetainsWireData()
    {
        $raw = json_decode('{"id":"p/1","type":"payment_demands","attributes":{"amount_cents":0,"amount_currency":"USD","fee_cents":-1,"created_at":"2024-06-06T21:48:33.382755Z","processor_state":"future_state","custom_at":"not a date","extra":{"nested":true}},"links":{},"meta":{"version":1},"extension":[]}');
        $resource = new Resource($raw, [
            'dates' => ['created_at'],
            'money' => ['amount' => ['amount_cents', 'amount_currency'], 'fee' => ['fee_cents', 'amount_currency']],
        ]);
        $this->assertSame('p/1', $resource->id);
        $this->assertSame('payment_demands', $resource->type);
        $this->assertInstanceOf(Money::class, $resource->amount);
        $this->assertSame(0, $resource->amount->cents);
        $this->assertSame(-1, $resource->fee->cents);
        $this->assertSame(0, $resource->amount_cents);
        $this->assertSame('USD', $resource->amount_currency);
        $this->assertInstanceOf(\DateTimeImmutable::class, $resource->created_at);
        $this->assertSame('382755', $resource->created_at->format('u'));
        $this->assertSame('future_state', $resource->processor_state);
        $this->assertSame('not a date', $resource->custom_at);
        $this->assertSame(json_encode($raw), json_encode($resource->getRaw()));
        $this->assertInstanceOf(\stdClass::class, $resource->getLinks());
        $this->assertSame(1, $resource->getMeta()->version);
        $this->assertSame([], $resource->getMember('extension'));
        $this->assertSame('2024-06-06T21:48:33.382755Z', $resource->getMember('attributes')->created_at);
    }

    public function testDistinguishesNullSparseOmissionUndefinedAndDecodeFailure()
    {
        $resource = new Resource(['attributes' => ['name' => null, 'created_at' => '2024-02-30T00:00:00Z']], [
            'dates' => ['created_at'], 'money' => ['amount' => ['amount_cents', 'amount_currency']],
        ], ['email', 'amount_cents', 'amount_currency']);
        $this->assertNull($resource->name);
        $this->assertSame(Unavailable::UNFETCHED, $resource->email->reason);
        $this->assertSame(Unavailable::UNFETCHED, $resource->amount->reason);
        $this->assertSame(Unavailable::UNDEFINED, $resource->other->reason);
        $this->assertSame(Unavailable::DECODING_FAILURE, $resource->created_at->reason);
        $this->assertFalse(isset($resource->name));
        $this->assertFalse(isset($resource->email));
        $this->assertFalse(isset($resource->other));
    }

    public function testNestedWireObjectsAreDefensiveSnapshots()
    {
        $raw = json_decode('{"attributes":{"extra":{"value":1}},"meta":{"value":1}}');
        $resource = new Resource($raw);
        $raw->attributes->extra->value = 2;
        $resource->extra->value = 3;
        $resource->getAttributes()['extra']->value = 4;
        $resource->getRaw()->attributes->extra->value = 5;
        $resource->getMeta()->value = 6;
        $this->assertSame(1, $resource->extra->value);
        $this->assertSame(1, $resource->getMeta()->value);
    }

    public function testUnknownAttributesDoNotCollideWithStorageOrDerivedValues()
    {
        $resource = new Resource(['attributes' => [
            'raw' => 'raw attribute', 'attributes' => 'attribute', 'meta' => 'metadata attribute',
            'amount' => 'future wire value', 'amount_cents' => 1, 'amount_currency' => 'USD',
        ], 'meta' => ['page' => 1]], ['money' => ['amount' => ['amount_cents', 'amount_currency']]]);
        $this->assertSame('raw attribute', $resource->raw);
        $this->assertSame('attribute', $resource->attributes);
        $this->assertSame('metadata attribute', $resource->meta);
        $this->assertSame(['page' => 1], $resource->getMeta());
        $this->assertSame('future wire value', $resource->amount);
    }

    public function testRelationshipsAreIndependentOfResourceMetadata()
    {
        $resource = new Resource(['links' => ['self' => '/resource'], 'meta' => ['level' => 'resource'],
            'relationships' => ['buyer' => ['data' => ['id' => '1', 'type' => 'customers'],
                'links' => ['related' => '/buyer'], 'meta' => ['level' => 'relationship']]]]);
        $this->assertSame('1', $resource->buyer->data->id);
        $this->assertSame($resource->buyer, $resource->getRelationships()['buyer']);
        $this->assertSame(['self' => '/resource'], $resource->getLinks());
        $this->assertSame(['related' => '/buyer'], $resource->buyer->links);
        $this->assertSame(['level' => 'relationship'], $resource->buyer->meta);
        $this->assertSame(['level' => 'resource'], $resource->getMeta());
    }
}
