<?php

namespace App\Http\Controllers;

use App\Services\OpenRouter;
use Illuminate\Http\Client\ConnectionException;

class LLMController extends Controller
{
    public function generate()
    {
        $originalMessages = [
            ['role' => 'system', 'content' => 'You are a helpful AI assistant. Answer in English.'],
            [
                'role'    => 'user',
                'content' => "Reason about this! If you built the world's tallest skyscraper, what would you name it? Please check the weather in New York first.",
            ],
        ];
        $model = 'deepseek/deepseek-v4-flash';

        $firstPayload = OpenRouter::buildChatPayload($model, $originalMessages, [
            'tools' => [[
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_weather',
                    'description' => 'Get current weather for a location',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties'  => [
                            'location' => [
                                'type'        => 'string',
                                'description' => 'City name',
                            ],
                        ],
                        'required' => ['location'],
                    ],
                ],
            ]],
            'tool_choice' => 'auto',
        ]);

        flush();
        return response()->stream(function () use ($originalMessages, $firstPayload, $model) {
            try {
                // --- First call (may request a tool) ---
                $gen1 = OpenRouter::tokenGenerator($firstPayload);
                OpenRouter::displayTokens($gen1);

                $toolCalls = $gen1->getReturn();

                // --- Build second payload with tool result
                $secondMessages = array_merge($originalMessages, OpenRouter::executeToolCalls($toolCalls));
                $secondPayload = OpenRouter::buildChatPayload($model, $secondMessages);

                $gen2 = OpenRouter::tokenGenerator($secondPayload);
                OpenRouter::displayTokens($gen2);
            } catch (ConnectionException $e) {
                report($e);
                echo "\n\n[Connection error – please try again.]";
            } catch (\Exception $e) {
                report($e);
                echo "\n\n[An unexpected error occurred.]";
            }
        });
    }
}
