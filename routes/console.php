<?php

use App\Services\TelegramService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Telegram\Bot\Laravel\Facades\Telegram;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('telegram', function() {
    $offset = DB::table('updates')->max('telegram_update_id')+1;

    $updates = Telegram::getUpdates([
        'timeout' => 60,
        'allowed_updates' => ['message'],
        'offset' => $offset
    ]);
    // dd([$updates, $offset]);

    $updatesInsert = [];
    $chatsInsert = [];
    $messagesInsert = [];
    $commandsInsert = [];

    foreach ($updates as $update) {
        $updatesInsert[] = [
            'telegram_update_id' => $update['update_id'],
        ];

        $message = $update['message'] ?? false;
        if (!$message) {
            continue;
        }

        $telegram_chat_id = $message['chat']['id'];
        $username = $message['chat']['username'];
        $text = $message['text'];
        $telegram_message_id = $message['message_id'];

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

        if (substr($text, 0, 1) === '/') {
            // Collect for 'commands' table
            $commandsInsert[] = [
                'handled' => 0,
                'telegram_message_id' => $telegram_message_id,
            ];
        }
    }

    DB::transaction(function () use($updatesInsert, $chatsInsert, $messagesInsert, $commandsInsert) {
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

    $unhandled_commands = DB::table('commands')->join('messages', 'commands.telegram_message_id', 'messages.telegram_message_id')->select()->where([
        'handled' => 0,
    ])->get();

    DB::transaction(function () use($unhandled_commands) {
        foreach ($unhandled_commands as $unhandled_command) {
            Telegram::sendMessage([
                'chat_id' => $unhandled_command->telegram_chat_id,
                'text' => TelegramService::handleCommand($unhandled_command),
            ]);
        }

        DB::table('commands')->where([
            'handled' => 0,
        ])
        ->update([
            'handled' => 1,
        ]);
    });

});

