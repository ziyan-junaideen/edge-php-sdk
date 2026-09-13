<?php

namespace Edge\Operations;

use Edge\ApiClient;
use Edge\ResourceResult;

/** Opt-in operation for an Edge\ApiResource subclass. */
trait ListOperation
{
    /** @return ResourceResult */
    public static function list(ApiClient $client, array $query = [])
    {
        return static::requestResource($client, 'list', null, $query);
    }
}
