<?php

namespace Peralta\AgentKit\Tests\Unit\Providers;

use GuzzleHttp\Psr7\Response;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\DTOs\ToolCall;
use Peralta\AgentKit\Exceptions\ProviderException;
use Peralta\AgentKit\Providers\AnthropicProvider;
use Peralta\AgentKit\Tests\Unit\Providers\Concerns\MocksGuzzleHttp;
use PHPUnit\Framework\TestCase;

class AnthropicProviderTest extends TestCase
{
    use MocksGuzzleHttp;

    public function test_chat_sends_correct_url_headers_and_payload()
    {
        [$provider, $history] = $this->provider([
            new Response(200, [], json_encode([
                'content' => [['type' => 'text', 'text' => 'oi']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])),
        ]);

        $provider->chat(messages: [Message::user('olá')], system: 'seja breve');

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('http://api.test/messages', (string) $request->getUri());
        $this->assertSame('secret-key', $request->getHeaderLine('x-api-key'));
        $this->assertSame('2023-06-01', $request->getHeaderLine('anthropic-version'));

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('claude-x', $body['model']);
        $this->assertSame('seja breve', $body['system']);
        $this->assertSame([['role' => 'user', 'content' => 'olá']], $body['messages']);
    }

    public function test_chat_parses_text_response()
    {
        [$provider] = $this->provider([
            new Response(200, [], json_encode([
                'content' => [['type' => 'text', 'text' => 'resposta']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
            ])),
        ]);

        $response = $provider->chat(messages: [Message::user('oi')]);

        $this->assertSame('resposta', $response->text());
        $this->assertSame('stop', $response->stopReason);
        $this->assertFalse($response->hasToolCalls());
        $this->assertSame(3, $response->inputTokens);
        $this->assertSame(2, $response->outputTokens);
    }

    public function test_chat_parses_tool_use_response()
    {
        [$provider] = $this->provider([
            new Response(200, [], json_encode([
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'call-1',
                    'name' => 'search',
                    'input' => ['q' => 'php'],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ])),
        ]);

        $response = $provider->chat(messages: [Message::user('busque')], tools: [$this->fakeTool()]);

        $this->assertTrue($response->hasToolCalls());
        $this->assertSame('tool_use', $response->stopReason);
        $this->assertSame('call-1', $response->toolCalls()[0]->id);
        $this->assertSame('search', $response->toolCalls()[0]->name);
        $this->assertSame(['q' => 'php'], $response->toolCalls()[0]->arguments);
    }

    public function test_chat_includes_formatted_tools_in_payload()
    {
        [$provider, $history] = $this->provider([
            new Response(200, [], json_encode([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'stop_reason' => 'end_turn',
            ])),
        ]);

        $provider->chat(messages: [Message::user('oi')], tools: [$this->fakeTool()]);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame([[
            'name' => 'search',
            'description' => 'Busca coisas',
            'input_schema' => ['type' => 'object'],
        ]], $body['tools']);
    }

    public function test_chat_serializes_tool_result_message_into_tool_result_content_block()
    {
        [$provider, $history] = $this->provider([
            new Response(200, [], json_encode([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'stop_reason' => 'end_turn',
            ])),
        ]);

        $messages = [
            Message::user('busque algo'),
            Message::assistant(null, [new ToolCall('call-1', 'search', ['q' => 'php'])]),
            Message::tool('call-1', 'resultado da busca'),
        ];

        $provider->chat(messages: $messages);

        $body = json_decode((string) $history[0]['request']->getBody(), true);

        $this->assertSame([
            'role' => 'user',
            'content' => [[
                'type' => 'tool_result',
                'tool_use_id' => 'call-1',
                'content' => 'resultado da busca',
            ]],
        ], $body['messages'][2]);
    }

    public function test_throws_provider_exception_on_missing_content_key()
    {
        [$provider] = $this->provider([
            new Response(200, [], json_encode(['stop_reason' => 'end_turn'])),
        ]);

        $this->expectException(ProviderException::class);
        $provider->chat(messages: [Message::user('oi')]);
    }

    public function test_throws_provider_exception_on_malformed_json()
    {
        [$provider] = $this->provider([
            new Response(200, [], 'not json at all'),
        ]);

        $this->expectException(ProviderException::class);
        $provider->chat(messages: [Message::user('oi')]);
    }

    public function test_throws_provider_exception_on_http_error_status()
    {
        [$provider] = $this->provider([
            new Response(500, [], json_encode(['error' => 'server error'])),
        ]);

        $this->expectException(ProviderException::class);
        $provider->chat(messages: [Message::user('oi')]);
    }

    private function provider(array $responses): array
    {
        [$stack, $history] = $this->mockHandlerStack($responses);

        $provider = new AnthropicProvider([
            'base_url' => 'http://api.test',
            'api_key' => 'secret-key',
            'model' => 'claude-x',
            'anthropic_version' => '2023-06-01',
            'max_tokens' => 4096,
            'handler' => $stack,
        ]);

        return [$provider, $history];
    }
}
