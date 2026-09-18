<?php

namespace Peralta\AgentKit\Tests\Feature\Database;

use Illuminate\Support\Facades\Schema;
use Peralta\AgentKit\Tests\PackageMigrations;
use Peralta\AgentKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
class DatabaseStoreMigrationTest extends TestCase
{
    public function test_migration_creates_and_drops_the_configured_table(): void
    {
        config()->set('agent-kit.knowledge.stores.database.table', 'kb_chunks');
        $migrations = PackageMigrations::in('database/knowledge/database');
        $this->assertCount(1, $migrations);
        [$migration] = $migrations;

        $migration->up();

        $this->assertTrue(Schema::hasColumns('kb_chunks', [
            'id', 'tenant_id', 'collection', 'source', 'content', 'metadata', 'embedding', 'created_at', 'updated_at',
        ]));

        $migration->down();

        $this->assertFalse(Schema::hasTable('kb_chunks'));
    }

    public function test_an_empty_connection_name_means_the_default_connection(): void
    {
        config()->set('agent-kit.knowledge.stores.database.connection', '');
        [$migration] = PackageMigrations::in('database/knowledge/database');

        $migration->up();

        $this->assertTrue(Schema::hasTable('knowledge_chunks'));
    }

    public function test_the_store_is_configured_with_the_default_table(): void
    {
        $config = config('agent-kit.knowledge.stores.database');

        $this->assertSame('database', $config['driver']);
        $this->assertSame('knowledge_chunks', $config['table']);
        $this->assertArrayHasKey('connection', $config);
    }
}
