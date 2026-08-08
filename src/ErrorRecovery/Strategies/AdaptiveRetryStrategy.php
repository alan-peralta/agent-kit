<?php

namespace Peralta\AgentKit\ErrorRecovery\Strategies;

use Peralta\AgentKit\ErrorRecovery\Contracts\RetryStrategy;
use Peralta\AgentKit\ErrorRecovery\Enums\ErrorType;

class AdaptiveRetryStrategy implements RetryStrategy
{
    /**
     * @param  array<string, array{max_attempts?: int, initial_delay_ms?: int, max_delay_ms?: int, multiplier?: float, jitter?: bool}>  $policies
     */
    public function __construct(
        protected array $policies = [],
    ) {}

    public function shouldRetry(ErrorType $type, int $attempt): bool
    {
        $maxAttempts = $this->policyFor($type)['max_attempts'] ?? 0;

        return $attempt < $maxAttempts;
    }

    public function delayMs(ErrorType $type, int $attempt): int
    {
        $policy = $this->policyFor($type);

        $initial = $policy['initial_delay_ms'] ?? 1000;
        $max = $policy['max_delay_ms'] ?? 30000;
        $multiplier = $policy['multiplier'] ?? 2.0;
        $jitter = $policy['jitter'] ?? false;

        $delay = (int) min($initial * ($multiplier ** ($attempt - 1)), $max);

        if ($jitter) {
            $delay = min($delay + random_int(0, $delay), $max);
        }

        return $delay;
    }

    /**
     * @return array{max_attempts?: int, initial_delay_ms?: int, max_delay_ms?: int, multiplier?: float, jitter?: bool}
     */
    protected function policyFor(ErrorType $type): array
    {
        return $this->policies[$type->value] ?? [];
    }
}
