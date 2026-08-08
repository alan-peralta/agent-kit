<?php

namespace Peralta\AgentKit\Conversation\Drivers;

use Peralta\AgentKit\Conversation\Contracts\ConversationStore;
use Peralta\AgentKit\DTOs\Message;

class ArrayStore implements ConversationStore
{
    /** @var array<string, Message[]> */
    private array $conversations = [];

    public function load(string $conversationId): array
    {
        return $this->conversations[$conversationId] ?? [];
    }

    public function append(string $conversationId, Message $message): void
    {
        $this->conversations[$conversationId][] = $message;
    }

    public function replace(string $conversationId, array $messages): void
    {
        $this->conversations[$conversationId] = $messages;
    }

    public function clear(string $conversationId): void
    {
        unset($this->conversations[$conversationId]);
    }
}
