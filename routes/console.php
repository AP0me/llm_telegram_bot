<?php

use App\Services\LLMSession;
use App\Services\LongPollingService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->info(json_encode(LLMSession::getMessagesOfOpenSessions([1])));
});

Artisan::command('telegram_whatsapp:longpoll', function () {
    LongPollingService::run();
})->purpose('Start infinite long‑polling for Telegram and WhatsApp');

Schedule::command('telegram_whatsapp:longpoll')->everySecond()->withoutOverlapping(3600);

Artisan::command('whatsapp_telegram', function () {
    $client = new Client([
        'base_uri' => 'http://localhost:8080',
        'timeout'  => 10.0,
        'headers'  => [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ],
    ]);

    $payload = [
        'phone'   => '209637404090446@lid',
        'message' => 'Hello World',
    ];

    try {
        $response = $client->post('send', [
            'json' => $payload, // sends JSON-encoded body
        ]);

        $statusCode = $response->getStatusCode();
        $body       = $response->getBody()->getContents();
        $data       = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON response: ' . json_last_error_msg());
            return;
        }

        $this->info("Status: {$statusCode}");
        $this->info("Repo Name: " . ($data['name'] ?? 'N/A'));
    } catch (RequestException $e) {
        $this->error("Request failed: " . $e->getMessage());
        if ($e->hasResponse()) {
            $this->error("Response: " . $e->getResponse()->getBody()->getContents());
        }
    }
})->describe('Send a WhatsApp/Telegram message via local API');

