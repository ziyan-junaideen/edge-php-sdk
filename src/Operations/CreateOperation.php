<?php

namespace Edge\Operations;

use Edge\ApiClient;
use Edge\ResourceResult;

/** Opt-in operation for an Edge\ApiResource subclass. */
trait CreateOperation
{
    /** @return ResourceResult */
    public static function create(ApiClient $client, array $options = [])
    {
        return static::requestResource($client, 'create', null, $options);
    }
}
