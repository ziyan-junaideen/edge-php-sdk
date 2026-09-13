<?php

namespace Edge;

use Edge\Internal\ReadOnlyValue;

/** Generic resource foundation. Construction and property access never perform HTTP. */
class Resource extends ReadOnlyValue
{
    private $raw;
    private $attributes;
    private $relationships = [];
    private $unfetched;

    /**
     * @param array|\stdClass $resource One JSON:API resource object, not a document
     * @param array $schema dates: field names; money: property => [cents field, currency field]
     * @param string[] $unfetched Fields known to have been excluded by sparse selection
     */
    public function __construct($resource, array $schema = [], array $unfetched = [])
    {
        $members = self::members($resource);
        $this->raw = self::snapshot($resource);
        $this->attributes = self::snapshot(self::members($members['attributes'] ?? []));
        $this->unfetched = $unfetched;
        foreach ($schema['dates'] ?? [] as $field) {
            if (array_key_exists($field, $this->attributes)) {
                $this->attributes[$field] = ValueDecoder::timestamp($this->attributes[$field]);
            }
        }
        foreach ($schema['money'] ?? [] as $name => $pair) {
            // A future wire attribute wins over a derived convenience property.
            if (!array_key_exists($name, $this->attributes)) {
                $reason = in_array($pair[0], $unfetched, true) || in_array($pair[1], $unfetched, true)
                    ? Unavailable::UNFETCHED : Unavailable::UNDEFINED;
                $this->attributes[$name] = ValueDecoder::money(
                    self::members($members['attributes'] ?? []), $pair[0], $pair[1], $reason
                );
            }
        }
        foreach (self::members($members['relationships'] ?? []) as $name => $relationship) {
            $this->relationships[$name] = new Relationship($relationship);
        }
    }

    public function __get($name)
    {
        $members = (array) $this->raw;
        if ($name === 'id' || $name === 'type') {
            return array_key_exists($name, $members) ? self::snapshot($members[$name]) : $this->missing($name);
        }
        if (array_key_exists($name, $this->attributes)) {
            return self::snapshot($this->attributes[$name]);
        }
        if (array_key_exists($name, $this->relationships)) {
            return $this->relationships[$name];
        }

        return $this->missing($name);
    }

    private function missing($name)
    {
        return new Unavailable(in_array($name, $this->unfetched, true) ? Unavailable::UNFETCHED : Unavailable::UNDEFINED);
    }

    public function getAttributes()
    {
        return self::snapshot($this->attributes);
    }

    public function getRelationships()
    {
        return $this->relationships;
    }

    public function getLinks()
    {
        return $this->getMember('links');
    }

    public function getMeta()
    {
        return $this->getMember('meta');
    }

    /** Access raw top-level members, including extensions and original attributes. */
    public function getMember($name)
    {
        $members = (array) $this->raw;

        return array_key_exists($name, $members) ? self::snapshot($members[$name]) : new Unavailable(Unavailable::UNDEFINED);
    }

    public function getRaw()
    {
        return self::snapshot($this->raw);
    }
}
