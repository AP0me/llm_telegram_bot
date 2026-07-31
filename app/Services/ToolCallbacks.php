<?php

namespace App\Services;

class ToolCallbacks
{

    public static function weather(string $location) {
        return "Weather is sunny in $location";
    }

}
