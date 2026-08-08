<?php

namespace Peralta\AgentKit\ErrorRecovery\Middleware;

use Illuminate\Support\Facades\Event;
use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\ErrorRecovery\Contracts\ErrorClassifier;
use Peralta\AgentKit\ErrorRecovery\Contracts\Middleware;
use Peralta\AgentKit\Events\RecoveryAttempted;
use Peralta\AgentKit\Events\RecoveryExhausted;
use Peralta\AgentKit\Exceptions\AllProvidersFailedException;
use Throwable;

class FallbackMiddleware implements Middleware
{
    /**
     * @param  string[]  $availableProviders  Todos os providers configurados, na ordem de fallback.
     * @param  array{on_errors?: string[], skip_on_errors?: string[]}  $config
     */
    public function __construct(
        protected ErrorClassifier $classifier,
        protected array $availableProviders,
        protected array $config = [],
    ) {}

    public function handle(callable $handler, RecoveryContext $context): mixed
    {
        $originalProvider = $context->provider;
        $tried = [$originalProvider];
        $attempt = 1;

        try {
            return $handler($context);
        } catch (Throwable $e) {
            $errorType = $this->classifier->classify($e);
            $context->recordFailure($originalProvider, $errorType->value);

            if (!$this->isFallbackEligible($errorType->value)) {
                throw $e;
            }

            $lastException = $e;
            $lastErrorType = $errorType->value;

            foreach ($this->remainingProviders($tried) as $provider) {
                $tried[] = $provider;
                $attempt++;
                $context->switchProvider($provider);

                Event::dispatch(new RecoveryAttempted(
                    conversationId: $context->conversationId,
                    provider: $provider,
                    attemptNumber: $attempt,
                    strategy: 'fallback',
                    errorClass: $lastErrorType,
                ));

                try {
                    return $handler($context);
                } catch (Throwable $inner) {
                    $innerType = $this->classifier->classify($inner);
                    $context->recordFailure($provider, $innerType->value);
                    $lastException = $inner;
                    $lastErrorType = $innerType->value;
                    continue;
                }
            }

            Event::dispatch(new RecoveryExhausted(
                conversationId: $context->conversationId,
                attempts: $attempt,
                finalErrorClass: $lastErrorType,
            ));

            throw new AllProvidersFailedException(
                "Todos os providers falharam: " . implode(', ', $tried),
                $context->summary(),
                $lastException,
            );
        }
    }

    private function isFallbackEligible(string $errorType): bool
    {
        $onErrors = $this->config['on_errors'] ?? [];
        $skipErrors = $this->config['skip_on_errors'] ?? [];

        if (in_array($errorType, $skipErrors, true)) {
            return false;
        }

        return in_array($errorType, $onErrors, true);
    }

    /**
     * @param  string[]  $tried
     * @return string[]
     */
    private function remainingProviders(array $tried): array
    {
        return array_values(array_diff($this->availableProviders, $tried));
    }
}
