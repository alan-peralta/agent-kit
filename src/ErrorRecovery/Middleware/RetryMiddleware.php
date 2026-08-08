<?php

namespace Peralta\AgentKit\ErrorRecovery\Middleware;

use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\ErrorRecovery\Contracts\ErrorClassifier;
use Peralta\AgentKit\ErrorRecovery\Contracts\Middleware;
use Peralta\AgentKit\ErrorRecovery\Contracts\RetryStrategy;
use Throwable;

class RetryMiddleware implements Middleware
{
    public function __construct(
        protected RetryStrategy $strategy,
        protected ErrorClassifier $classifier,
    ) {}

    public function handle(callable $handler, RecoveryContext $context): mixed
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $handler($context);
            } catch (Throwable $e) {
                $errorType = $this->classifier->classify($e);
                $context->recordFailure($context->provider, $errorType->value);

                if (!$this->strategy->shouldRetry($errorType, $attempt)) {
                    throw $e;
                }

                usleep($this->strategy->delayMs($errorType, $attempt) * 1000);
            }
        }
    }
}
