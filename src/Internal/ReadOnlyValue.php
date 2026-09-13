<?php

namespace Edge\Internal;

/** @internal Shared PHP 7.3-compatible property protection and JSON snapshots. */
abstract class ReadOnlyValue
{
    final public function __set($name, $value)
    {
        throw new \LogicException('Properties of ' . get_class($this) . ' are read-only.');
    }

    final public function __unset($name)
    {
        throw new \LogicException('Properties of ' . get_class($this) . ' are read-only.');
    }

    public function __isset($name)
    {
        $value = $this->__get($name);

        return $value !== null && !($value instanceof \Edge\Unavailable);
    }

    /** Copy JSON objects on input/output so nested objects cannot mutate stored data. */
    protected static function snapshot($value)
    {
        if (is_array($value)) {
            return array_map([self::class, 'snapshot'], $value);
        }
        if ($value instanceof \stdClass) {
            $copy = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $copy->$key = self::snapshot($item);
            }
            return $copy;
        }

        return $value;
    }

    protected static function members($value)
    {
        if (!is_array($value) && !($value instanceof \stdClass)) {
            throw new \InvalidArgumentException('Expected a JSON object or associative array.');
        }

        return (array) $value;
    }
}
