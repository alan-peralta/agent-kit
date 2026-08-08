<?php

namespace Peralta\AgentKit\ErrorRecovery\Contracts;

use Peralta\AgentKit\DTOs\RecoveryContext;

interface Middleware
{
    /**
     * @param  callable(RecoveryContext): mixed  $handler
     */
    public function handle(callable $handler, RecoveryContext $context): mixed;
}
