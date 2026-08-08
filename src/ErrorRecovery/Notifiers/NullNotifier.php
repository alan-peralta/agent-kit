<?php

namespace Peralta\AgentKit\ErrorRecovery\Notifiers;

use Peralta\AgentKit\ErrorRecovery\Contracts\AlertNotifier;

class NullNotifier implements AlertNotifier
{
    public function notifyFailure(string $message, array $context): void
    {
        // no-op
    }

    public function notifyFallback(string $provider, string $reason): void
    {
        // no-op
    }
}
