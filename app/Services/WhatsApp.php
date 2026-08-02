<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\ResponseInterface;
use Telegram\Bot\Laravel\Facades\Telegram;

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

        try {
            $promise = $this->client->getAsync($this->apiUrl . '/messages', [
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

            return $promise;
        } catch (\Throwable $e) {
            dd($e);               // See exactly what went wrong
        }
    }

    public function sendMessage(array $params = []): ResponseInterface
    {
        $chat_id = $params['chat_id'] ?? 60;
        $text  = $params['text']  ?? 0;

        $chat = DB::table('chats')
            ->join('updates', 'updates.telegram_update_id', 'chats.telegram_update_id')
            ->select()
            ->where([
                'telegram_chat_id' => $chat_id,
            ])
            ->first();

        $source = $chat->source;

        if ($source !== 'whatsapp') {
            Telegram::sendMessage([
                'chat_id' => $chat_id,
                'text'    => $text,
            ]);
        }

        echo 'SEND_MESSAGE'.$chat->username.$text.$source;

        try {
            $promise = $this->client->post($this->apiUrl . '/send', [
                'json'           => [
                    'token'   => $this->token,
                    'phone'  => $chat->username,
                    'message' => $text,
                ],
            ]);

            echo 'HAS_SENT'.$this->apiUrl;

            return $promise;
        } catch (\Throwable $e) {
            dd($e);               // See exactly what went wrong
        }
    }
}
