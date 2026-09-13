<?php

namespace Edge;

/** Explicit conversions shared by resource schemas; never guesses field mappings. */
final class ValueDecoder
{
    /** Decode RFC 3339 timestamps with up to six fractional digits. */
    public static function timestamp($value)
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || !preg_match(
            '/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/',
            $value,
            $parts
        )) {
            return new Unavailable(Unavailable::DECODING_FAILURE, $value);
        }

        $normalized = $parts[1] . '.' . str_pad($parts[2], 6, '0') . ($parts[3] === 'Z' ? '+00:00' : $parts[3]);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $normalized);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $date->format('Y-m-d\TH:i:s.uP') !== $normalized) {
            return new Unavailable(Unavailable::DECODING_FAILURE, $value);
        }

        return $date;
    }

    /** Both nulls mean null; partial/null/invalid pairs are decoding failures. */
    public static function money(array $attributes, $centsField, $currencyField, $absentReason = Unavailable::UNDEFINED)
    {
        $raw = array_intersect_key($attributes, array_flip([$centsField, $currencyField]));
        if (!$raw) {
            return new Unavailable($absentReason, $raw);
        }
        if (count($raw) !== 2) {
            return new Unavailable(Unavailable::DECODING_FAILURE, $raw);
        }
        if ($raw[$centsField] === null && $raw[$currencyField] === null) {
            return null;
        }
        try {
            return new Money($raw[$centsField], $raw[$currencyField]);
        } catch (\InvalidArgumentException $e) {
            return new Unavailable(Unavailable::DECODING_FAILURE, $raw);
        }
    }
}
