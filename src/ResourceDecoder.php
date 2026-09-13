<?php

namespace Edge;

use Edge\Internal\ResourceIndex;

/** Explicit, instance-local resource registry shared by primary and included records. */
final class ResourceDecoder
{
    private $types = [];

    public function __construct()
    {
        $this->register(Customer::TYPE, Customer::class, Customer::SCHEMA, Customer::FIELDS);
        $this->register(ConsumerAddress::TYPE, ConsumerAddress::class, ConsumerAddress::SCHEMA, ConsumerAddress::FIELDS);
        $this->register(PaymentMethod::TYPE, PaymentMethod::class, PaymentMethod::SCHEMA, PaymentMethod::FIELDS);
        $this->register(PaymentDemand::TYPE, PaymentDemand::class, PaymentDemand::SCHEMA, PaymentDemand::FIELDS);
        $this->register(PaymentSubscription::TYPE, PaymentSubscription::class, PaymentSubscription::SCHEMA, PaymentSubscription::FIELDS);
    }

    /**
     * Classes must inherit Resource's constructor contract. Fields lists known wire
     * attributes AND relationships, used to identify sparse omissions, not to filter data.
     * Built-in types are registered by default; explicit registrations override them
     * for this decoder only. Unregistered types use Resource without inferred mappings.
     */
    public function register($type, $class = Resource::class, array $schema = [], array $fields = [])
    {
        if (!is_string($type) || $type === '' || !is_string($class)
            || !is_a($class, Resource::class, true) || !(new \ReflectionClass($class))->isInstantiable()) {
            throw new \InvalidArgumentException('Register a nonempty type and an instantiable Resource class.');
        }
        $this->types[$type] = [$class, $schema, $fields];

        return $this;
    }

    public function decode(Response $response, array $query = [])
    {
        return new ResourceResult($response, $this, $query);
    }

    /** @internal First occurrence wins; primary records are indexed before included. */
    public function decodeResource($raw, ResourceIndex $index, array $query)
    {
        // Apply the same identity validation used by relationship identifiers.
        $identity = new Linkage($raw);
        $existing = $index->find($identity->type, $identity->id);
        if ($existing !== null) {
            return $existing;
        }
        list($class, $schema, $fields) = $this->types[$identity->type] ?? [Resource::class, [], []];
        $unfetched = [];
        if (isset($query['fields']) && array_key_exists($identity->type, $query['fields'])) {
            $selected = $query['fields'][$identity->type];
            if (is_string($selected)) {
                $selected = $selected === '' ? [] : array_map('trim', explode(',', $selected));
            }
            if (!is_array($selected)) {
                throw new \InvalidArgumentException('Sparse fields must be a string or array.');
            }
            $known = array_merge($fields, $schema['dates'] ?? []);
            foreach ($schema['money'] ?? [] as $pair) {
                $known = array_merge($known, $pair);
            }
            $unfetched = array_values(array_diff($known, $selected));
        }
        $resource = new $class($raw, $schema, $unfetched, $index);
        $index->add($resource);

        return $resource;
    }
}
