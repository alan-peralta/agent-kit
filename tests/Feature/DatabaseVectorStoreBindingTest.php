<?php

namespace Peralta\AgentKit\Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
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
    public function test_it_resolves_the_database_store_on_the_configured_connection_and_table(): void
    {
        config()->set('database.connections.knowledge', config('database.connections.testing'));
        config()->set('agent-kit.knowledge.store', 'database');
        config()->set('agent-kit.knowledge.stores.database', [
            'driver' => 'database',
            'connection' => 'knowledge',
            'table' => 'kb_custom',
        ]);
        foreach (PackageMigrations::in('database/knowledge/database') as $migration) {
            $migration->up();
        }
        $connections = [];
        DB::listen(function (QueryExecuted $query) use (&$connections): void {
            if (str_contains($query->sql, 'kb_custom')) {
                $connections[] = $query->connectionName;
            }
        });

        $store = $this->app->make(KnowledgeStore::class);
        $store->insert(new KnowledgeChunk('tenant', 'faq', 'source', 'content', [], [1.0, 0.0]));

        $this->assertInstanceOf(DatabaseVectorStore::class, $store);
        $this->assertSame(['knowledge'], array_values(array_unique($connections)));
        $this->assertSame(1, DB::connection('knowledge')->table('kb_custom')->count());
    }

    public function test_it_falls_back_to_the_defaults_when_a_published_config_lacks_the_database_store(): void
    {
        config()->set('agent-kit.knowledge.store', 'database');
        config()->set('agent-kit.knowledge.stores', [
            'pgvector' => ['driver' => 'pgvector', 'connection' => 'pgsql', 'table' => 'knowledge_chunks'],
        ]);
        foreach (PackageMigrations::in('database/knowledge/database') as $migration) {
            $migration->up();
        }

        $store = $this->app->make(KnowledgeStore::class);
        $store->insert(new KnowledgeChunk('tenant', 'faq', 'source', 'content', [], [1.0, 0.0]));

        $this->assertInstanceOf(DatabaseVectorStore::class, $store);
        $this->assertSame(1, DB::table('knowledge_chunks')->count());
    }
}
