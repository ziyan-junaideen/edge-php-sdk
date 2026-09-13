<?php

namespace Edge\Operations;

use Edge\ApiClient;
use Edge\ResourceResult;

/** Opt-in operation for an Edge\ApiResource subclass. */
trait UpdateOperation
{
    /** @return ResourceResult */
    public static function update(ApiClient $client, $idOrResource, array $options = [])
    {
        return static::requestResource($client, 'update', $idOrResource, $options);
    }
}
