<?php

namespace Edge;

use Edge\Internal\ReadOnlyValue;

/** An unresolved JSON:API resource identifier, including any metadata/extensions. */
final class Linkage extends ReadOnlyValue
{
    private $raw;

    public function __construct($identifier)
    {
        $members = self::members($identifier);
        if (!isset($members['type'], $members['id']) || !is_string($members['type']) || !is_string($members['id'])) {
            throw new \InvalidArgumentException('Linkage requires string type and id.');
        }
        $this->raw = self::snapshot($identifier);
    }

    public function __get($name)
    {
        $members = (array) $this->raw;

        return array_key_exists($name, $members) ? self::snapshot($members[$name]) : new Unavailable(Unavailable::UNDEFINED);
    }

    public function getRaw()
    {
        return self::snapshot($this->raw);
    }
}
