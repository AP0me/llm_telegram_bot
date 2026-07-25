<?php

namespace App\Http\Controllers;

use App\Services\OpenRouter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;

class LLMController extends Controller
{
    public function generate(Request $request)
    {
        $request->validate([
            'new_user_text' => 'required|string|max:10000'
        ]);
        $newUserText = $request->new_user_text;

        $originalMessages = [
            ['role' => 'system', 'content' => 'You are a helpful AI assistant. Answer in English.'],
            [
                'role'    => 'user',
                'content' => $newUserText,
            ],
        ];
        $model = 'deepseek/deepseek-v4-flash';

        $firstPayload = OpenRouter::buildChatPayload($model, $originalMessages, [
            'tools' => OpenRouter::toolList(),
            'tool_choice' => 'auto',
        ]);

        flush();
        return response()->stream(function () use ($originalMessages, $firstPayload, $model) {
            try {
                // --- First call (may request a tool) ---
                $gen1 = OpenRouter::tokenGenerator($firstPayload);
                OpenRouter::displayTokens($gen1);

                $toolCalls = $gen1->getReturn();

                if (!empty($toolCalls)) {
                    $secondMessages = array_merge($originalMessages, OpenRouter::executeToolCalls($toolCalls));
                    $secondPayload = OpenRouter::buildChatPayload($model, $secondMessages);

                    $gen2 = OpenRouter::tokenGenerator($secondPayload);
                    OpenRouter::displayTokens($gen2);
                }
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
