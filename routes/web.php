<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

// ----------------------------------------------------------------------
// REUSABLE FUNCTION – streams deltas from an OpenRouter chat completion
// ----------------------------------------------------------------------
function streamOpenRouter(array $payload): Generator
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

function tokenGenerator(array $payload) {
    $toolCalls = [];

    foreach (streamOpenRouter($payload) as $delta) {
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

function buildChatPayload(
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

function executeToolCalls(array $toolCalls) {
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

function displayTokens(Generator $generator) {
    foreach ($generator as $chunk) {
        if ($chunk['type'] === 'content') {

        }
        switch ($chunk['type']) {
            case 'content':
            case 'reasoning':
                echo $chunk['text'];
        }
    }
}

// ----------------------------------------------------------------------
// ROUTE – streamed answer with tool-use loop
// ----------------------------------------------------------------------
Route::get('/', function () {
    $originalMessages = [
        ['role' => 'system', 'content' => 'You are a helpful AI assistant. Answer in English.'],
        [
            'role'    => 'user',
            'content' => "If you built the world's tallest skyscraper, what would you name it? Please check the weather in New York first.",
        ],
    ];
    $model = 'deepseek/deepseek-v4-flash';

    $firstPayload = buildChatPayload($model, $originalMessages, [
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
            $gen1 = tokenGenerator($firstPayload);
            displayTokens($gen1);

            $toolCalls = $gen1->getReturn();

            // --- Build second payload with tool result
            $secondMessages = array_merge($originalMessages, executeToolCalls($toolCalls));
            $secondPayload = buildChatPayload($model, $secondMessages);

            $gen2 = tokenGenerator($secondPayload);
            displayTokens($gen2);
        } catch (ConnectionException $e) {
            report($e);
            echo "\n\n[Connection error – please try again.]";
        } catch (\Exception $e) {
            report($e);
            echo "\n\n[An unexpected error occurred.]";
        }
    });
});
