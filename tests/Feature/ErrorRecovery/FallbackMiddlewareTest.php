<?php

namespace Peralta\AgentKit\Tests\Feature\ErrorRecovery;

use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\ErrorRecovery\Classifiers\DefaultErrorClassifier;
use Peralta\AgentKit\ErrorRecovery\Middleware\FallbackMiddleware;
use Peralta\AgentKit\Exceptions\AllProvidersFailedException;
use Peralta\AgentKit\Exceptions\ProviderException;
use Peralta\AgentKit\Tests\TestCase;

class FallbackMiddlewareTest extends TestCase
{
    private function config(): array
    {
        return [
            'on_errors' => ['NETWORK_TIMEOUT', 'SERVER_ERROR', 'RATE_LIMIT', 'AUTH_ERROR'],
            'skip_on_errors' => ['INVALID_REQUEST'],
        ];
    }

    public function test_falls_back_to_next_available_provider()
    {
        $calls = [];
        $handler = function (RecoveryContext $ctx) use (&$calls) {
            $calls[] = $ctx->provider;
            if ($ctx->provider === 'openai') {
                throw $this->networkTimeoutException();
            }
            return "success from {$ctx->provider}";
        };

        $middleware = new FallbackMiddleware(
            new DefaultErrorClassifier(),
            ['openai', 'anthropic'],
            $this->config(),
        );

        $result = $middleware->handle($handler, new RecoveryContext(provider: 'openai'));

        $this->assertEquals('success from anthropic', $result);
        $this->assertEquals(['openai', 'anthropic'], $calls);
    }

    public function test_throws_all_providers_failed_when_every_provider_fails()
    {
        $handler = function (RecoveryContext $ctx) {
            throw $this->networkTimeoutException();
        };

        $middleware = new FallbackMiddleware(
            new DefaultErrorClassifier(),
            ['openai', 'anthropic'],
            $this->config(),
        );

        $this->expectException(AllProvidersFailedException::class);

        $middleware->handle($handler, new RecoveryContext(provider: 'openai'));
    }

    public function test_skips_fallback_for_invalid_request_errors()
    {
        $calls = [];
        $handler = function (RecoveryContext $ctx) use (&$calls) {
            $calls[] = $ctx->provider;
            throw $this->invalidRequestException();
        };

        $middleware = new FallbackMiddleware(
            new DefaultErrorClassifier(),
            ['openai', 'anthropic'],
            $this->config(),
        );

        $this->expectException(ProviderException::class);

        try {
            $middleware->handle($handler, new RecoveryContext(provider: 'openai'));
        } finally {
            $this->assertEquals(['openai'], $calls);
        }
    }

    private function networkTimeoutException(): ProviderException
    {
        $request = new \GuzzleHttp\Psr7\Request('POST', 'https://api.openai.com/v1/chat/completions');
        $previous = new \GuzzleHttp\Exception\ConnectException('timeout', $request);
        return new ProviderException('Erro chamando openai: timeout', 0, $previous);
    }

    private function invalidRequestException(): ProviderException
    {
        $request = new \GuzzleHttp\Psr7\Request('POST', 'https://api.openai.com/v1/chat/completions');
        $response = new \GuzzleHttp\Psr7\Response(400, [], '{"error":"bad"}');
        $previous = new \GuzzleHttp\Exception\RequestException('bad request', $request, $response);
        return new ProviderException('Erro chamando openai: bad request', 0, $previous);
    }
}
