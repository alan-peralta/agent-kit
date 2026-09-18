<?php

namespace Peralta\AgentKit\Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Peralta\AgentKit\Knowledge\KnowledgeChunk;
use Peralta\AgentKit\Knowledge\Stores\PgvectorStore;
use Peralta\AgentKit\Tests\PackageMigrations;
use Peralta\AgentKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
class PgvectorStoreTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('agent-kit.knowledge.stores.pgvector.connection', 'testing');
        $app['config']->set('agent-kit.knowledge.embedders.openai.dimensions', 3);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pgvector tests need AGENT_KIT_TEST_DB_CONNECTION=pgsql.');
        }

        if (DB::selectOne("select 1 as available from pg_available_extensions where name = 'vector'") === null) {
            $this->markTestSkipped('pgvector tests need the vector extension, e.g. the pgvector/pgvector image.');
        }
    }

    public function test_pgvector_migration_and_store_rank_chunks_by_cosine_similarity(): void
    {
        $migrations = PackageMigrations::in('database/knowledge/pgvector');
        $this->assertCount(1, $migrations);
        [$migration] = $migrations;

        $migration->up();

        $store = new PgvectorStore(connection: 'testing', table: 'knowledge_chunks');
        $store->insertBatch([
            new KnowledgeChunk('tenant', 'faq', 'a.pdf', 'far', [], [0.0, 1.0, 0.0]),
            new KnowledgeChunk('tenant', 'faq', 'b.pdf', 'near', ['page' => 3], [1.0, 0.1, 0.0]),
            new KnowledgeChunk('other', 'faq', 'c.pdf', 'other tenant', [], [1.0, 0.0, 0.0]),
        ]);

        $results = $store->search([1.0, 0.0, 0.0], 'tenant', 'faq', 5, 0.0);

        $this->assertSame(['near', 'far'], array_map(fn (KnowledgeChunk $chunk) => $chunk->content, $results));
        $this->assertSame(['page' => 3], $results[0]->metadata);
        $this->assertGreaterThan($results[1]->relevance, $results[0]->relevance);

        $migration->down();

        $this->assertFalse(Schema::hasTable('knowledge_chunks'));
    }
}
