<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;

class WhatsApp
{
    private Client $client;
    private string $apiUrl;
    private string $token;

    public function __construct(Client $client)
    {
        $this->client = $client; // ← shared CurlMultiHandler-backed client
        $this->apiUrl = config('whatsapp.api_url');
        $this->token  = config('whatsapp.token');
    }

    /**
     * Async long-poll for WhatsApp updates.
     * Returns a PENDING promise — does NOT block.
     */
    public function getUpdatesAsync(array $params = []): PromiseInterface
    {
        $timeout = $params['timeout'] ?? 60;
        $offset  = $params['offset']  ?? 0;

        return $this->client->getAsync($this->apiUrl . 'messages', [
            'query'           => [
                'token'   => $this->token,
                'offset'  => $offset,
                'timeout' => $timeout,
            ],
            'timeout'         => $timeout + 5,
            'connect_timeout' => 10,
            'headers'         => [
                'Accept'        => 'application/json',
            ],
        ]);
    }
}
