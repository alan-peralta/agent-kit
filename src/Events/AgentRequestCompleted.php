<?php

namespace Peralta\AgentKit\Events;

class AgentRequestCompleted implements AgentKitEvent
{
    public function __construct(
        public readonly ?string $conversationId,
        public readonly string $provider,
        public readonly ?string $model,
        public readonly int $durationMs,
        public readonly int $iterations,
    ) {}
}
