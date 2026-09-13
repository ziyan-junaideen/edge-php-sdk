<?php

namespace Edge\Tests;

use Edge\Linkage;
use Edge\Relationship;
use Edge\Unavailable;
use PHPUnit\Framework\TestCase;

class RelationshipTest extends TestCase
{
    public function testPreservesAllRelationshipShapes()
    {
        $one = new Relationship(['data' => ['type' => 'unknowns', 'id' => '1', 'meta' => ['extra' => true]]]);
        $this->assertInstanceOf(Linkage::class, $one->data);
        $this->assertSame('unknowns', $one->data->type);
        $this->assertSame(['extra' => true], $one->data->meta);
        $many = new Relationship(['data' => [['type' => 'customers', 'id' => '1'], ['type' => 'customers', 'id' => '2']]]);
        $this->assertCount(2, $many->data);
        $this->assertSame('2', $many->data[1]->id);
        $this->assertNull((new Relationship(['data' => null]))->data);
        $this->assertSame([], (new Relationship(['data' => []]))->data);
        $linksOnly = new Relationship(['links' => ['related' => '/customers']]);
        $this->assertSame(Unavailable::UNFETCHED, $linksOnly->data->reason);
        $this->assertSame(Unavailable::UNDEFINED, $linksOnly->meta->reason);
    }

    public function testRetainsRawObjectsAndExtensionsWithoutMutableAliases()
    {
        $raw = json_decode('{"data":{"type":"customers","id":"1","meta":{"x":1},"extra":{}},"links":{},"meta":{"x":2},"extension":true}');
        $relationship = new Relationship($raw);
        $expected = json_encode($raw);
        $raw->data->meta->x = 9;
        $relationship->data->meta->x = 10;
        $relationship->data->getRaw()->meta->x = 11;
        $relationship->getRaw()->meta->x = 12;
        $this->assertSame($expected, json_encode($relationship->getRaw()));
        $this->assertSame(1, $relationship->data->meta->x);
        $this->assertTrue($relationship->extension);
        $this->assertInstanceOf(\stdClass::class, $relationship->links);
    }

    public function testInvalidLinkageBecomesFailureWithoutLosingRawData()
    {
        foreach ([new \stdClass(), ['type' => 'customers'], ['type' => 'customers', 'id' => 1], false,
            [['type' => 'customers', 'id' => '1'], null]] as $raw) {
            $value = (new Relationship(['data' => $raw]))->data;
            $this->assertSame(Unavailable::DECODING_FAILURE, $value->reason);
            $this->assertEquals($raw, $value->raw);
        }
    }
}
