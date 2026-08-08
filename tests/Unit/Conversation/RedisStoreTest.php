<?php

namespace Peralta\AgentKit\Tests\Unit\Conversation;

use Illuminate\Support\Facades\Redis;
use Mockery;
use Peralta\AgentKit\Conversation\Drivers\RedisStore;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\Tests\TestCase;

class RedisStoreTest extends TestCase
{
    public function test_append_pushes_serialized_message_and_sets_expiry()
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->with('default')->andReturn($connection);

        $connection->shouldReceive('rpush')->once()->with(
            'agent:conv:conv-1',
            Mockery::on(fn($json) => json_decode($json, true)['content'] === 'olá'),
        );
        $connection->shouldReceive('expire')->once()->with('agent:conv:conv-1', 2592000);

        $store = new RedisStore();
        $store->append('conv-1', Message::user('olá'));
    }

    public function test_load_calls_lrange_and_deserializes_each_entry()
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->with('default')->andReturn($connection);

        $serialized = json_encode(['role' => 'user', 'content' => 'oi', 'tool_calls' => [], 'tool_call_id' => null, 'metadata' => []]);
        $connection->shouldReceive('lrange')->once()->with('agent:conv:conv-1', 0, -1)->andReturn([$serialized]);

        $store = new RedisStore();
        $loaded = $store->load('conv-1');

        $this->assertCount(1, $loaded);
        $this->assertSame('user', $loaded[0]->role);
        $this->assertSame('oi', $loaded[0]->content);
    }

    public function test_clear_calls_del_with_prefixed_key()
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('del')->once()->with('agent:conv:conv-1');

        $store = new RedisStore();
        $store->clear('conv-1');
    }

    public function test_uses_configured_connection_prefix_and_ttl()
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->with('custom')->andReturn($connection);

        $connection->shouldReceive('rpush')->once()->with('custom:prefix:conv-1', Mockery::type('string'));
        $connection->shouldReceive('expire')->once()->with('custom:prefix:conv-1', 60);

        $store = new RedisStore(connection: 'custom', prefix: 'custom:prefix:', ttl: 60);
        $store->append('conv-1', Message::user('oi'));
    }

    public function test_replace_deletes_then_rpushes_each_message_and_sets_expiry()
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->with('default')->andReturn($connection);

        $connection->shouldReceive('del')->once()->with('agent:conv:conv-1');
        $connection->shouldReceive('rpush')->twice()->with('agent:conv:conv-1', Mockery::type('string'));
        $connection->shouldReceive('expire')->once()->with('agent:conv:conv-1', 2592000);

        $store = new RedisStore();
        $store->replace('conv-1', [Message::user('um'), Message::user('dois')]);
    }
}
