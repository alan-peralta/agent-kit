<?php

namespace Peralta\AgentKit\Events;

class ProviderCallCompleted implements AgentKitEvent
{
    public function __construct(
        public readonly ?string $conversationId,
        public readonly string $provider,
        public readonly ?string $model,
        public readonly int $durationMs,
        public readonly int $iterationNumber,
    ) {}
}
