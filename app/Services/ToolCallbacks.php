<?php

namespace App\Services;

class ToolCallbacks
{

    public static function weather(array $location) {
        return "Weather is sunny in $location";
    }

}
