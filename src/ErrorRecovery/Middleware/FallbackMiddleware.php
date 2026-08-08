<?php

namespace Peralta\AgentKit\ErrorRecovery\Middleware;

use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\ErrorRecovery\Contracts\ErrorClassifier;
use Peralta\AgentKit\ErrorRecovery\Contracts\Middleware;
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

        try {
            return $handler($context);
        } catch (Throwable $e) {
            $errorType = $this->classifier->classify($e);

            if (!$this->isFallbackEligible($errorType->value)) {
                throw $e;
            }

            foreach ($this->remainingProviders($tried) as $provider) {
                $tried[] = $provider;
                $context->switchProvider($provider);

                try {
                    return $handler($context);
                } catch (Throwable $inner) {
                    $innerType = $this->classifier->classify($inner);
                    $context->recordFailure($provider, $innerType->value);
                    continue;
                }
            }

            throw new AllProvidersFailedException(
                "Todos os providers falharam: " . implode(', ', $tried),
                $context->summary(),
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
