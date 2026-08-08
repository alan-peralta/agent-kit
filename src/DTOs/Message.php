<?php

namespace Peralta\AgentKit\DTOs;

class Message
{
    public function __construct(
        public readonly string $role,           // 'user' | 'assistant' | 'system' | 'tool'
        public readonly ?string $content = null, // texto da mensagem
        public readonly array $toolCalls = [],   // ToolCall[] — quando assistant pediu tools
        public readonly ?string $toolCallId = null, // quando role=tool, qual call está respondendo
        public readonly array $metadata = [],
    ) {}

    public static function user(string $content): self
    {
        return new self(role: 'user', content: $content);
    }

    public static function assistant(?string $content = null, array $toolCalls = []): self
    {
        return new self(role: 'assistant', content: $content, toolCalls: $toolCalls);
    }

    public static function system(string $content): self
    {
        return new self(role: 'system', content: $content);
    }

    public static function tool(string $toolCallId, mixed $result): self
    {
        $content = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE);
        return new self(role: 'tool', content: $content, toolCallId: $toolCallId);
    }

    public function toArray(): array
    {
        return array_filter([
            'role' => $this->role,
            'content' => $this->content,
            'tool_calls' => array_map(fn($tc) => $tc->toArray(), $this->toolCalls) ?: null,
            'tool_call_id' => $this->toolCallId,
            'metadata' => $this->metadata ?: null,
        ], fn($v) => $v !== null);
    }
}
