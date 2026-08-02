<?php

return [
    'api_url' => env('WHATSAPP_LONG_POLLING_URL'),
    'token' => env('WHATSAPP_LONG_POLLING_TOKEN'),
    'bots' => [
        'mybot' => [
            'token' => env('APP_ENV', 'local') === 'prod' ? env('PROD_TELEGRAM_BOT_TOKEN', 'YOUR-BOT-TOKEN') : env('TEST_TELEGRAM_BOT_TOKEN', 'YOUR-BOT-TOKEN'),
            'certificate_path' => env('TELEGRAM_CERTIFICATE_PATH', 'YOUR-CERTIFICATE-PATH'),
            'webhook_url' => env('TELEGRAM_WEBHOOK_URL', 'YOUR-BOT-WEBHOOK-URL'),
            /*
             * @see https://core.telegram.org/bots/api#update
             */
            'allowed_updates' => null,
            'commands' => [
                // Acme\Project\Commands\MyTelegramBot\BotCommand::class
            ],
        ],
    ],
];
