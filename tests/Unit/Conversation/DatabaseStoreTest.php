<?php

namespace Peralta\AgentKit\Tests\Unit\Conversation;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Peralta\AgentKit\Conversation\Drivers\DatabaseStore;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\DTOs\ToolCall;
use Peralta\AgentKit\Tests\TestCase;

class DatabaseStoreTest extends TestCase
{
    protected function defineDatabaseMigrations()
    {
        Schema::create('agent_messages', function (Blueprint $t) {
            $t->id();
            $t->string('conversation_id', 100)->index();
            $t->string('role', 20);
            $t->longText('content')->nullable();
            $t->json('tool_calls')->nullable();
            $t->string('tool_call_id')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->index(['conversation_id', 'id']);
        });
    }

    public function test_load_of_unknown_conversation_returns_empty_array()
    {
        $store = new DatabaseStore();

        $this->assertSame([], $store->load('unknown'));
    }

    public function test_append_then_load_round_trips_a_plain_message()
    {
        $store = new DatabaseStore();
        $store->append('conv-1', Message::user('olá mundo'));

        $loaded = $store->load('conv-1');

        $this->assertCount(1, $loaded);
        $this->assertSame('user', $loaded[0]->role);
        $this->assertSame('olá mundo', $loaded[0]->content);
    }

    public function test_append_then_load_round_trips_tool_calls_and_metadata()
    {
        $store = new DatabaseStore();
        $msg = Message::assistant('usando tool', [new ToolCall('call-1', 'search', ['q' => 'php'])]);

        $store->append('conv-1', $msg);
        $loaded = $store->load('conv-1');

        $this->assertCount(1, $loaded[0]->toolCalls);
        $this->assertSame('call-1', $loaded[0]->toolCalls[0]->id);
        $this->assertSame('search', $loaded[0]->toolCalls[0]->name);
        $this->assertSame(['q' => 'php'], $loaded[0]->toolCalls[0]->arguments);
    }

    public function test_load_preserves_insertion_order()
    {
        $store = new DatabaseStore();
        $store->append('conv-1', Message::user('primeira'));
        $store->append('conv-1', Message::assistant('segunda'));

        $loaded = $store->load('conv-1');

        $this->assertSame('primeira', $loaded[0]->content);
        $this->assertSame('segunda', $loaded[1]->content);
    }

    public function test_replace_deletes_and_reinserts_for_the_given_conversation_only()
    {
        $store = new DatabaseStore();
        $store->append('conv-1', Message::user('antiga'));
        $store->append('conv-2', Message::user('outra conversa'));

        $store->replace('conv-1', [Message::user('nova')]);

        $conv1 = $store->load('conv-1');
        $this->assertCount(1, $conv1);
        $this->assertSame('nova', $conv1[0]->content);

        $conv2 = $store->load('conv-2');
        $this->assertCount(1, $conv2);
        $this->assertSame('outra conversa', $conv2[0]->content);
    }

    public function test_clear_deletes_only_the_given_conversation()
    {
        $store = new DatabaseStore();
        $store->append('conv-1', Message::user('a apagar'));
        $store->append('conv-2', Message::user('a manter'));

        $store->clear('conv-1');

        $this->assertSame([], $store->load('conv-1'));
        $this->assertCount(1, $store->load('conv-2'));
    }
}
