<?php<?php

use App\Services\LLMSession;
use App\Services\OpenRouter;
use App\Services\TelegramService;
use App\Services\Tool;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Mcp\Exception\ConnectionException;
use Telegram\Bot\Laravel\Facades\Telegram;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('telegram', function () {
    $offset = DB::table('updates')->max('telegram_update_id') + 1;

    $updates = [];
    $retryCount = 0;
    $retryDelay = 1;

    while ($retryCount < 5) {
        try {
            $updates = Telegram::getUpdates([
                'timeout'         => 60,
                'allowed_updates' => ['message'],
                'offset'          => $offset,
            ]);

            $retryCount = 0;
            break;
        }
        catch (\Telegram\Bot\Exceptions\TelegramSDKException $e) {
            $this->info("Telegram timed out (attempt {$retryCount}), retrying in {$retryDelay}s...");
            sleep($retryDelay);
            $retryDelay = min($retryDelay * 2, 30);
            continue;
        }
        catch (\Throwable $e) {
            $this->info('Unexpected error: ' . $e->getMessage());
            throw $e;
        }
    }

    $llm_sessions = DB::table('llm_sessions')
        ->join('commands', 'llm_sessions.start_command_id', 'commands.id')
        ->join('messages', 'commands.telegram_message_id', 'messages.telegram_message_id')
        ->select()
        ->where([
            'end_command_id' => null,
        ])
        ->get();

    $llm_sessions_by_telegram_chat_id = [];
    $llm_session_ids = [];
    foreach ($llm_sessions as $llm_session) {
        $llm_sessions_by_telegram_chat_id[$llm_session->telegram_chat_id] = $llm_session;
        $llm_session_ids[] = $llm_session->id;
    }

    $updatesInsert = [];
    $chatsInsert = [];
    $messagesInsert = [];
    $commandsInsert = [];
    $promptsInsert = [];

    foreach ($updates as $update) {
        $updatesInsert[] = [
            'telegram_update_id' => $update['update_id'],
        ];

        $message = $update['message'] ?? false;
        if (!$message) {
            continue;
        }

        $telegram_chat_id = $message['chat']['id'];
        $username = $message['chat']['username'] ?? false;
        $text = $message['text'] ?? false;
        $telegram_message_id = $message['message_id'];

        if (!isset($text) || !isset($username)) {
            continue;
        }

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
            $commandsInsert[] = [
                'handled' => false,
                'telegram_message_id' => $telegram_message_id,
            ];
        }
        else {
            $open_llm_session = $llm_sessions_by_telegram_chat_id[$telegram_chat_id] ?? false;
            if ($open_llm_session) {
                $promptsInsert[] = [
                    'answered' => false,
                    'telegram_message_id' => $telegram_message_id,
                    'llm_session_id' => $open_llm_session->id,
                ];
            }
        }
    }

    DB::transaction(function () use($promptsInsert, $updatesInsert, $chatsInsert, $messagesInsert, $commandsInsert) {
        if (!empty($updatesInsert)) {
            DB::table('updates')->insertOrIgnore($updatesInsert);
        }
        if (!empty($chatsInsert)) {
            DB::table('chats')->insertOrIgnore($chatsInsert);
        }
        if (!empty($messagesInsert)) {
            DB::table('messages')->insertOrIgnore($messagesInsert);
        }
        if (!empty($commandsInsert)) {
            DB::table('commands')->insertOrIgnore($commandsInsert);
        }
        if (!empty($promptsInsert)) {
            DB::table('prompts')->insertOrIgnore($promptsInsert);
        }
    });

    $unhandled_commands = DB::table('commands')
        ->join('messages', 'commands.telegram_message_id', 'messages.telegram_message_id')
        ->select([
            '*',
            'commands.id as command_id'
        ])
        ->where([
            'handled' => false,
        ])->get();

    DB::transaction(function () use($unhandled_commands) {
        foreach ($unhandled_commands as $unhandled_command) {
            Telegram::sendMessage([
                'chat_id' => $unhandled_command->telegram_chat_id,
                'text' => TelegramService::handleCommand($unhandled_command),
            ]);
        }

        DB::table('commands')->where([
            'handled' => false,
        ])
        ->update([
            'handled' => true,
        ]);
    });

    DB::transaction(function () use($llm_session_ids) {
        $unanswered_prompts = DB::table('prompts')
            ->join('messages', 'messages.telegram_message_id', 'prompts.telegram_message_id')
            ->where([
                'answered' => false
            ])
            ->select([
                '*',
                'prompts.id as prompt_id'
            ])
            ->get();

        $history_message_by_llmm_session_id = !empty($llm_session_ids) ? LLMSession::getMessagesOfOpenSessions($llm_session_ids) : [];
        $answered_prompt_ids = [];
        foreach ($unanswered_prompts as $unanswered_prompt) {
            $session_history = $history_message_by_llmm_session_id[$unanswered_prompt->llm_session_id];
            $session_messages = array_merge(
                [['role' => 'system', 'content' => 'You are a helpful AI assistant. Answer in English.']],
                $session_history,
            );

            $model = 'deepseek/deepseek-v4-flash';
            $answered_prompt_ids[] = $unanswered_prompt->prompt_id;

            $toolCalls = [];
            while(1) {
                try {
                    $session_messages = array_merge(
                        $session_messages,
                        Tool::executeToolCalls($toolCalls)
                    );

                    $firstPayload = OpenRouter::buildChatPayload(
                        $model,
                        $session_messages,
                        [
                            'tools' => Tool::list(),
                            'tool_choice' => 'auto',
                        ]
                    );

                    $toolCalls = TelegramService::telegramBufferedSend(
                        OpenRouter::tokenGenerator($firstPayload),
                        $unanswered_prompt
                    );

                    if (empty($toolCalls)) { break; }
                } catch (ConnectionException $e) {
                    report($e);
                    echo "\n\n[Connection error – please try again.]";
                } catch (\Exception $e) {
                    report($e);
                    echo "\n\n[An unexpected error occurred.]";
                }
            }
        }

        DB::table('prompts')
            ->whereIn('id', $answered_prompt_ids)
            ->where([
                'answered' => false
            ])
            ->update([
                'answered' => true
            ]);
    });
});


Schedule::command('telegram')->everySecond()->withoutOverlapping(3600);
