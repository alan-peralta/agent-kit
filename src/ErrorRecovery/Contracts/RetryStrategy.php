<?php

namespace Peralta\AgentKit\ErrorRecovery\Contracts;

use Peralta\AgentKit\ErrorRecovery\Enums\ErrorType;

interface RetryStrategy
{
    public function shouldRetry(ErrorType $type, int $attempt): bool;

    public function delayMs(ErrorType $type, int $attempt): int;
}
