<?php

namespace Peralta\AgentKit\Tests\Unit\Providers;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\ErrorRecovery\Classifiers\DefaultErrorClassifier;
use Peralta\AgentKit\ErrorRecovery\Enums\ErrorType;
use Peralta\AgentKit\Exceptions\ProviderException;
use Peralta\AgentKit\Providers\OpenAIProvider;
use Peralta\AgentKit\Tests\Unit\Providers\Concerns\MocksGuzzleHttp;
use PHPUnit\Framework\TestCase;

class OpenAIProviderTest extends TestCase
{
    use MocksGuzzleHttp;

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

    public function test_chat_sends_response_format_when_option_is_set()
    {
        [$provider, $history] = $this->provider([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => '{"ok":true}'], 'finish_reason' => 'stop']],
            ])),
        ]);

        $provider->chat(
            messages: [Message::user('responda em json')],
            options: ['response_format' => ['type' => 'json_object']],
        );

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(['type' => 'json_object'], $body['response_format']);
    }

    /**
     * Guard de retrocompatibilidade: sem a opção, o payload é idêntico ao de antes
     * da introdução de response_format — consumidores não podem receber a chave.
     */
    public function test_chat_omits_response_format_by_default()
    {
        [$provider, $history] = $this->provider([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'oi'], 'finish_reason' => 'stop']],
            ])),
        ]);

        $provider->chat(messages: [Message::user('oi')]);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertArrayNotHasKey('response_format', $body);
    }

    public function test_chat_applies_per_call_timeout_to_request_options()
    {
        [$provider, $history] = $this->provider([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'oi'], 'finish_reason' => 'stop']],
            ])),
        ]);

        $provider->chat(messages: [Message::user('oi')], options: ['timeout' => 8]);

        $this->assertSame(8, $history[0]['options']['timeout']);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertArrayNotHasKey('timeout', $body);
    }

    /**
     * Guard de retrocompatibilidade: sem a opção, vale o timeout de client
     * (default 60 s de AbstractProvider), que o Guzzle mescla nas opções da requisição.
     */
    public function test_chat_keeps_client_timeout_when_option_is_absent()
    {
        [$provider, $history] = $this->provider([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'oi'], 'finish_reason' => 'stop']],
            ])),
        ]);

        $provider->chat(messages: [Message::user('oi')]);

        $this->assertSame(60, $history[0]['options']['timeout']);
    }

    /**
     * Guard: o timeout por chamada não muda o tratamento de erro — a falha continua
     * chegando como ProviderException com ConnectException em previous, o que mantém
     * a classificação NETWORK_TIMEOUT do DefaultErrorClassifier.
     */
    public function test_chat_per_call_timeout_failure_keeps_provider_exception_with_connect_exception_previous()
    {
        $connectException = new ConnectException(
            'cURL error 28: Operation timed out',
            new Request('POST', 'http://api.test/chat/completions'),
        );

        [$provider] = $this->provider([$connectException]);

        try {
            $provider->chat(messages: [Message::user('oi')], options: ['timeout' => 1]);
            $this->fail('Esperava ProviderException.');
        } catch (ProviderException $e) {
            $this->assertInstanceOf(ConnectException::class, $e->getPrevious());
            $this->assertSame(ErrorType::NETWORK_TIMEOUT, (new DefaultErrorClassifier)->classify($e));
        }
    }

    public function test_chat_serializes_tool_result_message_into_flat_tool_message()
    {
        [$provider, $history] = $this->provider([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
            ])),
        ]);

        $messages = [
            Message::user('busque algo'),
            Message::assistant(null, [new \Peralta\AgentKit\DTOs\ToolCall('call-1', 'search', ['q' => 'php'])]),
            Message::tool('call-1', 'resultado'),
        ];

        $provider->chat(messages: $messages);

        $body = json_decode((string) $history[0]['request']->getBody(), true);

        $this->assertSame([
            'role' => 'tool',
            'tool_call_id' => 'call-1',
            'content' => 'resultado',
        ], $body['messages'][2]);
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
        [$stack, $history] = $this->mockHandlerStack($responses);

        $provider = new OpenAIProvider([
            'base_url' => 'http://api.test',
            'api_key' => 'secret-key',
            'model' => 'gpt-4o',
            'max_tokens' => 4096,
            'handler' => $stack,
        ]);

        return [$provider, $history];
    }
}
