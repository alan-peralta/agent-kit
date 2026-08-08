<?php

namespace Peralta\AgentKit\Tests\Unit\DTOs;

use Peralta\AgentKit\DTOs\AgentResponse;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\DTOs\ToolCall;
use PHPUnit\Framework\TestCase;

class AgentResponseTest extends TestCase
{
    public function test_has_tool_calls_is_true_when_stop_reason_is_tool_use_and_tool_calls_present()
    {
        $response = new AgentResponse(
            message: Message::assistant(null, [new ToolCall('id-1', 'search', [])]),
            stopReason: 'tool_use',
        );

        $this->assertTrue($response->hasToolCalls());
    }

    public function test_has_tool_calls_is_false_when_stop_reason_is_stop()
    {
        $response = new AgentResponse(message: Message::assistant('oi'), stopReason: 'stop');

        $this->assertFalse($response->hasToolCalls());
    }

    public function test_has_tool_calls_is_false_when_tool_use_but_no_tool_calls_present()
    {
        // Defensive case: stopReason says tool_use but message has no toolCalls.
        $response = new AgentResponse(message: Message::assistant('oi'), stopReason: 'tool_use');

        $this->assertFalse($response->hasToolCalls());
    }

    public function test_text_returns_message_content()
    {
        $response = new AgentResponse(message: Message::assistant('conteúdo'), stopReason: 'stop');

        $this->assertSame('conteúdo', $response->text());
    }

    public function test_tool_calls_returns_message_tool_calls()
    {
        $calls = [new ToolCall('id-1', 'search', [])];
        $response = new AgentResponse(message: Message::assistant(null, $calls), stopReason: 'tool_use');

        $this->assertSame($calls, $response->toolCalls());
    }
}
