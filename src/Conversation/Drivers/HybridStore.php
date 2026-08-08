<?php

namespace Peralta\AgentKit\Conversation\Drivers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Peralta\AgentKit\Conversation\Contracts\ConversationStore;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\DTOs\ToolCall;

/**
 * Hybrid Storage: Redis (performance) + Database (audit trail)
 *
 * Usa Redis como cache quente (rápido) mas sincroniza com Database
 * para manter histórico permanente e auditoria.
 *
 * Fluxo:
 * 1. Lê do Redis (rápido)
 * 2. Se não encontra, lê do Database (fallback)
 * 3. Escreve em ambos simultaneamente (sincronização)
 */
class HybridStore implements ConversationStore
{
    public function __construct(
        protected string $redisConnection = 'default',
        protected string $redisPrefix = 'agent:conv:',
        protected int $redisTtl = 2592000, // 30 dias
        protected ?string $dbConnection = null,
        protected string $messagesTable = 'agent_messages',
    ) {}

    /**
     * Carrega histórico: tenta Redis primeiro, depois Database
     */
    public function load(string $conversationId): array
    {
        // Tenta carregar do Redis (cache quente)
        $cached = $this->loadFromRedis($conversationId);
        if (!empty($cached)) {
            return $cached;
        }

        // Se não achou no Redis, carrega do Database (fallback)
        return $this->loadFromDatabase($conversationId);
    }

    /**
     * Salva em AMBOS: Redis (rápido) + Database (auditoria)
     */
    public function append(string $conversationId, Message $message): void
    {
        // Salva no Redis (rápido)
        $this->appendToRedis($conversationId, $message);

        // Salva no Database (auditoria/histórico)
        $this->appendToDatabase($conversationId, $message);
    }

    /**
     * Replace: atualiza em ambos
     */
    public function replace(string $conversationId, array $messages): void
    {
        // Limpa e reescreve no Redis
        $this->clearRedis($conversationId);
        foreach ($messages as $msg) {
            $this->appendToRedis($conversationId, $msg);
        }

        // Limpa e reescreve no Database (transação)
        $this->db()->transaction(function () use ($conversationId, $messages) {
            $this->clearDatabase($conversationId);
            foreach ($messages as $msg) {
                $this->appendToDatabase($conversationId, $msg);
            }
        });
    }

    /**
     * Clear: deleta de ambos
     */
    public function clear(string $conversationId): void
    {
        $this->clearRedis($conversationId);
        $this->clearDatabase($conversationId);
    }

    // ========== REDIS OPERATIONS ==========

    protected function loadFromRedis(string $conversationId): array
    {
        $items = Redis::connection($this->redisConnection)
            ->lrange($this->redisKey($conversationId), 0, -1);

        if (empty($items)) {
            return [];
        }

        return array_map(fn($json) => $this->deserialize($json), $items);
    }

    protected function appendToRedis(string $conversationId, Message $message): void
    {
        $key = $this->redisKey($conversationId);
        $redis = Redis::connection($this->redisConnection);
        $redis->rpush($key, $this->serialize($message));
        $redis->expire($key, $this->redisTtl);
    }

    protected function clearRedis(string $conversationId): void
    {
        Redis::connection($this->redisConnection)
            ->del($this->redisKey($conversationId));
    }

    protected function redisKey(string $id): string
    {
        return $this->redisPrefix . $id;
    }

    // ========== DATABASE OPERATIONS ==========

    protected function loadFromDatabase(string $conversationId): array
    {
        $rows = $this->db()
            ->table($this->messagesTable)
            ->where('conversation_id', $conversationId)
            ->orderBy('id', 'asc')
            ->get();

        return $rows->map(fn($row) => $this->rowToMessage($row))->all();
    }

    protected function appendToDatabase(string $conversationId, Message $message): void
    {
        $this->db()->table($this->messagesTable)->insert([
            'conversation_id' => $conversationId,
            'role' => $message->role,
            'content' => $message->content,
            'tool_calls' => !empty($message->toolCalls)
                ? json_encode(array_map(fn($tc) => $tc->toArray(), $message->toolCalls))
                : null,
            'tool_call_id' => $message->toolCallId,
            'metadata' => !empty($message->metadata) ? json_encode($message->metadata) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function clearDatabase(string $conversationId): void
    {
        $this->db()
            ->table($this->messagesTable)
            ->where('conversation_id', $conversationId)
            ->delete();
    }

    protected function rowToMessage(object $row): Message
    {
        $toolCalls = [];
        if ($row->tool_calls) {
            foreach (json_decode($row->tool_calls, true) ?? [] as $tc) {
                $toolCalls[] = new ToolCall(
                    id: $tc['id'],
                    name: $tc['name'],
                    arguments: $tc['arguments'] ?? [],
                );
            }
        }

        return new Message(
            role: $row->role,
            content: $row->content,
            toolCalls: $toolCalls,
            toolCallId: $row->tool_call_id,
            metadata: $row->metadata ? json_decode($row->metadata, true) : [],
        );
    }

    // ========== SERIALIZATION ==========

    protected function serialize(Message $msg): string
    {
        return json_encode([
            'role' => $msg->role,
            'content' => $msg->content,
            'tool_calls' => array_map(fn($tc) => $tc->toArray(), $msg->toolCalls),
            'tool_call_id' => $msg->toolCallId,
            'metadata' => $msg->metadata,
        ], JSON_UNESCAPED_UNICODE);
    }

    protected function deserialize(string $json): Message
    {
        $data = json_decode($json, true);
        $toolCalls = array_map(
            fn($tc) => new ToolCall($tc['id'], $tc['name'], $tc['arguments'] ?? []),
            $data['tool_calls'] ?? []
        );

        return new Message(
            role: $data['role'],
            content: $data['content'] ?? null,
            toolCalls: $toolCalls,
            toolCallId: $data['tool_call_id'] ?? null,
            metadata: $data['metadata'] ?? [],
        );
    }

    protected function db()
    {
        return $this->dbConnection ? DB::connection($this->dbConnection) : DB::connection();
    }
}
