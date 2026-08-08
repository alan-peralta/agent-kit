<?php

namespace Peralta\AgentKit\Events;

class ToolCallExecuted implements AgentKitEvent
{
    public function __construct(
        public readonly ?string $conversationId,
        public readonly string $toolName,
        public readonly bool $success,
        public readonly ?int $durationMs = null,
    ) {}
}
