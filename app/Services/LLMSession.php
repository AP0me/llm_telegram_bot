<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class LLMSession
{
    public static function getMessagesOfOpenSessions(array $session_ids): array
    {
        // 1. Fetch all prompts (user messages)
        $prompts = DB::table('prompts')
            ->join('messages', 'messages.telegram_message_id', 'prompts.telegram_message_id')
            ->whereIn('llm_session_id', $session_ids)
            ->select(['*', 'prompts.id as prompt_id'])
            ->orderBy('prompt_id')
            ->get(['id', 'text', 'created_at']);

        // 2. Fetch all LLM answers (assistant messages)
        $promptIds = $prompts->pluck('prompt_id')->all();
        $answers = DB::table('llm_answers')
            ->whereIn('prompt_id', $promptIds)
            ->get(['llm_answers.id as llm_answer_id', 'prompt_id', 'llm_answer']);

        $answers_by_prompt_id = [];
        $llm_answer_ids = [];
        foreach ($answers as $answer) {
            $answers_by_prompt_id[$answer->prompt_id][] = $answer;
            $llm_answer_ids[] = $answer->llm_answer_id;
        }

        // 3. Fetch all tool responses linked to those prompts
        $toolResponses = DB::table('tool_calls')
            ->whereIn('llm_answer_id', $llm_answer_ids)
            ->orderBy('llm_answer_id')
            ->orderBy('id')
            ->get(['llm_answer_id', 'tool_call_id', 'response']);

        // Group tool responses by prompt_id
        $tool_responses_by_answer_id = [];
        foreach ($toolResponses as $tr) {
            $tool_responses_by_answer_id[$tr->llm_answer_id][] = $tr;
        }

        // 4. Build the message array in correct chronological order
        $messages = [];
        foreach ($prompts as $prompt) {
            $sessionId = $prompt->llm_session_id;

            // User message
            $messages[$sessionId][] = [
                'role'    => 'user',
                'content' => $prompt->text,
            ];

            // Check if there's an answer for this prompt
            if (isset($answers_by_prompt_id[$prompt->prompt_id])) {
                $answers = $answers_by_prompt_id[$prompt->prompt_id];

                foreach ($answers as $answer) {
                    $messages[$sessionId][] = [
                        'role'    => 'assistant',
                        'content' => $answer->llm_answer ?? '',
                    ];

                    // Tool response messages (if any)
                    if (isset($tool_responses_by_answer_id[$answer->llm_answer_id])) {
                        foreach ($tool_responses_by_answer_id[$answer->llm_answer_id] as $toolResponse) {
                            if (isset($toolResponse->response)) {
                                $messages[$sessionId][] = [
                                    'role'         => 'tool',
                                    'tool_call_id' => $toolResponse->tool_call_id,
                                    'content'      => $toolResponse->response,
                                ];
                            }
                        }
                    }
                }
            }
        }

        return $messages;
    }
}
