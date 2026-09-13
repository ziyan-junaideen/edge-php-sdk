<?php

namespace Edge\Operations;

use Edge\ApiClient;
use Edge\ResourceResult;

/** Opt-in operation for an Edge\ApiResource subclass. */
trait ConfirmOperation
{
    /** @return ResourceResult */
    public static function confirm(ApiClient $client, $idOrResource, array $query = [])
    {
        return static::requestResource($client, 'confirm', $idOrResource, $query);
    }
}
