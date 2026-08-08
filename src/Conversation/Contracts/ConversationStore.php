<?php

namespace Peralta\AgentKit\Conversation\Contracts;

use Peralta\AgentKit\DTOs\Message;

interface ConversationStore
{
    /**
     * Carrega mensagens de uma conversa.
     *
     * @return Message[]
     */
    public function load(string $conversationId): array;

    /**
     * Adiciona uma mensagem à conversa.
     */
    public function append(string $conversationId, Message $message): void;

    /**
     * Substitui todo o histórico (útil pra truncamento).
     *
     * @param  Message[]  $messages
     */
    public function replace(string $conversationId, array $messages): void;

    /**
     * Apaga a conversa.
     */
    public function clear(string $conversationId): void;
}
