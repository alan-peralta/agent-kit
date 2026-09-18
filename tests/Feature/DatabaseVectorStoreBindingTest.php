<?php

namespace Peralta\AgentKit\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Peralta\AgentKit\Knowledge\Contracts\KnowledgeStore;
use Peralta\AgentKit\Knowledge\KnowledgeChunk;
use Peralta\AgentKit\Knowledge\Stores\DatabaseVectorStore;
use Peralta\AgentKit\Tests\PackageMigrations;
use Peralta\AgentKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
class DatabaseVectorStoreBindingTest extends TestCase
{
    public function test_it_resolves_the_database_store_with_the_configured_connection_and_table(): void
    {
        config()->set('agent-kit.knowledge.store', 'database');
        config()->set('agent-kit.knowledge.stores.database', [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'kb_custom',
        ]);
        foreach (PackageMigrations::in('database/knowledge/database') as $migration) {
            $migration->up();
        }

        $store = $this->app->make(KnowledgeStore::class);
        $store->insert(new KnowledgeChunk('tenant', 'faq', 'source', 'content', [], [1.0, 0.0]));

        $this->assertInstanceOf(DatabaseVectorStore::class, $store);
        $this->assertSame(1, DB::connection('testing')->table('kb_custom')->count());
    }
}
