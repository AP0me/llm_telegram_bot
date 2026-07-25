<?php

namespace App\Services;

use Generator;
use Illuminate\Support\Facades\Http;

class OpenRouter
{
    public static function streamOpenRouter(array $payload): Generator
    {
        $apiKey = config('services.open_router.token');
        $url = 'https://openrouter.ai/api/v1/chat/completions';

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type'  => 'application/json',
        ])
            ->withOptions(['stream' => true])
            ->withBody(json_encode($payload), 'application/json')
            ->post($url);

        $body = $response->getBody();
        $buffer = '';

        while (!$body->eof()) {
            $chunk = $body->read(1024);
            $buffer .= $chunk;

            while (($lineEnd = strpos($buffer, "\n")) !== false) {
                $line   = substr($buffer, 0, $lineEnd);
                $buffer = substr($buffer, $lineEnd + 1);

                $line = trim($line);
                if ($line === '' || !str_starts_with($line, 'data: ')) {
                    continue;
                }

                $jsonStr = substr($line, strlen('data: '));
                $event   = json_decode($jsonStr, true);

                if ($event && isset($event['choices'][0]['delta'])) {
                    yield $event['choices'][0]['delta'];
                }
            }
        }
    }

    public static function tokenGenerator(array $payload)
    {
        $toolCalls = [];

        foreach (self::streamOpenRouter($payload) as $delta) {
            if (isset($delta['content'])) {
                yield ['text' => $delta['content'], 'type' => 'content'];
            }

            if (isset($delta['reasoning'])) {
                yield ['text' => $delta['reasoning'], 'type' => 'reasoning'];
            }

            if (isset($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $tc) {
                    $idx = $tc['index'];
                    if (!isset($toolCalls[$idx])) {
                        $toolCalls[$idx] = [
                            'id'       => $tc['id'] ?? null,
                            'type'     => $tc['type'] ?? 'function',
                            'function' => ['name' => '', 'arguments' => ''],
                        ];
                    }
                    if (isset($tc['function']['name'])) {
                        $toolCalls[$idx]['function']['name'] = $tc['function']['name'];
                    }
                    if (isset($tc['function']['arguments'])) {
                        $toolCalls[$idx]['function']['arguments'] .= $tc['function']['arguments'];
                    }
                }
            }
        }

        return $toolCalls;
    }

    public static function buildChatPayload(
        string $model,
        array $messages,
        array $options = []
    ): array {
        $payload = [
            'model'    => $model,
            'messages' => $messages,
            'stream'   => $options['stream'] ?? true,
        ];

        if (! empty($options['tools'])) {
            $payload['tools'] = $options['tools'];
            $payload['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        return $payload;
    }

    public static function executeToolCalls(array $toolCalls)
    {
        if (empty($toolCalls)) {
            return;
        }

        // --- Execute tools ---
        $toolResults = [];
        foreach ($toolCalls as $call) {
            $args = json_decode($call['function']['arguments'], true) ?? [];
            $result = 'The weather in ' . ($args['location'] ?? 'Unknown') . ' is 72°F and Sunny.';
            $toolResults[] = [
                'tool_call_id' => $call['id'],
                'content'      => $result,
            ];
        }

        $toolMessages = [];
        foreach ($toolResults as $tr) {
            $toolMessages[] = [
                'role'         => 'tool',
                'tool_call_id' => $tr['tool_call_id'],
                'content'      => $tr['content'],
            ];
        }

        return $toolMessages;
    }

    public static function displayTokens(Generator $generator): void
    {
        $previousType = null;  // track previous type, null means "none yet"

        foreach ($generator as $chunk) {
            if ($chunk['text'] === '') {
                continue;
            }

            $currentType = $chunk['type']; // 'content' or 'reasoning'

            // If the type has changed (and we already had a previous chunk)
            if ($currentType !== $previousType) {
                echo '<br>switched to ' . $currentType . '<br>';
            }

            echo $chunk['text'];
            $previousType = $currentType;
        }
    }
}
