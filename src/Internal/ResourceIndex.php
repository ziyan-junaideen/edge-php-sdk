<?php

namespace Edge\Internal;

use Edge\Linkage;
use Edge\Resource;

/** @internal One identity map per result; relationships look up references on access. */
final class ResourceIndex
{
    private $resources = [];

    public function find($type, $id)
    {
        return $this->resources[$type][$id] ?? null;
    }

    public function add(Resource $resource)
    {
        $this->resources[$resource->type][$resource->id] = $resource;
    }

    public function resolve($data)
    {
        if ($data instanceof Linkage) {
            return $this->find($data->type, $data->id) ?? $data;
        }
        if (is_array($data)) {
            return array_map([$this, 'resolve'], $data);
        }

        return $data;
    }
}
