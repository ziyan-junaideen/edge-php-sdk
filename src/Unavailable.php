<?php

namespace Edge;

use Edge\Internal\ReadOnlyValue;

/** An absent or undecodable value, distinct from an explicit JSON null. */
final class Unavailable extends ReadOnlyValue
{
    const UNFETCHED = 'unfetched';
    const UNDEFINED = 'undefined';
    const DECODING_FAILURE = 'decoding_failure';

    private $reason;
    private $raw;

    public function __construct($reason, $raw = null)
    {
        if (!in_array($reason, [self::UNFETCHED, self::UNDEFINED, self::DECODING_FAILURE], true)) {
            throw new \InvalidArgumentException('Unknown unavailable reason.');
        }
        $this->reason = $reason;
        $this->raw = self::snapshot($raw);
    }

    public function __get($name)
    {
        if ($name === 'reason') {
            return $this->reason;
        }
        if ($name === 'raw') {
            return self::snapshot($this->raw);
        }

        return new self(self::UNDEFINED);
    }
}
