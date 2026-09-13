<?php

namespace Edge\Operations;

use Edge\ApiClient;
use Edge\ResourceResult;

/** Opt-in operation for an Edge\ApiResource subclass. */
trait ShowOperation
{
    /** @return ResourceResult */
    public static function show(ApiClient $client, $idOrResource, array $query = [])
    {
        return static::requestResource($client, 'show', $idOrResource, $query);
    }
}
