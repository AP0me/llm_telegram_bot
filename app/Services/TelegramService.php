<?php

namespace App\Services;

use Generator;
use Illuminate\Support\Facades\DB;
use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramService
{
    public static function startCommand(object $command)
    {
        DB::transaction(function () use($command) {
            $telegram_chat_id = DB::table('commands')
                ->join('messages', 'messages.telegram_message_id', 'commands.telegram_message_id')
                ->select()
                ->where([
                    'commands.id' => $command->command_id,
                ])
                ->first('messages.telegram_chat_id')
                ->telegram_chat_id;


            DB::table('llm_sessions')
                ->join('commands', 'commands.id', 'llm_sessions.start_command_id')
                ->join('messages', 'messages.telegram_message_id', 'commands.telegram_message_id')
                ->where([
                    'messages.telegram_chat_id' => $telegram_chat_id,
                ])
                ->update([
                    'end_command_id' => $command->command_id
                ]);

            DB::table('llm_sessions')->insert([
                'start_command_id' => $command->command_id,
            ]);
        });

        return "Hi, I am AI chatbot, how can I help you?";
    }

    public static function stopCommand(object $command)
    {
        DB::transaction(function () use($command) {
            $telegram_chat_id = DB::table('commands')
                ->join('messages', 'messages.telegram_message_id', 'commands.telegram_message_id')
                ->select()
                ->where([
                    'commands.id' => $command->command_id,
                ])
                ->first('messages.telegram_chat_id')
                ->telegram_chat_id;


            DB::table('llm_sessions')
                ->join('commands', 'commands.id', 'llm_sessions.start_command_id')
                ->join('messages', 'messages.telegram_message_id', 'commands.telegram_message_id')
                ->where([
                    'messages.telegram_chat_id' => $telegram_chat_id,
                ])
                ->update([
                    'end_command_id' => $command->command_id
                ]);
        });

        return "Bye!";
    }

    public static function handleCommand(object $command): string
    {
        $command_functions = [
            'start' => fn() => self::startCommand($command),
            'stop' => fn() => self::stopCommand($command),
        ];

        $command_text = substr($command->text, 1);

        if (!isset($command_functions[$command_text])) {
            return "/$command_text is not a command";
        }

        return $command_functions[$command_text]($command);
    }

    public static function telegramBufferedSend(Generator $gen, object $unanswered_prompt): mixed
    {
        $chatId = $unanswered_prompt->telegram_chat_id;
        $prompt_id = $unanswered_prompt->prompt_id;
        $buffer = '';
        $content_buffer = '';
        $reasoning_buffer = '';
        $thinking_notification_sent = false;

        $whatsapp = new WhatsApp(LongPollingService::$client);

        foreach ($gen as $chunk) {
            $text = $chunk['text'] ?? '';
            $chunk_type = $chunk['type'];

            if ($text === '') {
                continue;
            }

            if ($chunk_type === 'reasoning') {
                if(!$thinking_notification_sent) {
                    $whatsapp->sendMessage([
                        'chat_id' => $chatId,
                        'text'    => 'Thinking...',
                    ]);
                    $thinking_notification_sent = true;
                }

                if ($text === '') {
                    continue;
                }
                $reasoning_buffer .= $text;
            }
            else if ($chunk_type === 'content') {
                $buffer .= $text;
                $content_buffer .= $text;
                [$sentences, $buffer] = self::extractSentences($buffer);

                foreach ($sentences as $sentence) {
                    $whatsapp->sendMessage([
                        'chat_id' => $chatId,
                        'text'    => trim($sentence),
                    ]);
                }
            }
        }

        // Flush whatever is left (a trailing partial sentence, if any)
        if (trim($buffer) !== '') {
            $whatsapp->sendMessage([
                'chat_id' => $chatId,
                'text'    => trim($buffer),
            ]);
        }

        $llm_answer_id = DB::table('llm_answers')
            ->insertGetId([
                'llm_answer_reasoning' => $reasoning_buffer,
                'llm_answer' => $content_buffer,
                'prompt_id' => $prompt_id,
            ]);

        $tool_calls = $gen->getReturn();
        $tool_call_inserts = [];
        foreach ($tool_calls as $tool_call) {
            if (!isset($tool_call['function']['name'], $tool_call['id'])) {
                continue;
            }

            $tool_call_inserts[] = [
                'name' => $tool_call['function']['name'],
                'tool_call_id' => $tool_call['id'],
                'llm_answer_id' => $llm_answer_id,
                'response' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!empty($tool_call_inserts)) {
            DB::table('tool_calls')->insert($tool_call_inserts);
        }

        return [
            'content_buffer' => $content_buffer,
            'tool_calls' => $tool_calls,
        ];
    }

    public static function extractSentences(string $buffer): array
    {
        $sentences = [];
        $remaining = $buffer;

        // Check if the buffer contains END_SENTENCE markers
        if (str_contains($buffer, 'END_MESSAGE')) {
            // Split the buffer by END_SENTENCE
            $parts = explode('END_MESSAGE', $buffer);

            // Process all parts except the last one (which might be incomplete)
            for ($i = 0; $i < count($parts) - 1; $i++) {
                $sentence = trim($parts[$i]);
                if (!empty($sentence)) {
                    $sentences[] = $sentence;
                }
            }

            // The last part is the remaining incomplete sentence
            $remaining = $parts[count($parts) - 1];
        }

        return [$sentences, $remaining];
    }
}
