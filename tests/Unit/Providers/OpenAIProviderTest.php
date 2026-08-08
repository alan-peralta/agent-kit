<?php

namespace Peralta\AgentKit\Tests\Unit\Providers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Peralta\AgentKit\Contracts\Tool;
use Peralta\AgentKit\DTOs\Context;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\Exceptions\ProviderException;
use Peralta\AgentKit\Providers\OpenAIProvider;
use PHPUnit\Framework\TestCase;

class OpenAIProviderTest extends TestCase
{
    public function test_chat_sends_correct_url_headers_and_payload()
    {
        [$provider, $history] = $this->provider([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'oi'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ])),
        ]);

        $provider->chat(messages: [Message::user('olá')], system: 'seja breve');

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('http://api.test/chat/completions', (string) $request->getUri());
        $this->assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('gpt-4o', $body['model']);
        $this->assertSame([
            ['role' => 'system', 'content' => 'seja breve'],
            ['role' => 'user', 'content' => 'olá'],
        ], $body['messages']);
    }

    public function test_chat_parses_text_response()
    {
        [$provider] = $this->provider([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'resposta'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2],
            ])),
        ]);

        $response = $provider->chat(messages: [Message::user('oi')]);

        $this->assertSame('resposta', $response->text());
        $this->assertSame('stop', $response->stopReason);
        $this->assertSame(3, $response->inputTokens);
        $this->assertSame(2, $response->outputTokens);
    }

    public function test_chat_decodes_tool_call_arguments_json_string_into_array()
    {
        [$provider] = $this->provider([
            new Response(200, [], json_encode([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call-1',
                            'type' => 'function',
                            'function' => ['name' => 'search', 'arguments' => '{"q":"php"}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ])),
        ]);

        $response = $provider->chat(messages: [Message::user('busque')], tools: [$this->fakeTool()]);

        $this->assertTrue($response->hasToolCalls());
        $this->assertSame('tool_use', $response->stopReason);
        $this->assertSame('call-1', $response->toolCalls()[0]->id);
        $this->assertSame(['q' => 'php'], $response->toolCalls()[0]->arguments);
    }

    public function test_chat_includes_formatted_tools_and_tool_choice_in_payload()
    {
        [$provider, $history] = $this->provider([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
            ])),
        ]);

        $provider->chat(messages: [Message::user('oi')], tools: [$this->fakeTool()]);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('auto', $body['tool_choice']);
        $this->assertSame([[
            'type' => 'function',
            'function' => [
                'name' => 'search',
                'description' => 'Busca coisas',
                'parameters' => ['type' => 'object'],
            ],
        ]], $body['tools']);
    }

    public function test_throws_provider_exception_on_missing_choices()
    {
        [$provider] = $this->provider([
            new Response(200, [], json_encode(['choices' => []])),
        ]);

        $this->expectException(ProviderException::class);
        $provider->chat(messages: [Message::user('oi')]);
    }

    public function test_throws_provider_exception_on_malformed_json()
    {
        [$provider] = $this->provider([
            new Response(200, [], 'not json'),
        ]);

        $this->expectException(ProviderException::class);
        $provider->chat(messages: [Message::user('oi')]);
    }

    public function test_throws_provider_exception_on_http_error_status()
    {
        [$provider] = $this->provider([
            new Response(500, [], json_encode(['error' => 'boom'])),
        ]);

        $this->expectException(ProviderException::class);
        $provider->chat(messages: [Message::user('oi')]);
    }

    private function provider(array $responses): array
    {
        $history = new \ArrayObject();
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        $provider = new OpenAIProvider([
            'base_url' => 'http://api.test',
            'api_key' => 'secret-key',
            'model' => 'gpt-4o',
            'max_tokens' => 4096,
            'handler' => $stack,
        ]);

        return [$provider, $history];
    }

    private function fakeTool(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'search'; }
            public function description(): string { return 'Busca coisas'; }
            public function schema(): array { return ['type' => 'object']; }
            public function authorize(Context $context): bool { return true; }
            public function handle(array $input, Context $context): mixed { return []; }
        };
    }
}
