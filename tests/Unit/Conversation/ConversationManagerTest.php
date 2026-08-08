<?php

namespace Peralta\AgentKit\Tests\Unit\Conversation;

use Mockery;
use Peralta\AgentKit\Conversation\Contracts\ConversationStore;
use Peralta\AgentKit\Conversation\ConversationManager;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\Tests\TestCase;

class ConversationManagerTest extends TestCase
{
    public function test_load_delegates_to_store()
    {
        $store = Mockery::mock(ConversationStore::class);
        $messages = [Message::user('oi')];
        $store->shouldReceive('load')->once()->with('conv-1')->andReturn($messages);

        $manager = new ConversationManager($store, maxMessages: 50);

        $this->assertSame($messages, $manager->load('conv-1'));
    }

    public function test_clear_delegates_to_store()
    {
        $store = Mockery::mock(ConversationStore::class);
        $store->shouldReceive('clear')->once()->with('conv-1');

        $manager = new ConversationManager($store, maxMessages: 50);
        $manager->clear('conv-1');
    }

    public function test_append_calls_store_append_then_checks_truncation()
    {
        $store = Mockery::mock(ConversationStore::class);
        $msg = Message::user('oi');

        $store->shouldReceive('append')->once()->with('conv-1', $msg);
        $store->shouldReceive('load')->once()->with('conv-1')->andReturn([$msg]);

        $manager = new ConversationManager($store, maxMessages: 50);
        $manager->append('conv-1', $msg);
    }

    public function test_append_does_not_truncate_when_under_the_limit()
    {
        $store = Mockery::mock(ConversationStore::class);
        $messages = array_fill(0, 5, Message::user('oi'));

        $store->shouldReceive('append')->once();
        $store->shouldReceive('load')->once()->andReturn($messages);
        $store->shouldNotReceive('replace');

        $manager = new ConversationManager($store, maxMessages: 50);
        $manager->append('conv-1', Message::user('oi'));
    }

    public function test_append_truncates_to_max_messages_when_over_the_limit()
    {
        $store = Mockery::mock(ConversationStore::class);
        $messages = array_map(fn($i) => Message::user("msg-{$i}"), range(1, 5));

        $store->shouldReceive('append')->once();
        $store->shouldReceive('load')->once()->andReturn($messages);
        $store->shouldReceive('replace')->once()->with('conv-1', Mockery::on(
            fn($kept) => count($kept) === 3 && $kept[0]->content === 'msg-3'
        ));

        $manager = new ConversationManager($store, maxMessages: 3);
        $manager->append('conv-1', Message::user('irrelevant, load() is mocked'));
    }

    public function test_truncation_drops_leading_orphan_tool_message()
    {
        $store = Mockery::mock(ConversationStore::class);
        // After slicing to the last 3, the first would be a 'tool' message — must be dropped too.
        $messages = [
            Message::user('msg-1'),
            Message::assistant('msg-2'),
            Message::tool('call-id', 'resultado'), // role=tool, would become the leading kept message
            Message::user('msg-4'),
        ];

        $store->shouldReceive('append')->once();
        $store->shouldReceive('load')->once()->andReturn($messages);
        $store->shouldReceive('replace')->once()->with('conv-1', Mockery::on(
            fn($kept) => count($kept) === 1 && $kept[0]->content === 'msg-4'
        ));

        $manager = new ConversationManager($store, maxMessages: 2);
        $manager->append('conv-1', Message::user('irrelevant, load() is mocked'));
    }
}
