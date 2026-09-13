<?php

namespace Edge;

/**
 * Holds the secret API key used to authenticate server side requests.
 *
 * Secret keys look like `ept_live_s…` or `ept_sandbox_s…`; the prefix alone selects
 * live or sandbox data, the URL is identical for both. Publishable keys (`ept_live_b…`,
 * `ept_sandbox_b…`) are never given to this SDK — they belong to the browser, where the
 * Edge JS SDK uses them to mount the hosted payment form.
 */
class Auth
{
    private static $apiKey;

    public static function setApiKey($apiKey)
    {
        // Keys pasted into settings screens routinely arrive with surrounding whitespace,
        // and a stray newline makes an invalid HTTP header value. PSR-7 rejects that with
        // an \InvalidArgumentException, which is not a Guzzle exception and so escapes
        // Client's error handling entirely.
        self::$apiKey = is_string($apiKey) ? trim($apiKey) : $apiKey;
    }

    public static function getApiKey()
    {
        if (!self::$apiKey) {
            throw new Exception('API key not set. Use Edge\Auth::setApiKey() to set it.');
        }

        return self::$apiKey;
    }
}
