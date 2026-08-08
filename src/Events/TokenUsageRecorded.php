<?php

namespace Peralta\AgentKit\Events;

class TokenUsageRecorded implements AgentKitEvent
{
    public function __construct(
        public readonly ?string $conversationId,
        public readonly string $provider,
        public readonly ?string $model,
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
    ) {}
}
