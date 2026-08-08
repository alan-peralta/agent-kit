<?php

namespace Peralta\AgentKit\Tests\Unit\Listeners;

use Illuminate\Support\Facades\Log;
use Peralta\AgentKit\Events\ToolCallExecuted;
use Peralta\AgentKit\Listeners\LogToolCallListener;
use Peralta\AgentKit\Tests\TestCase;

class LogToolCallListenerTest extends TestCase
{
    public function test_logs_when_enabled_and_log_tool_calls_true()
    {
        config([
            'agent-kit.logging.enabled' => true,
            'agent-kit.logging.log_tool_calls' => true,
            'agent-kit.logging.channel' => 'stack',
        ]);
        Log::shouldReceive('channel')->with('stack')->andReturnSelf();
        Log::shouldReceive('info')->once()->with('[agent-kit] Tool executada', [
            'tool' => 'search',
            'success' => true,
            'conversation_id' => 'conv-1',
        ]);

        (new LogToolCallListener())->handle(new ToolCallExecuted(
            conversationId: 'conv-1',
            toolName: 'search',
            success: true,
        ));
    }

    public function test_does_nothing_when_log_tool_calls_disabled()
    {
        config(['agent-kit.logging.enabled' => true, 'agent-kit.logging.log_tool_calls' => false]);
        Log::shouldReceive('channel')->never();

        (new LogToolCallListener())->handle(new ToolCallExecuted(
            conversationId: 'conv-1',
            toolName: 'search',
            success: true,
        ));
    }
}
