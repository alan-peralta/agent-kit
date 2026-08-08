<?php

namespace Peralta\AgentKit\DTOs;

class AgentResponse
{
    public function __construct(
        public readonly Message $message,           // mensagem do assistant
        public readonly string $stopReason,         // 'stop' | 'tool_use' | 'length' | 'error'
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly array $raw = [],            // resposta crua do provider (debug)
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->stopReason === 'tool_use' && !empty($this->message->toolCalls);
    }

    public function text(): ?string
    {
        return $this->message->content;
    }

    public function toolCalls(): array
    {
        return $this->message->toolCalls;
    }
}
