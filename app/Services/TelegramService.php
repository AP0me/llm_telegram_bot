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

        return "Hi, I an AI chatbot, how can I help you?";
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
        $prompt_id = $unanswered_prompt->id;
        $buffer = '';
        $reasoning_buffer = '';
        $thinking_notification_sent = false;

        foreach ($gen as $chunk) {
            $text = $chunk['text'] ?? '';
            $chunk_type = $chunk['type'];

            if ($chunk_type === 'reasoning') {
                if(!$thinking_notification_sent) {
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text'    => 'Thinking...',
                    ]);
                    $thinking_notification_sent = true;
                }

                if ($text === '') {
                    $reasoning_buffer .= $text;
                }
                continue;
            }

            if ($text === '') {
                continue;
            }

            $buffer .= $text;
            [$sentences, $buffer] = self::extractSentences($buffer);

            foreach ($sentences as $sentence) {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => trim($sentence),
                ]);
            }
        }
        DB::table('llm_answers')
            ->insert([
                'llm_answer_reasoning' => $reasoning_buffer,
                'llm_answer' => $buffer,
                'prompt_id' => $prompt_id,
            ]);

        // Flush whatever is left (a trailing partial sentence, if any)
        if (trim($buffer) !== '') {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text'    => trim($buffer),
            ]);
        }

        return $gen->getReturn();
    }

    public static function extractSentences(string $buffer): array
    {
        $sentences = [];
        $remaining = $buffer;

        // Match complete sentences: text ending with .!? plus optional trailing whitespace
        if (preg_match_all('/[^.!?]+[.!?]+(?:\s+|$)/u', $buffer, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $sentences[] = $match[0];
            }

            // Where does the last complete sentence end?
            $lastMatch = end($matches[0]);
            $lastEnd = $lastMatch[1] + strlen($lastMatch[0]);
            $remaining = substr($buffer, $lastEnd);
        }

        return [$sentences, $remaining];
    }
}
