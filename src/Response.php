<?php

namespace Edge;

use Psr\Http\Message\ResponseInterface;

class Response
{
    private $response;

    public function __construct(ResponseInterface $response)
    {
        $this->response = $response;
    }

    public function getStatusCode()
    {
        return $this->response->getStatusCode();
    }

    public function getHeaders()
    {
        return $this->response->getHeaders();
    }

    public function getBody()
    {
        $body = $this->response->getBody()->getContents();
        $this->response->getBody()->rewind(); // Rewind the stream for further reads

        return $body;
    }

    public function toArray()
    {
        return json_decode($this->getBody(), true);
    }

    public function toObject()
    {
        return json_decode($this->getBody());
    }
}
