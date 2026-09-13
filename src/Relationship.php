<?php

namespace Edge;

use Edge\Internal\ReadOnlyValue;

/** Relationship envelope; data is Linkage, Linkage[], null, or Unavailable. */
final class Relationship extends ReadOnlyValue
{
    private $raw;
    private $data;

    public function __construct($relationship)
    {
        $members = self::members($relationship);
        $this->raw = self::snapshot($relationship);
        $this->data = array_key_exists('data', $members)
            ? self::decodeData($members['data']) : new Unavailable(Unavailable::UNFETCHED);
    }

    private static function decodeData($data)
    {
        if ($data === null) {
            return null;
        }
        try {
            if (is_array($data) && ($data === [] || array_keys($data) === range(0, count($data) - 1))) {
                return array_map(function ($identifier) {
                    return new Linkage($identifier);
                }, $data);
            }

            return new Linkage($data);
        } catch (\InvalidArgumentException $e) {
            return new Unavailable(Unavailable::DECODING_FAILURE, $data);
        }
    }

    public function __get($name)
    {
        if ($name === 'data') {
            return $this->data;
        }
        $members = (array) $this->raw;

        return array_key_exists($name, $members) ? self::snapshot($members[$name]) : new Unavailable(Unavailable::UNDEFINED);
    }

    public function getRaw()
    {
        return self::snapshot($this->raw);
    }
}
