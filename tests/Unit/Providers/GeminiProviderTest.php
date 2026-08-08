<?php

namespace Peralta\AgentKit\Tests\Unit\Providers;

use GuzzleHttp\Psr7\Response;
use Peralta\AgentKit\Contracts\Tool;
use Peralta\AgentKit\DTOs\Context;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\Exceptions\ProviderException;
use Peralta\AgentKit\Providers\GeminiProvider;
use Peralta\AgentKit\Tests\Unit\Providers\Concerns\MocksGuzzleHttp;
use PHPUnit\Framework\TestCase;

class GeminiProviderTest extends TestCase
{
    use MocksGuzzleHttp;

    public function test_chat_sends_api_key_in_query_string_and_correct_payload()
    {
        [$provider, $history] = $this->provider([
            new Response(200, [], json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'oi']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ])),
        ]);

        $provider->chat(messages: [Message::user('olá')], system: 'seja breve');

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString('models/gemini-x:generateContent?key=secret-key', (string) $request->getUri());

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('seja breve', $body['systemInstruction']['parts'][0]['text']);
        $this->assertSame([['role' => 'user', 'parts' => [['text' => 'olá']]]], $body['contents']);
    }

    public function test_chat_parses_text_response()
    {
        [$provider] = $this->provider([
            new Response(200, [], json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'resposta']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 2],
            ])),
        ]);

        $response = $provider->chat(messages: [Message::user('oi')]);

        $this->assertSame('resposta', $response->text());
        $this->assertSame('stop', $response->stopReason);
        $this->assertSame(3, $response->inputTokens);
        $this->assertSame(2, $response->outputTokens);
    }

    public function test_chat_generates_synthetic_tool_call_id()
    {
        [$provider] = $this->provider([
            new Response(200, [], json_encode([
                'candidates' => [[
                    'content' => ['parts' => [['functionCall' => ['name' => 'search', 'args' => ['q' => 'php']]]]],
                    'finishReason' => 'STOP',
                ]],
            ])),
        ]);

        $response = $provider->chat(messages: [Message::user('busque')], tools: [$this->fakeTool()]);

        $this->assertTrue($response->hasToolCalls());
        $this->assertSame('tool_use', $response->stopReason);
        $this->assertStringStartsWith('gemini_', $response->toolCalls()[0]->id);
        $this->assertSame('search', $response->toolCalls()[0]->name);
        $this->assertSame(['q' => 'php'], $response->toolCalls()[0]->arguments);
    }

    public function test_clean_schema_for_gemini_strips_disallowed_keys_from_tool_payload()
    {
        [$provider, $history] = $this->provider([
            new Response(200, [], json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
            ])),
        ]);

        $tool = new class implements Tool {
            public function name(): string { return 'search'; }
            public function description(): string { return 'Busca coisas'; }
            public function schema(): array {
                return [
                    'type' => 'object',
                    'default' => 'x',
                    '$schema' => 'http://json-schema.org/draft-07/schema#',
                    'properties' => [
                        'q' => ['type' => 'string', 'additionalProperties' => false, 'examples' => ['php']],
                    ],
                ];
            }
            public function authorize(Context $context): bool { return true; }
            public function handle(array $input, Context $context): mixed { return []; }
        };

        $provider->chat(messages: [Message::user('oi')], tools: [$tool]);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $params = $body['tools'][0]['functionDeclarations'][0]['parameters'];

        $this->assertArrayNotHasKey('default', $params);
        $this->assertArrayNotHasKey('$schema', $params);
        $this->assertArrayNotHasKey('additionalProperties', $params['properties']['q']);
        $this->assertArrayNotHasKey('examples', $params['properties']['q']);
        $this->assertSame('string', $params['properties']['q']['type']);
    }

    public function test_throws_provider_exception_on_missing_candidates()
    {
        [$provider] = $this->provider([
            new Response(200, [], json_encode(['candidates' => []])),
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

        $provider = new GeminiProvider([
            'base_url' => 'http://api.test',
            'api_key' => 'secret-key',
            'model' => 'gemini-x',
            'max_tokens' => 4096,
            'handler' => $stack,
        ]);

        return [$provider, $history];
    }
}
