<?php

namespace Peralta\AgentKit\Listeners;

use Illuminate\Support\Facades\Log;
use Peralta\AgentKit\Events\ToolCallExecuted;

class LogToolCallListener
{
    public function handle(ToolCallExecuted $event): void
    {
        if (!config('agent-kit.logging.enabled', false)) return;
        if (!config('agent-kit.logging.log_tool_calls', false)) return;

        Log::channel(config('agent-kit.logging.channel', 'stack'))->info(
            '[agent-kit] Tool executada',
            [
                'tool' => $event->toolName,
                'success' => $event->success,
                'conversation_id' => $event->conversationId,
            ],
        );
    }
}
