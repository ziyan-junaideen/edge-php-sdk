<?php

namespace Edge\Tests;

use Edge\Linkage;
use Edge\Money;
use Edge\Relationship;
use Edge\Resource;
use Edge\Unavailable;
use Edge\ValueDecoder;
use PHPUnit\Framework\TestCase;

class ValueTest extends TestCase
{
    public function testMoneyPreservesIntegerExtremesAndCurrency()
    {
        foreach ([0, -1, PHP_INT_MIN, PHP_INT_MAX] as $cents) {
            $money = new Money($cents, 'future_currency');
            $this->assertSame($cents, $money->cents);
            $this->assertSame('future_currency', $money->currency);
        }
    }

    public function testMoneyDoesNotCoerceInvalidInputs()
    {
        foreach ([[1.5, 'USD'], ['1', 'USD'], [true, 'USD'], [null, 'USD'], [1, null], [1, ''], [1, 123]] as $pair) {
            try {
                new Money($pair[0], $pair[1]);
                $this->fail('Expected invalid money to be rejected.');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function testIncompleteMoneyRetainsPresenceAndNulls()
    {
        foreach ([['cents' => 0], ['currency' => 'USD'], ['cents' => null],
            ['cents' => null, 'currency' => 'USD'], ['cents' => 0, 'currency' => null],
            ['cents' => '100', 'currency' => 'USD']] as $attributes) {
            $value = ValueDecoder::money($attributes, 'cents', 'currency');
            $this->assertSame(Unavailable::DECODING_FAILURE, $value->reason);
            $this->assertSame($attributes, $value->raw);
        }
        $this->assertNull(ValueDecoder::money(['cents' => null, 'currency' => null], 'cents', 'currency'));
        $this->assertSame(Unavailable::UNDEFINED, ValueDecoder::money([], 'cents', 'currency')->reason);
        $this->assertSame(Unavailable::UNFETCHED, ValueDecoder::money([], 'cents', 'currency', Unavailable::UNFETCHED)->reason);
    }

    public function testTimestampsRejectNormalizationAndAmbiguousStrings()
    {
        foreach (['2023-02-29T00:00:00Z', '2024-04-31T00:00:00Z', '2024-01-01T24:00:00Z',
            '2024-01-01T00:60:00Z', '2024-01-01T00:00:60Z', '2024-01-01T00:00:00+24:00',
            '2024-01-01T00:00:00+00:60', '2024-01-01', 'tomorrow', '', 123,
            '2024-01-01T00:00:00', '2024-01-01T00:00:00.1234567Z', "2024-01-01T00:00:00Z\n"] as $raw) {
            $value = ValueDecoder::timestamp($raw);
            $this->assertSame(Unavailable::DECODING_FAILURE, $value->reason, (string) $raw);
            $this->assertSame($raw, $value->raw);
        }
        $this->assertNull(ValueDecoder::timestamp(null));
        foreach (['2024-02-29T00:00:00Z', '2024-02-29T00:00:00.1Z', '2024-02-29T00:00:00.123456+05:30'] as $raw) {
            $this->assertInstanceOf(\DateTimeImmutable::class, ValueDecoder::timestamp($raw));
        }
        $this->assertSame('+05:30', ValueDecoder::timestamp('2024-02-29T00:00:00+05:30')->format('P'));
    }

    public function testAllPrimitivePropertiesRejectAssignmentAndUnset()
    {
        $values = [new Money(0, 'USD'), new Resource(['id' => '1']),
            new Unavailable(Unavailable::UNFETCHED), new Linkage(['type' => 'customers', 'id' => '1']),
            new Relationship(['data' => null])];
        foreach ($values as $value) {
            foreach (['id', 'cents', 'raw', 'reason', 'data', 'unknown'] as $property) {
                try {
                    $value->$property = 1;
                    $this->fail('Assignment should fail.');
                } catch (\LogicException $e) {
                    $this->assertStringContainsString('read-only', $e->getMessage());
                }
                try {
                    unset($value->$property);
                    $this->fail('Unset should fail.');
                } catch (\LogicException $e) {
                    $this->assertStringContainsString('read-only', $e->getMessage());
                }
            }
        }
    }

    public function testUnavailableReasonMustBeRecognized()
    {
        $this->expectException(\InvalidArgumentException::class);
        new Unavailable('missing');
    }
}
