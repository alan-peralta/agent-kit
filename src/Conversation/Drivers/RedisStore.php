<?php

namespace Peralta\AgentKit\Conversation\Drivers;

use Illuminate\Support\Facades\Redis;
use Peralta\AgentKit\Conversation\Contracts\ConversationStore;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\DTOs\ToolCall;

class RedisStore implements ConversationStore
{
    public function __construct(
        protected string $connection = 'default',
        protected string $prefix = 'agent:conv:',
        protected int $ttl = 2592000, // 30 dias
    ) {}

    public function load(string $conversationId): array
    {
        $items = Redis::connection($this->connection)
            ->lrange($this->key($conversationId), 0, -1);

        return array_map(fn($json) => $this->deserialize($json), $items);
    }

    public function append(string $conversationId, Message $message): void
    {
        $key = $this->key($conversationId);
        $redis = Redis::connection($this->connection);
        $redis->rpush($key, $this->serialize($message));
        $redis->expire($key, $this->ttl);
    }

    public function replace(string $conversationId, array $messages): void
    {
        $key = $this->key($conversationId);
        $redis = Redis::connection($this->connection);

        $redis->del($key);
        foreach ($messages as $msg) {
            $redis->rpush($key, $this->serialize($msg));
        }
        $redis->expire($key, $this->ttl);
    }

    public function clear(string $conversationId): void
    {
        Redis::connection($this->connection)->del($this->key($conversationId));
    }

    protected function key(string $id): string
    {
        return $this->prefix . $id;
    }

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
}
