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

    public function test_all_providers_failed_exception_preserves_last_exception_as_previous()
    {
        $handler = function (RecoveryContext $ctx) {
            throw $this->networkTimeoutException();
        };

        $middleware = new FallbackMiddleware(
            new DefaultErrorClassifier(),
            ['openai', 'anthropic'],
            $this->config(),
        );

        try {
            $middleware->handle($handler, new RecoveryContext(provider: 'openai'));
            $this->fail('Expected AllProvidersFailedException to be thrown');
        } catch (AllProvidersFailedException $e) {
            $this->assertInstanceOf(ProviderException::class, $e->getPrevious());
        }
    }

    public function test_summary_includes_original_provider_failure()
    {
        $handler = function (RecoveryContext $ctx) {
            throw $this->networkTimeoutException();
        };

        $middleware = new FallbackMiddleware(
            new DefaultErrorClassifier(),
            ['openai', 'anthropic'],
            $this->config(),
        );

        try {
            $middleware->handle($handler, new RecoveryContext(provider: 'openai'));
            $this->fail('Expected AllProvidersFailedException to be thrown');
        } catch (AllProvidersFailedException $e) {
            $this->assertArrayHasKey('openai', $e->providerSummary);
            $this->assertEquals('NETWORK_TIMEOUT', $e->providerSummary['openai']['last_error']);
        }
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

    public function test_dispatches_recovery_attempted_on_switch_and_recovery_exhausted_when_all_fail()
    {
        \Illuminate\Support\Facades\Event::fake();

        $classifier = \Mockery::mock(\Peralta\AgentKit\ErrorRecovery\Contracts\ErrorClassifier::class);
        $classifier->shouldReceive('classify')->andReturn(\Peralta\AgentKit\ErrorRecovery\Enums\ErrorType::SERVER_ERROR);

        $middleware = new FallbackMiddleware(
            $classifier,
            ['openai', 'anthropic'],
            ['on_errors' => ['SERVER_ERROR'], 'skip_on_errors' => []],
        );

        $context = new RecoveryContext(provider: 'openai', conversationId: 'conv-1');

        try {
            $middleware->handle(function () {
                throw new \RuntimeException('boom');
            }, $context);
            $this->fail('Expected exception was not thrown');
        } catch (AllProvidersFailedException) {
            // esperado
        }

        \Illuminate\Support\Facades\Event::assertDispatched(\Peralta\AgentKit\Events\RecoveryAttempted::class, fn($e) => $e->strategy === 'fallback' && $e->provider === 'anthropic');
        \Illuminate\Support\Facades\Event::assertDispatched(\Peralta\AgentKit\Events\RecoveryExhausted::class);
    }
}
