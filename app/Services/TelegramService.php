<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

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
}
