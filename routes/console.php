<?php

use App\Services\OpenRouter;
use App\Services\TelegramService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mcp\Exception\ConnectionException;
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

        $history_message_by_llmm_session_id = !empty($llm_session_ids) ? getMessagesOfOpenSessions($llm_session_ids) : [];
        $answered_prompt_ids = [];
        foreach ($unanswered_prompts as $unanswered_prompt) {
            $session_history = $history_message_by_llmm_session_id[$unanswered_prompt->llm_session_id];
            echo json_encode($session_history);
            $originalMessages = array_merge(
                [['role' => 'system', 'content' => 'You are a helpful AI assistant. Answer in English.']],
                $session_history,
            );

            $model = 'deepseek/deepseek-v4-flash';

            $firstPayload = OpenRouter::buildChatPayload($model, $originalMessages, [
                'tools' => OpenRouter::toolList(),
                'tool_choice' => 'auto',
            ]);

            try {
                $gen1 = OpenRouter::tokenGenerator($firstPayload);
                $answered_prompt_ids[] = $unanswered_prompt->prompt_id;
                $toolCalls = TelegramService::telegramBufferedSend($gen1, $unanswered_prompt);

                if (!empty($toolCalls)) {
                    $secondMessages = array_merge($originalMessages, OpenRouter::executeToolCalls($toolCalls));
                    $secondPayload  = OpenRouter::buildChatPayload($model, $secondMessages);

                    $gen2 = OpenRouter::tokenGenerator($secondPayload);
                    TelegramService::telegramBufferedSend($gen2, $unanswered_prompt);
                }

            } catch (ConnectionException $e) {
                report($e);
                echo "\n\n[Connection error – please try again.]";
            } catch (\Exception $e) {
                report($e);
                echo "\n\n[An unexpected error occurred.]";
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

    Artisan::call('telegram');
});

function getMessagesOfOpenSessions(array $session_ids): array
{
    // 2. Fetch all prompts that occurred after the /start command
    $prompts = DB::table('prompts')
        ->join('messages', 'messages.telegram_message_id', 'prompts.telegram_message_id')
        ->whereIn('llm_session_id', $session_ids)
        ->select(['*', 'prompts.id as prompt_id'])
        ->orderBy('prompt_id')
        ->get(['id', 'text', 'created_at']);

    // 3. Fetch all LLM answers linked to those prompts
    $promptIds = $prompts->pluck('prompt_id')->all();
    echo json_encode($promptIds);
    $answers = DB::table('llm_answers')
            ->whereIn('prompt_id', $promptIds)
        ->get(['prompt_id', 'llm_answer']);

    $answer_by_prompt_id = [];
    foreach ($answers as $answer) {
        $answer_by_prompt_id[$answer->prompt_id] = $answer;
    }
    echo json_encode($answer_by_prompt_id);


    // 4. Build the message array, alternating user and assistant
    $messages = [];
    foreach ($prompts as $prompt) {
        $messages[$prompt->llm_session_id][] = [
            'role'    => 'user',
            'content' => $prompt->text,
        ];

        if (isset($answer_by_prompt_id[$prompt->prompt_id])) {
            $messages[$prompt->llm_session_id][] = [
                'role'    => 'assistant',
                'content' => $answer_by_prompt_id[$prompt->prompt_id]->llm_answer,
            ];
        }
    }
    echo json_encode($messages);

    return $messages;
}
