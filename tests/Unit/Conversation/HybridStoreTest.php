<?php

namespace Peralta\AgentKit\Tests\Unit\Conversation;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Peralta\AgentKit\Conversation\Drivers\HybridStore;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\Tests\TestCase;

class HybridStoreTest extends TestCase
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

    public function test_load_reads_from_redis_when_redis_has_data()
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->with('default')->andReturn($connection);

        $serialized = json_encode(['role' => 'user', 'content' => 'do redis', 'tool_calls' => [], 'tool_call_id' => null, 'metadata' => []]);
        $connection->shouldReceive('lrange')->once()->andReturn([$serialized]);

        $store = new HybridStore();
        $loaded = $store->load('conv-1');

        $this->assertCount(1, $loaded);
        $this->assertSame('do redis', $loaded[0]->content);
    }

    public function test_load_falls_back_to_database_when_redis_is_empty()
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('lrange')->once()->andReturn([]);

        $store = new HybridStore();
        // Seed the DB directly to simulate a prior write, bypassing Redis.
        \Illuminate\Support\Facades\DB::table('agent_messages')->insert([
            'conversation_id' => 'conv-1',
            'role' => 'user',
            'content' => 'do banco',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $loaded = $store->load('conv-1');

        $this->assertCount(1, $loaded);
        $this->assertSame('do banco', $loaded[0]->content);
    }

    public function test_append_writes_to_both_redis_and_database()
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('rpush')->once();
        $connection->shouldReceive('expire')->once();

        $store = new HybridStore();
        $store->append('conv-1', Message::user('em ambos'));

        $dbRow = \Illuminate\Support\Facades\DB::table('agent_messages')->where('conversation_id', 'conv-1')->first();
        $this->assertNotNull($dbRow);
        $this->assertSame('em ambos', $dbRow->content);
    }

    public function test_clear_removes_from_both_redis_and_database()
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('rpush')->once();
        $connection->shouldReceive('expire')->once();
        $connection->shouldReceive('del')->once();

        $store = new HybridStore();
        $store->append('conv-1', Message::user('a apagar'));
        $store->clear('conv-1');

        $dbRow = \Illuminate\Support\Facades\DB::table('agent_messages')->where('conversation_id', 'conv-1')->first();
        $this->assertNull($dbRow);
    }
}
