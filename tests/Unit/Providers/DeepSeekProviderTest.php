<?php

namespace Peralta\AgentKit\Tests\Unit\Providers;

use GuzzleHttp\Psr7\Response;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\Providers\DeepSeekProvider;
use Peralta\AgentKit\Tests\Unit\Providers\Concerns\MocksGuzzleHttp;
use PHPUnit\Framework\TestCase;

class DeepSeekProviderTest extends TestCase
{
    use MocksGuzzleHttp;

    public function test_name_returns_deepseek()
    {
        $provider = new DeepSeekProvider([
            'base_url' => 'http://api.test',
            'api_key' => 'secret-key',
            'model' => 'deepseek-chat',
        ]);

        $this->assertSame('deepseek', $provider->name());
    }

    public function test_chat_uses_openai_compatible_request_and_response_shape()
    {
        [$stack, $history] = $this->mockHandlerStack([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'oi'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2],
            ])),
        ]);

        $provider = new DeepSeekProvider([
            'base_url' => 'http://api.test',
            'api_key' => 'secret-key',
            'model' => 'deepseek-chat',
            'handler' => $stack,
        ]);

        $response = $provider->chat(messages: [Message::user('olá')]);

        $request = $history[0]['request'];
        $this->assertSame('http://api.test/chat/completions', (string) $request->getUri());
        $this->assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('oi', $response->text());
        $this->assertSame('stop', $response->stopReason);
        $this->assertSame(4, $response->inputTokens);
        $this->assertSame(2, $response->outputTokens);
    }

    public function test_chat_forwards_response_format_and_timeout_options()
    {
        [$stack, $history] = $this->mockHandlerStack([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => '{"uuids":[]}'], 'finish_reason' => 'stop']],
            ])),
        ]);

        $provider = new DeepSeekProvider([
            'base_url' => 'http://api.test',
            'api_key' => 'secret-key',
            'model' => 'deepseek-chat',
            'handler' => $stack,
        ]);

        $provider->chat(
            messages: [Message::user('responda em json')],
            options: ['response_format' => ['type' => 'json_object'], 'timeout' => 8],
        );

        $request = $history[0]['request'];
        $this->assertSame('http://api.test/chat/completions', (string) $request->getUri());

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame(['type' => 'json_object'], $body['response_format']);
        $this->assertArrayNotHasKey('timeout', $body);
        $this->assertSame(8, $history[0]['options']['timeout']);
    }
}
