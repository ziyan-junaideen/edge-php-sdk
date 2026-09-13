<?php

namespace Edge\Internal;

use Edge\ApiClient;
use Edge\Linkage;
use Edge\Resource;

/** @internal JSON:API request encoding, independent of resource business rules. */
final class ResourceRequest
{
    public static function build($class, $type, $operation, $idOrResource, array $options)
    {
        if (!is_string($type) || !preg_match('/\A[a-zA-Z0-9_-]+\z/', $type)) {
            throw new \InvalidArgumentException('Resource TYPE must be a collection path segment.');
        }
        $endpoint = $type;
        $id = null;
        if (in_array($operation, ['show', 'update', 'confirm'], true)) {
            if ($idOrResource instanceof Resource) {
                if (!($idOrResource instanceof $class) || $idOrResource->type !== $type) {
                    throw new \InvalidArgumentException('Resource must match the called class and type.');
                }
                $idOrResource = $idOrResource->id;
            }
            if (!is_string($idOrResource) || $idOrResource === '') {
                throw new \InvalidArgumentException('Resource ID must be a nonempty string.');
            }
            $id = $idOrResource;
            // Dot-only IDs must not become URI navigation segments.
            $segment = $id === '.' || $id === '..' ? str_replace('.', '%2E', $id) : rawurlencode($id);
            $endpoint .= '/' . $segment;
        }

        $write = in_array($operation, ['create', 'update'], true);
        if ($write && array_diff(array_keys($options), ['attributes', 'relationships', 'query'])) {
            throw new \InvalidArgumentException('Write options must separate attributes, relationships, and query.');
        }
        $query = self::query($write ? ($options['query'] ?? []) : $options);
        $request = ['query' => $query];
        $method = 'GET';
        if ($write || $operation === 'confirm') {
            $method = $operation === 'create' ? 'POST' : 'PATCH';
            $data = ['type' => $type];
            if ($id !== null) {
                $data['id'] = $id;
            }
            $data['attributes'] = $write
                ? self::map($options['attributes'] ?? [], 'Attributes') : new \stdClass();
            if ($write) {
                $relationships = self::map($options['relationships'] ?? [], 'Relationships');
                $encoded = new \stdClass();
                foreach ($relationships as $name => $value) {
                    $encoded->{$name} = ['data' => self::relationship($value)];
                }
                $data['relationships'] = $encoded;
            } else {
                $endpoint .= '/confirm';
            }
            $request['json'] = ['data' => $data];
            $request['headers'] = ['Content-Type' => ApiClient::MEDIA_TYPE];
        }

        return [$method, $endpoint, $request, $query];
    }

    private static function query(array $query)
    {
        foreach (['include', 'sort'] as $key) {
            if (array_key_exists($key, $query)) {
                $query[$key] = self::stringList($query[$key]);
            }
        }
        if (array_key_exists('fields', $query)) {
            $fields = self::map($query['fields'], 'Fields');
            $query['fields'] = [];
            foreach ($fields as $type => $value) {
                $query['fields'][$type] = self::stringList($value);
            }
        }

        return $query;
    }

    private static function stringList($value)
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value) && array_values($value) === $value) {
            foreach ($value as $item) {
                if (!is_string($item)) {
                    throw new \InvalidArgumentException('Query lists must contain strings.');
                }
            }

            return implode(',', $value);
        }
        throw new \InvalidArgumentException('Query lists must be strings or arrays of strings.');
    }

    private static function map($value, $name)
    {
        if (!is_array($value) && !($value instanceof \stdClass)) {
            throw new \InvalidArgumentException($name . ' must be a map.');
        }
        foreach ((array) $value as $key => $unused) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException($name . ' must have string keys.');
            }
        }

        return (object) $value;
    }

    private static function relationship($value)
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value) && array_values($value) === $value) {
            return array_map([self::class, 'identifier'], $value);
        }

        return self::identifier($value);
    }

    private static function identifier($value)
    {
        if ($value instanceof Resource) {
            $value = ['type' => $value->type, 'id' => $value->id];
        }
        $linkage = $value instanceof Linkage ? $value : new Linkage($value);

        return (object) $linkage->getRaw();
    }
}
