<?php

namespace Peralta\AgentKit\Tests\Unit\Listeners;

use Illuminate\Support\Facades\Log;
use Peralta\AgentKit\Events\TokenUsageRecorded;
use Peralta\AgentKit\Listeners\LogUsageListener;
use Peralta\AgentKit\Tests\TestCase;

class LogUsageListenerTest extends TestCase
{
    public function test_logs_when_logging_enabled()
    {
        config(['agent-kit.logging.enabled' => true, 'agent-kit.logging.channel' => 'stack']);
        Log::shouldReceive('channel')->with('stack')->andReturnSelf();
        Log::shouldReceive('info')->once()->with('[agent-kit] Resposta finalizada', [
            'provider' => 'openai',
            'input_tokens' => 10,
            'output_tokens' => 5,
            'conversation_id' => 'conv-1',
        ]);

        (new LogUsageListener())->handle(new TokenUsageRecorded(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4o',
            inputTokens: 10,
            outputTokens: 5,
        ));
    }

    public function test_does_nothing_when_logging_disabled()
    {
        config(['agent-kit.logging.enabled' => false]);
        Log::shouldReceive('channel')->never();

        (new LogUsageListener())->handle(new TokenUsageRecorded(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4o',
            inputTokens: 10,
            outputTokens: 5,
        ));
    }
}
