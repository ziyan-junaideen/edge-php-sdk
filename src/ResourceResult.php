<?php

namespace Edge;

use Edge\Internal\ReadOnlyValue;
use Edge\Internal\ResourceIndex;

/** Read-only decoded JSON:API document with its own HTTP metadata and identity map. */
final class ResourceResult extends ReadOnlyValue
{
    private $values;

    public function __construct(Response $response, ?ResourceDecoder $decoder = null, array $query = [])
    {
        $body = $response->getBody();
        $document = trim($body) === '' ? null : json_decode($body);
        if (trim($body) !== '' && (json_last_error() !== JSON_ERROR_NONE || !($document instanceof \stdClass))) {
            throw new Exception('Expected a JSON:API document object.', $response->getStatusCode());
        }
        $members = (array) $document;
        $decoder = $decoder ?? new ResourceDecoder();
        $index = new ResourceIndex();
        try {
            $data = $members['data'] ?? null;
            if (is_array($data)) {
                $data = array_map(function ($record) use ($decoder, $index, $query) {
                    return $decoder->decodeResource($record, $index, $query);
                }, $data);
            } elseif ($data !== null) {
                $data = $decoder->decodeResource($data, $index, $query);
            }
            $included = $members['included'] ?? [];
            if (!is_array($included)) {
                throw new \InvalidArgumentException('Included must be a resource array.');
            }
            $included = array_map(function ($record) use ($decoder, $index, $query) {
                return $decoder->decodeResource($record, $index, $query);
            }, $included);
        } catch (\InvalidArgumentException $e) {
            throw new Exception('Invalid JSON:API resource document: ' . $e->getMessage(), $response->getStatusCode(), $e);
        }
        $this->values = [
            'data' => $data,
            'included' => $included,
            'links' => array_key_exists('links', $members) ? $members['links'] : new Unavailable(Unavailable::UNDEFINED),
            'meta' => array_key_exists('meta', $members) ? $members['meta'] : new Unavailable(Unavailable::UNDEFINED),
            'status' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'raw' => $document,
            'query' => self::snapshot($query),
        ];
    }

    public function __get($name)
    {
        return array_key_exists($name, $this->values)
            ? self::snapshot($this->values[$name]) : new Unavailable(Unavailable::UNDEFINED);
    }
}
