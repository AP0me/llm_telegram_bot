<?php

use Mcp\Client;
use Illuminate\Support\Facades\Route;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Content\TextContent;

function removeModelMetadata(string $text): string {
    // Pattern explanation:
    // \s*               - optional leading whitespace/newlines
    // \(model:\s*       - literal "(model:" then optional spaces
    // [^,]+             - model name (everything until the next comma)
    // ,\s*generation\s+id:\s* - literal ", generation id:" with flexible spacing
    // [^,]+             - generation ID
    // ,\s*input\s+tokens:\s*\d+ - literal ", input tokens: N"
    // ,\s*output\s+tokens:\s*\d+ - literal ", output tokens: N"
    // \)                - closing parenthesis
    // $/m               - end of line (multiline mode)
    $pattern = '/\s*\(model:\s*[^,]+,\s*generation\s+id:\s*[^,]+,\s*input\s+tokens:\s*\d+,\s*output\s+tokens:\s*\d+\)$/m';

    return preg_replace($pattern, '', $text);
}

Route::get('/', function () {
    $client = Client::builder()
        ->setClientInfo('My Application', '1.0.0')
        ->setInitTimeout(30)
        ->setRequestTimeout(120)
        ->build();

    $transport = new HttpTransport(
        endpoint: 'https://mcp.openrouter.ai/mcp',
        headers: ['Authorization' => 'Bearer ' . config('services.open_router.mcp.token')],
    );

    $client->connect($transport);

    $result = $client->callTool(
        name: 'send-message',
        arguments: [
            'model' => 'deepseek/deepseek-v4-flash',
            'message' => 'Hello',
            'system' => 'You are a useful AI assistant.'
        ],
    );

    $list_texts = [];
    foreach ($result->content as $content) {
        if ($content instanceof TextContent) {
            $list_texts[] = removeModelMetadata($content->text);
        }
    }

    return response(['response' => $list_texts]);
});
