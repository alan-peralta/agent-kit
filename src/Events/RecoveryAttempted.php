<?php

namespace Peralta\AgentKit\Events;

class RecoveryAttempted implements AgentKitEvent
{
    public function __construct(
        public readonly ?string $conversationId,
        public readonly string $provider,
        public readonly int $attemptNumber,
        public readonly string $strategy, // 'retry' | 'fallback'
        public readonly string $errorClass,
    ) {}
}
