<?php

namespace Peralta\AgentKit\Listeners;

use Illuminate\Support\Facades\Log;
use Peralta\AgentKit\Events\TokenUsageRecorded;

class LogUsageListener
{
    public function handle(TokenUsageRecorded $event): void
    {
        if (!config('agent-kit.logging.enabled', false)) return;

        Log::channel(config('agent-kit.logging.channel', 'stack'))->info(
            '[agent-kit] Resposta finalizada',
            [
                'provider' => $event->provider,
                'input_tokens' => $event->inputTokens,
                'output_tokens' => $event->outputTokens,
                'conversation_id' => $event->conversationId,
            ],
        );
    }
}
