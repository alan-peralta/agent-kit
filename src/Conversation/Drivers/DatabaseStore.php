<?php

namespace Peralta\AgentKit\Conversation\Drivers;

use Illuminate\Support\Facades\DB;
use Peralta\AgentKit\Conversation\Contracts\ConversationStore;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\DTOs\ToolCall;

class DatabaseStore implements ConversationStore
{
    public function __construct(
        protected ?string $connection = null,
        protected string $messagesTable = 'agent_messages',
    ) {}

    public function load(string $conversationId): array
    {
        $rows = $this->db()
            ->table($this->messagesTable)
            ->where('conversation_id', $conversationId)
            ->orderBy('id', 'asc')
            ->get();

        return $rows->map(fn($row) => $this->rowToMessage($row))->all();
    }

    public function append(string $conversationId, Message $message): void
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

    public function replace(string $conversationId, array $messages): void
    {
        $this->db()->transaction(function () use ($conversationId, $messages) {
            $this->clear($conversationId);
            foreach ($messages as $msg) {
                $this->append($conversationId, $msg);
            }
        });
    }

    public function clear(string $conversationId): void
    {
        $this->db()
            ->table($this->messagesTable)
            ->where('conversation_id', $conversationId)
            ->delete();
    }

    protected function db()
    {
        return $this->connection ? DB::connection($this->connection) : DB::connection();
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
}
