<?php

namespace Edge;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;

/**
 * An isolated Edge JSON:API client. Low-level operations return decoded documents.
 * Configuration is captured at construction and never reads global authentication.
 */
class ApiClient
{
    const VERSION = Client::VERSION;
    const DEFAULT_BASE_URI = Client::DEFAULT_BASE_URI;
    const MEDIA_TYPE = Client::MEDIA_TYPE;

    private $apiKey;
    private $baseUri;
    private $verify;
    private $userAgentSuffix;
    private $client;

    /**
     * @param string $apiKey Secret API key
     * @param array $options base_uri, verify (bool or CA bundle path),
     *                       user_agent_suffix, and http_client (GuzzleClient)
     */
    public function __construct($apiKey, array $options = [])
    {
        $this->apiKey = is_string($apiKey) ? trim($apiKey) : $apiKey;

        if (!$this->apiKey) {
            throw new Exception('API key not set. Pass a secret key to Edge\\ApiClient.');
        }

        $this->baseUri = self::normalizeBaseUri($options['base_uri'] ?? self::DEFAULT_BASE_URI);
        $this->verify = $options['verify'] ?? true;
        $this->userAgentSuffix = trim((string) ($options['user_agent_suffix'] ?? ''));
        $this->setHttpClient($options['http_client'] ?? new GuzzleClient());
    }

    private function setHttpClient(GuzzleClient $client)
    {
        $this->client = $client;
    }

    public function getBaseUri()
    {
        return $this->baseUri;
    }

    public function userAgent()
    {
        $userAgent = sprintf('Edge PHP SDK %s (PHP %s)', self::VERSION, PHP_VERSION);

        return $this->userAgentSuffix === '' ? $userAgent : $userAgent . ' ' . $this->userAgentSuffix;
    }

    /**
     * GET a resource or collection.
     *
     * The second argument becomes the query string, and supports the JSON:API
     * parameters: filter[…], include, fields[…], sort and page[…].
     */
    public function get($endpoint, $body = [])
    {
        // Guzzle's query option replaces the URI's query string outright, so only set
        // it when there is something to send — otherwise an endpoint that carries its
        // own query, such as a pagination link, would lose it.
        $options = empty($body) ? [] : ['query' => $body];

        return $this->request('GET', $endpoint, $options);
    }

    /**
     * POST a JSON:API document to create a resource.
     */
    public function create($endpoint, $body = [])
    {
        return $this->request('POST', $endpoint, [
            'json' => $body,
            'headers' => ['Content-Type' => self::MEDIA_TYPE],
        ]);
    }

    /**
     * PATCH a JSON:API document to update a resource.
     *
     * The API has no PUT — updates are always a partial merge.
     */
    public function update($endpoint, $body = [])
    {
        return $this->request('PATCH', $endpoint, [
            'json' => $body,
            'headers' => ['Content-Type' => self::MEDIA_TYPE],
        ]);
    }

    /**
     * Alias for update(), named after the verb it actually sends.
     */
    public function patch($endpoint, $body = [])
    {
        return $this->update($endpoint, $body);
    }

    /**
     * Confirm a resource created in the unconfirmed state.
     *
     * Creating a payment demand or a payment subscription defaults it to
     * `confirmed: false`; this is the second half of that flow.
     *
     *     $demand = $client->create('payment_demands', [...]);
     *     $client->confirm('payment_demands', $demand->data->id);
     *
     * Supported by `payment_demands` and `payment_subscriptions`.
     */
    public function confirm($type, $id, $attributes = [])
    {
        $type = trim($type, '/');

        return $this->update($type . '/' . rawurlencode($id) . '/confirm', [
            'data' => [
                'id' => (string) $id,
                'type' => $type,
                // An empty PHP array encodes to a JSON list; the server wants an object.
                'attributes' => empty($attributes) ? new \stdClass() : $attributes,
            ],
        ]);
    }

    private function request($method, $endpoint, array $options = [])
    {
        return $this->requestResponse($method, $endpoint, $options)->toObject();
    }

    /**
     * Shared transport entry point for resource operations needing status and headers.
     * Each call returns its own response; no last-response state is stored.
     *
     * @internal
     * @return Response
     */
    public function requestResponse($method, $endpoint, array $options = [])
    {
        $headers = [
            'User-Agent' => $this->userAgent(),
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => self::MEDIA_TYPE,
        ];

        if (isset($options['headers'])) {
            $headers = array_merge($headers, $options['headers']);
        }

        $options['headers'] = $headers;

        if (!isset($options['verify'])) {
            $options['verify'] = $this->verify;
        }

        try {
            $response = $this->client->request($method, $this->url($endpoint), $options);

            // Injected clients may disable http_errors or omit its middleware.
            if ($response->getStatusCode() >= 400) {
                throw Exception::fromResponse($response);
            }

            return new Response($response);
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
    private function url($endpoint)
    {
        $endpoint = ltrim(trim($endpoint), '/');

        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $endpoint)) {
            // Absolute URLs are only honoured for the configured API itself. Every
            // request carries the secret key, so following a URL to any other origin
            // would hand that key to whoever supplied it.
            return $this->assertSameOrigin($endpoint);
        }

        return $this->getBaseUri() . $endpoint;
    }

    private function assertSameOrigin($url)
    {
        $target = parse_url($url);

        if ($target === false) {
            throw new Exception('Could not parse endpoint URL: ' . $url);
        }

        $base = parse_url($this->getBaseUri());
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

    /** @internal Shared with the legacy facade. */
    public static function normalizeBaseUri($baseUri)
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
