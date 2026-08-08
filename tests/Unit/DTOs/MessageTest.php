<?php

namespace Peralta\AgentKit\Tests\Unit\DTOs;

use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\DTOs\ToolCall;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    public function test_user_constructor()
    {
        $msg = Message::user('olá');

        $this->assertSame('user', $msg->role);
        $this->assertSame('olá', $msg->content);
    }

    public function test_assistant_constructor_with_tool_calls()
    {
        $calls = [new ToolCall('id-1', 'search', [])];
        $msg = Message::assistant('texto', $calls);

        $this->assertSame('assistant', $msg->role);
        $this->assertSame('texto', $msg->content);
        $this->assertSame($calls, $msg->toolCalls);
    }

    public function test_system_constructor()
    {
        $msg = Message::system('regra do sistema');

        $this->assertSame('system', $msg->role);
        $this->assertSame('regra do sistema', $msg->content);
    }

    public function test_tool_constructor_with_string_result()
    {
        $msg = Message::tool('call-1', 'resultado simples');

        $this->assertSame('tool', $msg->role);
        $this->assertSame('call-1', $msg->toolCallId);
        $this->assertSame('resultado simples', $msg->content);
    }

    public function test_tool_constructor_json_encodes_non_string_result()
    {
        $msg = Message::tool('call-1', ['erro' => 'algo falhou']);

        $this->assertSame('{"erro":"algo falhou"}', $msg->content);
    }

    public function test_to_array_omits_null_fields()
    {
        $msg = Message::user('oi');

        $array = $msg->toArray();

        $this->assertArrayNotHasKey('tool_calls', $array);
        $this->assertArrayNotHasKey('tool_call_id', $array);
        $this->assertArrayNotHasKey('metadata', $array);
        $this->assertSame('user', $array['role']);
        $this->assertSame('oi', $array['content']);
    }

    public function test_to_array_includes_serialized_tool_calls()
    {
        $msg = Message::assistant(null, [new ToolCall('id-1', 'search', ['q' => 'php'])]);

        $array = $msg->toArray();

        $this->assertSame([['id' => 'id-1', 'name' => 'search', 'arguments' => ['q' => 'php']]], $array['tool_calls']);
    }
}
