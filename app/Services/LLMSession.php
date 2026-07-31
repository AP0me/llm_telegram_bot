<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class LLMSession
{
    public static function getMessagesOfOpenSessions(array $session_ids): array
    {
        // 2. Fetch all prompts that occurred after the /start command
        $prompts = DB::table('prompts')
            ->join('messages', 'messages.telegram_message_id', 'prompts.telegram_message_id')
            ->whereIn('llm_session_id', $session_ids)
            ->select(['*', 'prompts.id as prompt_id'])
            ->orderBy('prompt_id')
            ->get(['id', 'text', 'created_at']);

        // 3. Fetch all LLM answers linked to those prompts
        $promptIds = $prompts->pluck('prompt_id')->all();
        $answers = DB::table('llm_answers')
                ->whereIn('prompt_id', $promptIds)
            ->get(['prompt_id', 'llm_answer']);

        $answer_by_prompt_id = [];
        foreach ($answers as $answer) {
            $answer_by_prompt_id[$answer->prompt_id] = $answer;
        }

        // 4. Build the message array, alternating user and assistant
        $messages = [];
        foreach ($prompts as $prompt) {
            $messages[$prompt->llm_session_id][] = [
                'role'    => 'user',
                'content' => $prompt->text,
            ];

            if (isset($answer_by_prompt_id[$prompt->prompt_id])) {
                $messages[$prompt->llm_session_id][] = [
                    'role'    => 'assistant',
                    'content' => $answer_by_prompt_id[$prompt->prompt_id]->llm_answer,
                ];
            }
        }

        return $messages;
    }
}
