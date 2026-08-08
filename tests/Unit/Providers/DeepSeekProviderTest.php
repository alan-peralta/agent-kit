<?php

namespace Peralta\AgentKit\Tests\Unit\Providers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\Providers\DeepSeekProvider;
use PHPUnit\Framework\TestCase;

class DeepSeekProviderTest extends TestCase
{
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
        $history = new \ArrayObject();
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'oi'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2],
            ])),
        ]));
        $stack->push(Middleware::history($history));

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
}
