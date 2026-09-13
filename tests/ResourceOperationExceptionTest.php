<?php

namespace Edge\Tests;

use Edge\ApiClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;

/** The shared error contract must also hold through resource decoding. */
class ResourceOperationExceptionTest extends ExceptionTest
{
    private $client;

    protected function willRespondWith(array $responses)
    {
        $this->client = new ApiClient('ept_sandbox_s_test', [
            'http_client' => new GuzzleClient([
                'handler' => HandlerStack::create(new MockHandler($responses)),
            ]),
        ]);
    }

    protected function request()
    {
        return OperationResource::show($this->client, 'test-id');
    }
}
