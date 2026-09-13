<?php

namespace Edge;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;

/**
 * Static facade over the Edge JSON:API.
 *
 * Endpoints are given relative to the API version root, so `customers` becomes
 * `https://api.tryedge.io/v2/customers`. The API speaks JSON:API 1.0 over
 * `application/vnd.api+json` and exposes GET, POST and PATCH only.
 */
class Client
{
    const VERSION = '2.0.0';
    const DEFAULT_BASE_URI = 'https://api.tryedge.io/v2/';
    const MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * Memoized Guzzle client. Deliberately built with no configuration so that nothing
     * which can change between calls — the API key above all — gets frozen into it.
     *
     * @var GuzzleClient|null
     */
    private static $client;

    /** @var string|null Resolved lazily so the environment is read as late as possible. */
    private static $baseUri;

    /** @var string */
    private static $userAgentSuffix = '';

    /** @var bool|string TLS verification: true, false, or a path to a CA bundle. */
    private static $verify = true;

    /**
     * Point the SDK at a different API root.
     *
     * Accepts a full root (`https://api.tryedge.io/v2/`) or a bare host
     * (`https://api.tryedge.test:4001`), which gets `/v2` appended.
     */
    public static function setBaseUri($baseUri)
    {
        self::$baseUri = self::normalizeBaseUri($baseUri);
    }

    public static function getBaseUri()
    {
        if (self::$baseUri === null) {
            $env = getenv('EDGE_API_BASE_URI');

            self::$baseUri = self::normalizeBaseUri(
                ($env !== false && $env !== '') ? $env : self::DEFAULT_BASE_URI
            );
        }

        return self::$baseUri;
    }

    /**
     * Control TLS verification. Only useful for pointing at a local gateway with a
     * self signed certificate — never disable this against a real environment.
     *
     * @param bool|string $verify true, false, or a path to a CA bundle
     */
    public static function setVerifySsl($verify)
    {
        self::$verify = $verify;
    }

    /**
     * Append an application identity to the User-Agent, e.g. "WooCommerce/9.1.2".
     */
    public static function setUserAgentSuffix($suffix)
    {
        self::$userAgentSuffix = trim((string) $suffix);
    }

    public static function userAgent()
    {
        $userAgent = sprintf('Edge PHP SDK %s (PHP %s)', self::VERSION, PHP_VERSION);

        return self::$userAgentSuffix === '' ? $userAgent : $userAgent . ' ' . self::$userAgentSuffix;
    }

    /**
     * GET a resource or collection.
     *
     * The second argument becomes the query string, and supports the JSON:API
     * parameters: filter[…], include, fields[…], sort and page[…].
     */
    public static function get($endpoint, $body = [])
    {
        // Guzzle's query option replaces the URI's query string outright, so only set
        // it when there is something to send — otherwise an endpoint that carries its
        // own query, such as a pagination link, would lose it.
        $options = empty($body) ? [] : ['query' => $body];

        return self::request('GET', $endpoint, $options);
    }

    /**
     * POST a JSON:API document to create a resource.
     */
    public static function create($endpoint, $body = [])
    {
        return self::request('POST', $endpoint, [
            'json' => $body,
            'headers' => ['Content-Type' => self::MEDIA_TYPE],
        ]);
    }

    /**
     * PATCH a JSON:API document to update a resource.
     *
     * The API has no PUT — updates are always a partial merge.
     */
    public static function update($endpoint, $body = [])
    {
        return self::request('PATCH', $endpoint, [
            'json' => $body,
            'headers' => ['Content-Type' => self::MEDIA_TYPE],
        ]);
    }

    /**
     * Alias for update(), named after the verb it actually sends.
     */
    public static function patch($endpoint, $body = [])
    {
        return self::update($endpoint, $body);
    }

    /**
     * Confirm a resource created in the unconfirmed state.
     *
     * Creating a payment demand or a payment subscription defaults it to
     * `confirmed: false`; this is the second half of that flow.
     *
     *     $demand = Client::create('payment_demands', [...]);
     *     Client::confirm('payment_demands', $demand->data->id);
     *
     * Supported by `payment_demands` and `payment_subscriptions`.
     */
    public static function confirm($type, $id, $attributes = [])
    {
        $type = trim($type, '/');

        return self::update($type . '/' . rawurlencode($id) . '/confirm', [
            'data' => [
                'id' => (string) $id,
                'type' => $type,
                // An empty PHP array encodes to a JSON list; the server wants an object.
                'attributes' => empty($attributes) ? new \stdClass() : $attributes,
            ],
        ]);
    }

    /**
     * Supply the underlying HTTP client, for tests or for custom middleware.
     */
    public static function setHttpClient(GuzzleClient $client)
    {
        self::$client = $client;
    }

    /**
     * Discard all configuration, returning the client to its defaults.
     */
    public static function reset()
    {
        self::$client = null;
        self::$baseUri = null;
        self::$userAgentSuffix = '';
        self::$verify = true;
    }

    private static function getClient()
    {
        if (!self::$client) {
            self::$client = new GuzzleClient();
        }

        return self::$client;
    }

    private static function request($method, $endpoint, array $options = [])
    {
        $headers = [
            'User-Agent' => self::userAgent(),
            // Read at call time, so changing the key between requests takes effect.
            'Authorization' => 'Bearer ' . Auth::getApiKey(),
            'Accept' => self::MEDIA_TYPE,
        ];

        if (isset($options['headers'])) {
            $headers = array_merge($headers, $options['headers']);
        }

        $options['headers'] = $headers;

        if (!isset($options['verify'])) {
            $options['verify'] = self::$verify;
        }

        try {
            $response = self::getClient()->request($method, self::url($endpoint), $options);

            return (new Response($response))->toObject();
        } catch (RequestException $e) {
            throw Exception::fromRequestException($e);
        } catch (TransferException $e) {
            // Connection failures and redirect loops are not RequestExceptions in
            // Guzzle 7, so they need catching separately.
            throw new Exception($e->getMessage(), 0, $e);
        }
    }

    /**
     * Resolve an endpoint against the base URI.
     *
     * Done by hand rather than through Guzzle's base_uri because RFC 3986 resolution
     * silently drops the version prefix when the endpoint has a leading slash.
     */
    private static function url($endpoint)
    {
        $endpoint = ltrim(trim($endpoint), '/');

        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $endpoint)) {
            // Absolute URLs are only honoured for the configured API itself. Every
            // request carries the secret key, so following a URL to any other origin
            // would hand that key to whoever supplied it.
            return self::assertSameOrigin($endpoint);
        }

        return self::getBaseUri() . $endpoint;
    }

    private static function assertSameOrigin($url)
    {
        $target = parse_url($url);

        if ($target === false) {
            throw new Exception('Could not parse endpoint URL: ' . $url);
        }

        $base = parse_url(self::getBaseUri());
        $targetOrigin = self::origin($target);
        $baseOrigin = self::origin($base);

        if ($targetOrigin !== $baseOrigin) {
            throw new Exception(sprintf(
                'Refusing to send Edge credentials to %s. Endpoints must be relative to %s.',
                $targetOrigin,
                $baseOrigin
            ));
        }

        return $url;
    }

    private static function origin(array $parts)
    {
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $host = isset($parts['host']) ? strtolower($parts['host']) : '';
        $port = isset($parts['port']) ? (int) $parts['port'] : self::defaultPort($scheme);

        return $scheme . '://' . $host . ':' . $port;
    }

    private static function defaultPort($scheme)
    {
        return $scheme === 'http' ? 80 : 443;
    }

    private static function normalizeBaseUri($baseUri)
    {
        $baseUri = rtrim(trim($baseUri), '/');
        $parts = parse_url($baseUri);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new Exception('Invalid Edge API base URI: ' . $baseUri);
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new Exception('Edge API base URI must be http or https: ' . $baseUri);
        }

        if (!isset($parts['path']) || $parts['path'] === '') {
            $baseUri .= '/v2';
        }

        return $baseUri . '/';
    }
}
