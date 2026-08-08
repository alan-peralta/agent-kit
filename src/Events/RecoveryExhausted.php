<?php

namespace Peralta\AgentKit\Events;

class RecoveryExhausted implements AgentKitEvent
{
    public function __construct(
        public readonly ?string $conversationId,
        public readonly int $attempts,
        public readonly string $finalErrorClass,
    ) {}
}
