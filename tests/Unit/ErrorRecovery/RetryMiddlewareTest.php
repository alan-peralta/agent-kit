<?php

namespace Peralta\AgentKit\Tests\Unit\ErrorRecovery;

use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\ErrorRecovery\Classifiers\DefaultErrorClassifier;
use Peralta\AgentKit\ErrorRecovery\Middleware\RetryMiddleware;
use Peralta\AgentKit\ErrorRecovery\Strategies\AdaptiveRetryStrategy;
use Peralta\AgentKit\Exceptions\ProviderException;
use Peralta\AgentKit\Tests\TestCase;

class RetryMiddlewareTest extends TestCase
{
    private function fastPolicies(int $maxAttempts): array
    {
        return [
            'NETWORK_TIMEOUT' => [
                'max_attempts' => $maxAttempts,
                'initial_delay_ms' => 1,
                'max_delay_ms' => 5,
                'multiplier' => 1.0,
                'jitter' => false,
            ],
            'INVALID_REQUEST' => ['max_attempts' => 0],
        ];
    }

    public function test_retries_until_success()
    {
        $attempts = 0;
        $handler = function (RecoveryContext $ctx) use (&$attempts) {
            $attempts++;
            $ctx->recordAttempt($ctx->provider);
            if ($attempts < 3) {
                throw $this->networkTimeoutException();
            }
            return 'success';
        };

        $middleware = new RetryMiddleware(new AdaptiveRetryStrategy($this->fastPolicies(7)), new DefaultErrorClassifier());

        $result = $middleware->handle($handler, new RecoveryContext(provider: 'openai'));

        $this->assertEquals('success', $result);
        $this->assertEquals(3, $attempts);
    }

    public function test_stops_after_max_attempts_and_rethrows()
    {
        $attempts = 0;
        $handler = function (RecoveryContext $ctx) use (&$attempts) {
            $attempts++;
            throw $this->networkTimeoutException();
        };

        $middleware = new RetryMiddleware(new AdaptiveRetryStrategy($this->fastPolicies(3)), new DefaultErrorClassifier());

        $this->expectException(ProviderException::class);

        try {
            $middleware->handle($handler, new RecoveryContext(provider: 'openai'));
        } finally {
            $this->assertEquals(3, $attempts);
        }
    }

    public function test_does_not_retry_non_retryable_error()
    {
        $attempts = 0;
        $handler = function (RecoveryContext $ctx) use (&$attempts) {
            $attempts++;
            throw $this->invalidRequestException();
        };

        $middleware = new RetryMiddleware(new AdaptiveRetryStrategy($this->fastPolicies(7)), new DefaultErrorClassifier());

        $this->expectException(ProviderException::class);

        try {
            $middleware->handle($handler, new RecoveryContext(provider: 'openai'));
        } finally {
            $this->assertEquals(1, $attempts);
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
