<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('telegram', function() {
    $updates = Telegram::getUpdates([
        'timeout' => 60,
        'allowed_updates' => ['message']
    ]);

    $updatesInsert = [];
    $chatsInsert = [];
    $messagesInsert = [];
    $commandsInsert = [];

    foreach ($updates as $update) {
        $updatesInsert[] = [
            'telegram_update_id' => $update['update_id'],
        ];

        $message = $update['message'];
        $telegram_chat_id = $message['chat']['id'];
        $username = $message['chat']['username'];
        $text = $message['text'];
        $telegram_message_id = $message['message_id'];

        if (substr($text, 0, 1) === '/') {
            // Collect for 'chats' table
            $chatsInsert[] = [
                'username' => $username,
                'telegram_chat_id' => $telegram_chat_id,
                'telegram_update_id' => $update['update_id'],
            ];

            // Collect for 'messages' table
            $messagesInsert[] = [
                'text' => $text,
                'telegram_chat_id' => $telegram_chat_id,
                'telegram_message_id' => $telegram_message_id,
            ];

            // Collect for 'commands' table
            $commandsInsert[] = [
                'handled' => 0,
                'telegram_message_id' => $telegram_message_id,
            ];
        }
    }

    DB::transaction(function () {
        if (!empty($updatesInsert)) {
            DB::table('updates')->insertOrIgnore($updatesInsert);
        }
        if (!empty($chatsInsert)) {
            DB::table('chats')->insertOrIgnore($chatsInsert);  // consider insertOrIgnore if duplicates are possible
        }
        if (!empty($messagesInsert)) {
            DB::table('messages')->insertOrIgnore($messagesInsert);
        }
        if (!empty($commandsInsert)) {
            DB::table('commands')->insertOrIgnore($commandsInsert);
        }
    });
});

