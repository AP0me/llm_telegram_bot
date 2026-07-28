<?php

namespace App\Services;

use Generator;
use Illuminate\Support\Facades\DB;
use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramService
{
    public static function startCommand(object $command)
    {
        DB::table('llm_sessions')->insert([
            'start_command_id' => $command->command_id,
        ]);

        return "Hi, I an AI chatbot, how can I help you?";
    }

    public static function handleCommand(object $command): string
    {
        $command_functions = [
            'start' => fn() => self::startCommand($command),
        ];

        $command_text = substr($command->text, 1);

        if (!isset($command_functions[$command_text])) {
            return "/$command_text is not a command";
        }

        return $command_functions[$command_text]($command);
    }

    public static function telegramBufferedSend(Generator $gen, string|int $chatId): mixed
    {
        $buffer = '';

        foreach ($gen as $chunk) {
            $text = $chunk['text'] ?? '';
            if ($text === '') {
                continue;   // skip empty / no-text chunks
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
