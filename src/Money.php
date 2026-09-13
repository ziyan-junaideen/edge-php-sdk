<?php

namespace Edge;

use Edge\Internal\ReadOnlyValue;

/** Exact integer cents; no coercion, currency normalization, or arithmetic. */
final class Money extends ReadOnlyValue
{
    private $cents;
    private $currency;

    public function __construct($cents, $currency)
    {
        if (!is_int($cents) || !is_string($currency) || $currency === '') {
            throw new \InvalidArgumentException('Money requires integer cents and a nonempty currency string.');
        }
        $this->cents = $cents;
        $this->currency = $currency;
    }

    public function __get($name)
    {
        if ($name === 'cents') {
            return $this->cents;
        }
        if ($name === 'currency') {
            return $this->currency;
        }

        return new Unavailable(Unavailable::UNDEFINED);
    }
}
