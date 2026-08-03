<?php

namespace App\Services;

use Fiber;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\DB;
use App\Services\TelegramService;
use App\Services\WhatsApp;
use Mcp\Exception\ConnectionException;
use Telegram\Bot\Laravel\Facades\Telegram;

class LongPollingService
{
    /** Shared non-blocking HTTP handler — drives ALL concurrent requests */
    public static CurlMultiHandler $handler;

    /** Guzzle client wired to the shared handler */
    public static Client $client;

    /**
     * Bootstrap the shared handler + client.
     * Must be called once before run().
     */
    public static function bootClient(): void
    {
        self::$handler = new CurlMultiHandler([
            'select_timeout' => 1, // curl_multi_select blocks at most 1s per tick
        ]);

        self::$client = new Client([
            'handler' => HandlerStack::create(self::$handler),
            // Default request timeout is set per-request below
        ]);
    }

    /**
     * Run the infinite concurrent long-polling loop.
     *
     * Both Telegram and WhatsApp issue 60-second long-poll requests via Guzzle
     * async promises on the SAME CurlMultiHandler. The parent loop calls
     * tick() once per iteration to advance all pending HTTP requests
     * non-blockingly. Neither fiber blocks the other.
     */
    public static function run(): void
    {
        self::bootClient();

        $fibers           = [];
        $pendingPromises  = []; // source => PromiseInterface
        $results          = [];

        while (true) {
            //----------------------------------------------------------------
            // 1. Ensure both fibers are alive (recreate if terminated)
            //----------------------------------------------------------------
            self::ensureFibersAreRunning($fibers, $pendingPromises);

            //----------------------------------------------------------------
            // 2. Pump the curl_multi event loop — advances ALL pending
            //    HTTP requests simultaneously. Non-blocking.
            //----------------------------------------------------------------
            self::$handler->tick();

            //----------------------------------------------------------------
            // 3. Check each pending promise; resume the fiber when settled
            //----------------------------------------------------------------
            foreach ($pendingPromises as $source => $promise) {
                if ($promise->getState() === PromiseInterface::PENDING) {
                    continue;
                }

                $fiber = $fibers[$source];
                if (!$fiber->isSuspended()) {
                    continue;
                }

                // Resume ONCE with the settled promise and CAPTURE the suspension event
                $event = $fiber->resume($promise);
                unset($pendingPromises[$source]);

                // Process this event (it might be data_ready, error, polling, etc.)
                if (is_array($event) && isset($event['event'])) {
                    self::handleFiberEvent($event, $source, $results, $pendingPromises, $fibers, $source);

                    // If the event included a new promise, store it
                    // If it didn't include a new promise (like polling/error events),
                    // we need to resume the fiber to let it loop and create a new request
                    if (!isset($event['promise']) && !$fiber->isTerminated()) {
                        // Resume to let fiber continue its loop
                        $nextEvent = $fiber->resume(null);

                        // If this next event has a promise, store it
                        if (is_array($nextEvent) && isset($nextEvent['promise'])) {
                            self::handleFiberEvent($nextEvent, $source, $results, $pendingPromises, $fibers, $source);
                        }
                    }
                }
            }

            //----------------------------------------------------------------
            // 5. Process collected results
            //----------------------------------------------------------------
            if (!empty($results)) {
                foreach ($results as $source => $updates) {
                    if (!empty($updates)) {
                        $messages = self::updatesToMessages($updates, $source);
                        self::handleIncomingMessages($messages, $source);
                    }
                }
                $results = [];
            }

            //----------------------------------------------------------------
            // 6. Yield CPU (curl_multi_select already sleeps up to 1s in
            //    tick(), but add a tiny guard for the case where there
            //    are no pending requests yet)
            //----------------------------------------------------------------
            if (empty($pendingPromises)) {
                usleep(10_000); // 10ms — nothing to pump
            }
        }
    }

    /**
     * Handle a suspension event from a fiber.
     */
    private static function handleFiberEvent(
        array $event,
        string $source,
        array &$results,
        array &$pendingPromises,
        array &$fibers,
        string $fiberKey
    ): void {
        $msg = match ($event['event'] ?? '') {
            'http_pending'   => "{$source} long-poll started (60s timeout)",
            'telegram_data_ready',
            'whatsapp_data_ready' => "{$source} data ready (count: {$event['count']})",
            'telegram_error',
            'whatsapp_error' => "{$source} error: {$event['error']}",
            'telegram_polling',
            'whatsapp_polling' => "{$source} no updates, re-polling",
            default            => null,
        };

        if ($msg) {
            echo date('[Y-m-d H:i:s] ') . $msg . PHP_EOL;
        }

        // If the fiber yielded a new pending promise, store it
        if (isset($event['promise']) && $event['promise'] instanceof PromiseInterface) {
            $pendingPromises[$source] = $event['promise'];
        }

        // If the fiber yielded updates, collect them
        if (isset($event['updates'])) {
            $results[$source] = $event['updates'];
        }
    }

    /**
     * Ensure both fibers are created and running. Recreate terminated ones.
     */
    protected static function ensureFibersAreRunning(array &$fibers, array &$pendingPromises): void
    {
        foreach (['telegram', 'whatsapp'] as $source) {
            if (isset($fibers[$source]) && $fibers[$source]->isTerminated()) {
                unset($fibers[$source], $pendingPromises[$source]);
            }
        }

        if (!isset($fibers['telegram']) || !$fibers['telegram']->isStarted()) {
            $fibers['telegram'] = self::createTelegramFiber();
            $event = $fibers['telegram']->start();

            if (is_array($event) && isset($event['promise'])) {
                $pendingPromises['telegram'] = $event['promise'];
            }
            if (is_array($event) && isset($event['updates'])) {
                // Edge case: immediate result (unlikely with long-poll)
            }
        }

        if (!isset($fibers['whatsapp']) || !$fibers['whatsapp']->isStarted()) {
            $fibers['whatsapp'] = self::createWhatsAppFiber();
            $event = $fibers['whatsapp']->start();

            if (is_array($event) && isset($event['promise'])) {
                $pendingPromises['whatsapp'] = $event['promise'];
            }
        }
    }

    /**
     * Telegram fiber — uses Guzzle async directly against the Bot API.
     *
     * The irazasyed/laravel-telegram-bot-sdk's setAsyncRequest(true) is
     * fire-and-forget (returns NULL response), so it CANNOT be used for
     * getUpdates where we need the result. Instead we hit the REST API
     * directly with our shared CurlMultiHandler-backed client.
     */
    protected static function createTelegramFiber(): Fiber
    {
        return new Fiber(function () {
            $retryCount = 0;
            $botToken   = config('telegram.bots.mybot.token');

            $offset = DB::table('updates')
                ->where('source', 'telegram')
                ->count('telegram_update_id') + 1;

            while (true) {
                $timeout = 60; // ← True long-polling, not 5s

                try {
                    // Issue ASYNC request — returns immediately with a pending promise
                    $promise = self::$client->getAsync(
                        "https://api.telegram.org/bot{$botToken}/getUpdates",
                        [
                            'query'   => [
                                'offset'          => $offset,
                                'timeout'         => $timeout,
                                'allowed_updates' => json_encode(['message']),
                            ],
                            // Guzzle request timeout — must be >= Telegram timeout
                            'timeout' => $timeout + 5,
                            'connect_timeout' => 10,
                        ]
                    );

                    // Suspend — yield the promise to the parent loop.
                    // The parent will tick() the curl_multi handler and
                    // resume us when the promise settles.
                    $settledPromise = Fiber::suspend([
                        'event'   => 'http_pending',
                        'promise' => $promise,
                    ]);


                    // Parent resumed us — the promise is now settled.
                    // wait() is instant (non-blocking) since already resolved.
                    $response = $settledPromise->wait();
                    $body     = json_decode($response->getBody()->getContents(), true);


                    $retryCount = 0;

                    if (!empty($body['result'])) {
                        $lastUpdate = end($body['result']);
                        $offset     = ($lastUpdate['update_id'] ?? 0) + 1;

                        // Yield updates to parent, then continue
                        Fiber::suspend([
                            'event'   => 'telegram_data_ready',
                            'count'   => count($body['result']),
                            'updates' => $body['result'],
                        ]);
                    } else {
                        Fiber::suspend([
                            'event' => 'telegram_polling',
                        ]);
                    }
                } catch (\Exception $e) {
                    $retryCount++;
                    Fiber::suspend([
                        'event'  => 'telegram_error',
                        'error'  => $e->getMessage(),
                        'attempt' => $retryCount,
                    ]);

                    if ($retryCount >= 5) {
                        $retryCount = 0;
                        sleep(5); // cool-down before retrying
                    }
                }
            }
        });
    }

    /**
     * WhatsApp fiber — uses Guzzle async (as you planned).
     *
     * WhatsApp::getUpdatesAsync() should return a PromiseInterface.
     * If your WhatsApp class wraps Guzzle internally, have it accept
     * the shared client in its constructor:
     *
     *   $whatsapp = new WhatsApp(self::$client);
     *
     * Or expose a method that returns the promise:
     *
     *   $promise = $whatsapp->getUpdatesAsync(['timeout' => 60, 'offset' => $offset]);
     */
    protected static function createWhatsAppFiber(): Fiber
    {
        return new Fiber(function () {
            $retryCount = 0;
            $whatsapp   = new WhatsApp(self::$client); // inject shared client

            $offset = DB::table('updates')
                ->where('source', 'whatsapp')
                ->count('telegram_update_id');

            while (true) {
                $timeout = 60; // ← True long-polling

                try {
                    // Your WhatsApp service returns a Guzzle promise
                    $promise = $whatsapp->getUpdatesAsync([
                        'timeout' => $timeout,
                        'offset'  => $offset,
                    ]);

                    $settledPromise = Fiber::suspend([
                        'event'   => 'http_pending',
                        'promise' => $promise,
                    ]);

                    $response = $settledPromise->wait();
                    $updates  = json_decode($response->getBody()->getContents(), true);

                    $retryCount = 0;

                    if (!empty($updates)) {
                        $offset     = $offset + 1;

                        Fiber::suspend([
                            'event'   => 'whatsapp_data_ready',
                            'count'   => count($updates),
                            'updates' => $updates,
                        ]);
                    } else {
                        Fiber::suspend([
                            'event' => 'whatsapp_polling',
                        ]);
                    }
                } catch (\Exception $e) {
                    $retryCount++;
                    Fiber::suspend([
                        'event'   => 'whatsapp_error',
                        'error'   => $e->getMessage(),
                        'attempt' => $retryCount,
                    ]);

                    if ($retryCount >= 5) {
                        $retryCount = 0;
                        sleep(5);
                    }
                }
            }
        });
    }

    /**
     * Convert raw platform updates into a uniform message array.
     */
    public static function updatesToMessages(array $updates, string $source): array
    {
        $messages = [];

        if ($source === 'telegram') {
            foreach ($updates as $update) {
                $message = $update['message'] ?? false;
                if (!$message) {
                    continue;
                }

                $username = $message['chat']['username'] ?? false;
                $text = $message['text'] ?? false;
                if (!$text || !$username) {
                    continue;
                }

                $messages[] = [
                    'remote_update_id'  => $update['update_id'],
                    'remote_chat_id'     => $message['chat']['id'],
                    'username'           => $username,
                    'text'               => $text,
                    'remote_message_id'  => $message['message_id'],
                ];
            }
        }
        else if ($source === 'whatsapp') {
            $whatsapp_messages = $updates['messages'] ?? [];
            foreach ($whatsapp_messages as $whatsapp_message) {
                if (!$whatsapp_message) {
                    continue;
                }

                $username = $whatsapp_message['phone'] ?? false;
                $text = $whatsapp_message['text'] ?? false;
                $whatsapp_message_id = $whatsapp_message['id'];
                if (!$text || !$username) {
                    continue;
                }

                $messages[] = [
                    'remote_update_id'   => "$username-update-$whatsapp_message_id",
                    'remote_chat_id'     => "$username-chat",
                    'username'           => $username,
                    'text'               => $text,
                    'remote_message_id'  => "whatsapp-message-$whatsapp_message_id",
                ];
            }
        }

        return $messages;
    }

    /**
     * Handle a single incoming message (already uniform format).
     */
    public static function handleIncomingMessages(array $messages, string $source): void
    {
        $llm_sessions = DB::table('llm_sessions')
            ->join('commands', 'llm_sessions.start_command_id', 'commands.id')
            ->join('messages', 'commands.telegram_message_id', 'messages.telegram_message_id')
            ->select()
            ->where([
                'end_command_id' => null,
            ])
            ->get();

        $llm_sessions_by_telegram_chat_id = [];
        $llm_session_ids = [];
        foreach ($llm_sessions as $llm_session) {
            $llm_sessions_by_telegram_chat_id[$llm_session->telegram_chat_id] = $llm_session;
            $llm_session_ids[] = $llm_session->id;
        }

        $updatesInsert = [];
        $chatsInsert = [];
        $messagesInsert = [];
        $commandsInsert = [];
        $promptsInsert = [];

        foreach ($messages as $message) {
            $remote_update_id  = $message['remote_update_id'];
            $remote_chat_id    = $message['remote_chat_id'];
            $username          = $message['username'];
            $text              = $message['text'];
            $remote_message_id = $message['remote_message_id'];
            // ---

            $updatesInsert[] = [
                'telegram_update_id' => $remote_update_id,
                'source' => $source,
            ];

            $telegram_chat_id = $remote_chat_id;
            $telegram_message_id = $remote_message_id;

            if (!isset($text) || !isset($username)) {
                continue;
            }

            // Collect for 'chats' table
            $chatsInsert[] = [
                'username' => $username,
                'telegram_chat_id' => $telegram_chat_id,
                'telegram_update_id' => $remote_update_id,
            ];

            // Collect for 'messages' table
            $messagesInsert[] = [
                'text' => $text,
                'telegram_chat_id' => $telegram_chat_id,
                'telegram_message_id' => $telegram_message_id,
            ];

            if (substr($text, 0, 1) === '/') {
                $commandsInsert[] = [
                    'handled' => false,
                    'telegram_message_id' => $telegram_message_id,
                ];
            } else {
                $open_llm_session = $llm_sessions_by_telegram_chat_id[$telegram_chat_id] ?? false;
                if ($open_llm_session) {
                    $promptsInsert[] = [
                        'answered' => false,
                        'telegram_message_id' => $telegram_message_id,
                        'llm_session_id' => $open_llm_session->id,
                    ];
                }
            }
        }

        DB::transaction(function () use ($promptsInsert, $updatesInsert, $chatsInsert, $messagesInsert, $commandsInsert) {
            if (!empty($updatesInsert)) {
                DB::table('updates')->insertOrIgnore($updatesInsert);
            }
            if (!empty($chatsInsert)) {
                DB::table('chats')->insertOrIgnore($chatsInsert);
            }
            if (!empty($messagesInsert)) {
                DB::table('messages')->insertOrIgnore($messagesInsert);
            }
            if (!empty($commandsInsert)) {
                DB::table('commands')->insertOrIgnore($commandsInsert);
            }
            if (!empty($promptsInsert)) {
                DB::table('prompts')->insertOrIgnore($promptsInsert);
            }
        });

        $unhandled_commands = DB::table('commands')
            ->join('messages', 'commands.telegram_message_id', 'messages.telegram_message_id')
            ->select([
                '*',
                'commands.id as command_id'
            ])
            ->where([
                'handled' => false,
            ])->get();


        DB::transaction(function () use ($unhandled_commands) {
            $whatsapp   = new WhatsApp(self::$client);
            foreach ($unhandled_commands as $unhandled_command) {
                $whatsapp->sendMessage([
                    'chat_id' => $unhandled_command->telegram_chat_id,
                    'text' => TelegramService::handleCommand($unhandled_command),
                ]);
            }

            DB::table('commands')->where([
                'handled' => false,
            ])
                ->update([
                    'handled' => true,
                ]);
        });

        DB::transaction(function () use ($llm_session_ids) {
            $unanswered_prompts = DB::table('prompts')
                ->join('messages', 'messages.telegram_message_id', 'prompts.telegram_message_id')
                ->where([
                    'answered' => false
                ])
                ->select([
                    '*',
                    'prompts.id as prompt_id'
                ])
                ->get();

            $answered_prompt_ids = [];
            foreach ($unanswered_prompts as $unanswered_prompt) {
                $model = 'deepseek/deepseek-v4-flash';
                $answered_prompt_ids[] = $unanswered_prompt->prompt_id;

                $tool_calls = [];
                while (1) {
                    try {
                        $history_message_by_llm_session_id = !empty($llm_session_ids) ? LLMSession::getMessagesOfOpenSessions($llm_session_ids) : [];
                        $session_messages = array_merge(
                            [
                                [
                                    'role' => 'system',
                                    'content' => 'You are a helpful AI assistant that books appointments.'
                                ],
                                [
                                    'role' => 'system',
                                    'content' => 'Answer in English.'
                                ],
                                [
                                    'role' => 'system',
                                    'content' => 'If a tool call errors, stop the chain of tool calls and tell the user what went wrong.'
                                ],
                                [
                                    'role' => 'system',
                                    'content' => 'Separate the output with END_MESSAGE each section of the output separated in this way, will be a separate telegram message.'
                                ],
                            ],
                            $history_message_by_llm_session_id[$unanswered_prompt->llm_session_id] ?? [],
                            Tool::executeToolCalls($tool_calls),
                        );
                        echo json_encode(['tool_calls' => $tool_calls, 'session_messages' => $session_messages]);
                        echo "\n";

                        $payload = OpenRouter::buildChatPayload(
                            $model,
                            $session_messages,
                            [
                                'tools' => Tool::list(),
                                'tool_choice' => 'auto',
                            ]
                        );

                        $generated_info = TelegramService::telegramBufferedSend(
                            OpenRouter::tokenGenerator($payload),
                            $unanswered_prompt
                        );
                        $tool_calls = $generated_info['tool_calls'];
                        $content_buffer = $generated_info['content_buffer'];

                        // Ghost adding the llm response in order to avoid fetching from DB.
                        $history_message_by_llm_session_id[$unanswered_prompt->llm_session_id][] = [
                            'role'    => 'assistant',
                            'content' => $content_buffer,
                        ];
                        if (empty($tool_calls)) {
                            break;
                        }
                    } catch (ConnectionException $e) {
                        report($e);
                        echo "\n\n[Connection error – please try again.]";
                    } catch (\Exception $e) {
                        report($e);
                        echo "\n\n[An unexpected error occurred.] {json_encode($e)}";
                    }
                }
            }

            DB::table('prompts')
                ->whereIn('id', $answered_prompt_ids)
                ->where([
                    'answered' => false
                ])
                ->update([
                    'answered' => true
                ]);
        });
    }
}
