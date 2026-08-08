<?php

namespace Peralta\AgentKit\Tests\Unit\Conversation;

use Peralta\AgentKit\Conversation\Drivers\ArrayStore;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\Tests\TestCase;

class ArrayStoreTest extends TestCase
{
    public function test_load_of_unknown_conversation_returns_empty_array()
    {
        $store = new ArrayStore();

        $this->assertSame([], $store->load('unknown'));
    }

    public function test_append_then_load_round_trips_a_message()
    {
        $store = new ArrayStore();
        $msg = Message::user('olá');

        $store->append('conv-1', $msg);

        $this->assertSame([$msg], $store->load('conv-1'));
    }

    public function test_append_accumulates_multiple_messages_in_order()
    {
        $store = new ArrayStore();
        $first = Message::user('primeira');
        $second = Message::assistant('segunda');

        $store->append('conv-1', $first);
        $store->append('conv-1', $second);

        $this->assertSame([$first, $second], $store->load('conv-1'));
    }

    public function test_replace_overwrites_existing_messages()
    {
        $store = new ArrayStore();
        $store->append('conv-1', Message::user('original'));

        $replacement = [Message::user('novo')];
        $store->replace('conv-1', $replacement);

        $this->assertSame($replacement, $store->load('conv-1'));
    }

    public function test_clear_empties_the_conversation()
    {
        $store = new ArrayStore();
        $store->append('conv-1', Message::user('olá'));

        $store->clear('conv-1');

        $this->assertSame([], $store->load('conv-1'));
    }

    public function test_different_conversation_ids_are_isolated()
    {
        $store = new ArrayStore();
        $store->append('conv-1', Message::user('conversa 1'));
        $store->append('conv-2', Message::user('conversa 2'));

        $this->assertCount(1, $store->load('conv-1'));
        $this->assertCount(1, $store->load('conv-2'));
        $this->assertNotSame($store->load('conv-1'), $store->load('conv-2'));
    }
}
