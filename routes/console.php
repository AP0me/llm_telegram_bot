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
    ]);

    try {
        $response = $client->request('GET', 'messages');
        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);

        echo "Status: " . $statusCode . "\n";
        echo "Repo Name: " . $data['name'] . "\n";
    } catch (RequestException $e) {
        echo "Error: " . $e->getMessage();
    }
});
