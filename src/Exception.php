<?php

namespace Edge;

use GuzzleHttp\Exception\RequestException;

/**
 * Raised for every failed Edge API call.
 *
 *     try {
 *         Edge\Client::create('payment_demands', $document);
 *     } catch (Edge\Exception $e) {
 *         $e->getMessage();     // "amount_cents must be greater than 0"
 *         $e->getStatusCode();  // 422
 *         $e->getErrors();      // the parsed JSON:API errors array
 *         $e->getRawBody();     // the response body, exactly as it arrived
 *         $e->getPrevious();    // the underlying Guzzle exception
 *     }
 *
 * Not every failure is a JSON:API error document: the gateway answers authentication
 * failures (401, 422) with plain text and returns an empty body for 403. getMessage()
 * is always something displayable regardless; getErrors() is empty in those cases.
 */
class Exception extends \Exception
{
    /** @var array The JSON:API `errors` array, or [] when the body had none. */
    protected $errors = [];

    /** @var string The response body exactly as it came off the wire. */
    protected $rawBody = '';

    /**
     * Build an exception from a failed Guzzle request.
     */
    public static function fromRequestException(RequestException $e)
    {
        $response = $e->getResponse();

        // A RequestException does not always carry a response — a malformed URI or a
        // transfer aborted mid-flight leaves it null.
        if ($response === null) {
            return new self($e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $rawBody = (string) $response->getBody();
        $errors = self::parseErrors($rawBody);

        $exception = new self(self::describe($status, $errors, $rawBody), $status, $e);
        $exception->rawBody = $rawBody;
        $exception->errors = $errors;

        return $exception;
    }

    /**
     * The JSON:API errors array. Empty for plain text and empty bodies.
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * The HTTP status code, or 0 when the request never got a response.
     */
    public function getStatusCode()
    {
        return $this->getCode();
    }

    /**
     * The response body exactly as it came off the wire.
     */
    public function getRawBody()
    {
        return $this->rawBody;
    }

    /**
     * Reduce a failure to one displayable line.
     */
    private static function describe($status, array $errors, $rawBody)
    {
        if (!empty($errors)) {
            $first = reset($errors);

            foreach (['detail', 'title', 'code'] as $key) {
                if (is_array($first) && isset($first[$key]) && $first[$key] !== '') {
                    return (string) $first[$key];
                }
            }
        }

        $body = trim($rawBody);

        // Auth failures come back as a bare phrase such as "Unauthorized". Anything
        // longer, or that opens like JSON or HTML, is a payload rather than a message.
        if ($body !== '' && strlen($body) <= 200 && $body[0] !== '{' && $body[0] !== '<') {
            return $body;
        }

        return 'Edge API error (HTTP ' . $status . ')';
    }

    private static function parseErrors($body)
    {
        $decoded = json_decode($body, true);

        if (!is_array($decoded) || !isset($decoded['errors']) || !is_array($decoded['errors'])) {
            return [];
        }

        return $decoded['errors'];
    }
}
