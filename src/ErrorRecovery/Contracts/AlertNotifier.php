<?php

namespace Peralta\AgentKit\ErrorRecovery\Contracts;

interface AlertNotifier
{
    public function notifyFailure(string $message, array $context): void;

    public function notifyFallback(string $provider, string $reason): void;
}
