<?php

namespace Peralta\AgentKit\Tests\Unit\Events;

use Peralta\AgentKit\Events\AgentKitEvent;
use Peralta\AgentKit\Events\ToolCallExecuted;
use Peralta\AgentKit\Events\TokenUsageRecorded;
use Peralta\AgentKit\Tests\TestCase;

class UsageAndToolEventsTest extends TestCase
{
    public function test_token_usage_recorded_holds_token_counts()
    {
        $event = new TokenUsageRecorded(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4o',
            inputTokens: 100,
            outputTokens: 50,
        );

        $this->assertInstanceOf(AgentKitEvent::class, $event);
        $this->assertEquals(100, $event->inputTokens);
        $this->assertEquals(50, $event->outputTokens);
    }

    public function test_tool_call_executed_holds_success_and_duration()
    {
        $event = new ToolCallExecuted(
            conversationId: 'conv-1',
            toolName: 'search',
            success: true,
            durationMs: 42,
        );

        $this->assertInstanceOf(AgentKitEvent::class, $event);
        $this->assertEquals('search', $event->toolName);
        $this->assertTrue($event->success);
        $this->assertEquals(42, $event->durationMs);
    }
}
