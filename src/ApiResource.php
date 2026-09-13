<?php

namespace Edge;

use Edge\Internal\ResourceRequest;

/** Resource endpoint foundation. Add only the supported Edge\Operations traits. */
abstract class ApiResource extends Resource
{
    const TYPE = '';
    const SCHEMA = [];
    const FIELDS = [];

    /** Override to register additional named included types in a fresh decoder. */
    protected static function resourceDecoder()
    {
        return (new ResourceDecoder())->register(static::TYPE, static::class, static::SCHEMA, static::FIELDS);
    }

    /** @internal All operations share encoding, transport, and query-aware decoding. */
    protected static function requestResource(ApiClient $client, $operation, $idOrResource, array $options)
    {
        list($method, $endpoint, $request, $query) = ResourceRequest::build(
            static::class, static::TYPE, $operation, $idOrResource, $options
        );
        $decoder = static::resourceDecoder();

        return $decoder->decode($client->requestResponse($method, $endpoint, $request), $query);
    }
}
