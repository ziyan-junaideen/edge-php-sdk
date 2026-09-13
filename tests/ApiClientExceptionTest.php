<?php

namespace Edge\Tests;

use Edge\ApiClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;

/** Run the same error contract against both client interfaces. */
class ApiClientExceptionTest extends ExceptionTest
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
        return $this->client->get('customers');
    }
}
