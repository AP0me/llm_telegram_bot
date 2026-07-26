<?php

namespace App\Services;

class TelegramService
{
    public static function startCommand()
    {
        return "Hello World";
    }

    public static function handleCommand(object $command): string
    {
        $command_functions = [
            'start' => fn() => self::startCommand(),
        ];

        $command_text = substr($command->text, 1);

        if (!isset($command_functions[$command_text])) {
            return "$command_text is not a command";
        }

        return $command_functions[$command_text]();
    }
}
