<?php

namespace Peralta\AgentKit\Conversation;

use Peralta\AgentKit\Conversation\Contracts\ConversationStore;
use Peralta\AgentKit\DTOs\Message;

class ConversationManager
{
    public function __construct(
        protected ConversationStore $store,
        protected int $maxMessages = 50,
    ) {}

    public function load(string $conversationId): array
    {
        return $this->store->load($conversationId);
    }

    public function append(string $conversationId, Message $message): void
    {
        $this->store->append($conversationId, $message);
        $this->maybeTruncate($conversationId);
    }

    public function clear(string $conversationId): void
    {
        $this->store->clear($conversationId);
    }

    /**
     * Trunca a conversa pra não estourar contexto/custo.
     * Mantém as N mensagens mais recentes, preservando integridade
     * (não corta entre tool_use e tool_result).
     */
    protected function maybeTruncate(string $conversationId): void
    {
        $messages = $this->store->load($conversationId);
        if (count($messages) <= $this->maxMessages) return;

        $keep = array_slice($messages, -$this->maxMessages);

        // Garante que não começamos com role=tool órfão
        while (!empty($keep) && $keep[0]->role === 'tool') {
            array_shift($keep);
        }

        $this->store->replace($conversationId, $keep);
    }
}
