<?php

namespace Peralta\AgentKit\Tests\Feature\Database;

use Illuminate\Support\Facades\Schema;
use Peralta\AgentKit\Tests\PackageMigrations;
use Peralta\AgentKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
class CoreMigrationsTest extends TestCase
{
    public function test_core_migrations_create_and_drop_their_tables(): void
    {
        $migrations = PackageMigrations::in('database/migrations');
        $this->assertCount(2, $migrations);

        foreach ($migrations as $migration) {
            $migration->up();
        }

        $this->assertTrue(Schema::hasColumns('agent_messages', [
            'id', 'conversation_id', 'role', 'content', 'tool_calls', 'tool_call_id', 'metadata', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('agent_kit_metrics', [
            'id', 'type', 'conversation_id', 'provider', 'model', 'duration_ms', 'payload', 'created_at',
        ]));

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }

        $this->assertFalse(Schema::hasTable('agent_messages'));
        $this->assertFalse(Schema::hasTable('agent_kit_metrics'));
    }
}
