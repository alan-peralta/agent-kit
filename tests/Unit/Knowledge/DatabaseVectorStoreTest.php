<?php

namespace Peralta\AgentKit\Tests\Unit\Knowledge;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Peralta\AgentKit\Exceptions\KnowledgeStoreException;
use Peralta\AgentKit\Knowledge\KnowledgeChunk;
use Peralta\AgentKit\Knowledge\Stores\DatabaseVectorStore;
use Peralta\AgentKit\Tests\PackageMigrations;
use Peralta\AgentKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('database')]
class DatabaseVectorStoreTest extends TestCase
{
    protected function defineDatabaseMigrations()
    {
        foreach (PackageMigrations::in('database/knowledge/database') as $migration) {
            $migration->up();
        }
    }

    public function test_search_returns_the_closest_chunks_first_with_their_data(): void
    {
        $store = new DatabaseVectorStore();
        $store->insertBatch([
            $this->chunk([0.0, 1.0, 0.0], 'far', source: 'a.pdf'),
            $this->chunk([1.0, 0.2, 0.0], 'near', source: 'b.pdf', metadata: ['page' => 3]),
            $this->chunk([1.0, 0.0, 0.0], 'exact', source: 'c.pdf'),
        ]);

        $results = $store->search([1.0, 0.0, 0.0], 'tenant', 'faq', 5);

        $this->assertSame(['exact', 'near', 'far'], $this->contents($results));
        $this->assertEqualsWithDelta(1.0, $results[0]->relevance, 1e-6);
        $this->assertSame('b.pdf', $results[1]->source);
        $this->assertSame(['page' => 3], $results[1]->metadata);
        $this->assertSame('tenant', $results[1]->tenantId);
        $this->assertSame('faq', $results[1]->collection);
        $this->assertIsInt($results[1]->id);
        $this->assertSame([], $results[1]->embedding);
    }

    public function test_relevance_is_cosine_similarity_even_for_unnormalised_vectors(): void
    {
        $store = new DatabaseVectorStore();
        $store->insert($this->chunk([3.0, 4.0]));

        $results = $store->search([8.0, 6.0], 'tenant');

        $this->assertEqualsWithDelta(0.96, $results[0]->relevance, 1e-6);
    }

    public function test_search_is_scoped_to_the_tenant_and_optionally_to_the_collection(): void
    {
        $store = new DatabaseVectorStore();
        $store->insertBatch([
            $this->chunk([1.0, 0.0], 'faq of tenant'),
            $this->chunk([1.0, 0.0], 'manual of tenant', collection: 'manual'),
            $this->chunk([1.0, 0.0], 'faq of other', tenant: 'other'),
        ]);

        $this->assertSame(['faq of tenant'], $this->contents($store->search([1.0, 0.0], 'tenant', 'faq')));
        $this->assertSame(['faq of tenant', 'manual of tenant'], $this->contents($store->search([1.0, 0.0], 'tenant')));
        $this->assertSame([], $store->search([1.0, 0.0], 'nobody'));
    }

    public function test_min_relevance_filters_and_limit_truncates(): void
    {
        $store = new DatabaseVectorStore();
        $store->insertBatch([
            $this->chunk([1.0, 0.0], 'a'),
            $this->chunk([1.0, 0.5], 'b'),
            $this->chunk([1.0, 1.0], 'c'),
            $this->chunk([0.0, 1.0], 'd'),
        ]);

        $this->assertSame(['a', 'b'], $this->contents($store->search([1.0, 0.0], 'tenant', limit: 2)));
        $this->assertSame(['a', 'b', 'c'], $this->contents($store->search([1.0, 0.0], 'tenant', minRelevance: 0.7)));
        $this->assertSame([], $store->search([1.0, 0.0], 'tenant', limit: 0));
    }

    public function test_equal_relevance_keeps_insertion_order(): void
    {
        $store = new DatabaseVectorStore();
        $store->insertBatch([
            $this->chunk([0.0, 1.0], 'first'),
            $this->chunk([0.0, 2.0], 'second'),
        ]);

        $this->assertSame(['first', 'second'], $this->contents($store->search([0.0, 1.0], 'tenant')));
    }

    public function test_search_rejects_a_query_with_different_dimensions(): void
    {
        $store = new DatabaseVectorStore();
        $store->insert($this->chunk([1.0, 0.0, 0.0]));

        $this->expectException(KnowledgeStoreException::class);
        $this->expectExceptionMessage('Re-index');

        $store->search([1.0, 0.0], 'tenant');
    }

    public function test_search_rejects_an_empty_query(): void
    {
        $this->expectException(KnowledgeStoreException::class);

        (new DatabaseVectorStore())->search([], 'tenant');
    }

    public function test_insert_rejects_empty_or_mixed_dimension_embeddings_and_writes_nothing(): void
    {
        $store = new DatabaseVectorStore();

        foreach ([[$this->chunk([])], [$this->chunk([1.0, 0.0]), $this->chunk([1.0, 0.0, 0.0])]] as $batch) {
            try {
                $store->insertBatch($batch);
                $this->fail('Expected a KnowledgeStoreException.');
            } catch (KnowledgeStoreException) {
            }
        }

        $this->assertSame(0, DB::table('knowledge_chunks')->count());
    }

    public function test_non_finite_embedding_values_are_rejected(): void
    {
        $store = new DatabaseVectorStore();

        foreach ([NAN, INF, -INF] as $value) {
            try {
                $store->insert($this->chunk([$value, 0.0]));
                $this->fail('Expected a KnowledgeStoreException on insert.');
            } catch (KnowledgeStoreException) {
            }
        }
        $this->assertSame(0, DB::table('knowledge_chunks')->count());

        $store->insert($this->chunk([1.0, 0.0]));
        $this->expectException(KnowledgeStoreException::class);
        $this->expectExceptionMessage('non-finite');

        $store->search([NAN, 0.0], 'tenant');
    }

    public function test_zero_vectors_have_zero_relevance(): void
    {
        $store = new DatabaseVectorStore();
        $store->insertBatch([
            $this->chunk([0.0, 0.0], 'zero'),
            $this->chunk([1.0, 0.0], 'unit'),
        ]);

        $this->assertSame(['unit' => 1.0, 'zero' => 0.0], $this->relevanceByContent($store->search([1.0, 0.0], 'tenant')));
        $this->assertSame(['zero' => 0.0, 'unit' => 0.0], $this->relevanceByContent($store->search([0.0, 0.0], 'tenant')));
    }

    public function test_search_pages_through_every_row_of_the_tenant(): void
    {
        $store = new DatabaseVectorStore(scanPageSize: 2);
        $store->insertBatch([
            $this->chunk([0.0, 1.0], 'r1'),
            $this->chunk([0.2, 1.0], 'r2'),
            $this->chunk([0.4, 1.0], 'r3'),
            $this->chunk([0.6, 1.0], 'r4'),
            $this->chunk([1.0, 0.0], 'r5'),
        ]);

        DB::enableQueryLog();
        $results = $store->search([1.0, 0.0], 'tenant', limit: 1);
        $scans = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'embedding'));

        $this->assertSame(['r5'], $this->contents($results));
        $this->assertGreaterThan(1, count($scans));
    }

    public function test_a_failed_batch_is_rolled_back(): void
    {
        $store = new DatabaseVectorStore();
        $chunks = array_map(fn (int $i): KnowledgeChunk => $this->chunk([1.0, (float) $i], "chunk {$i}"), range(1, 150));

        $inserts = 0;
        DB::listen(function (QueryExecuted $query) use (&$inserts): void {
            if (str_starts_with(strtolower($query->sql), 'insert') && ++$inserts === 2) {
                throw new RuntimeException('second insert failed');
            }
        });

        try {
            $store->insertBatch($chunks);
            $this->fail('Expected the second insert to fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('second insert failed', $e->getMessage());
        }

        $this->assertSame(0, DB::table('knowledge_chunks')->count());
    }

    public function test_delete_by_source_spans_collections_and_delete_by_tenant_clears_the_tenant(): void
    {
        $store = new DatabaseVectorStore();
        $store->insertBatch([
            $this->chunk([1.0, 0.0], source: 'manual.pdf'),
            $this->chunk([1.0, 0.0], collection: 'manual', source: 'manual.pdf'),
            $this->chunk([1.0, 0.0], source: 'faq.pdf'),
            $this->chunk([1.0, 0.0], tenant: 'other', source: 'manual.pdf'),
        ]);

        $this->assertSame(2, $store->deleteBySource('tenant', 'manual.pdf'));
        $this->assertSame(1, $store->deleteByTenant('tenant'));
        $this->assertSame(1, DB::table('knowledge_chunks')->count());
    }

    public function test_unicode_content_and_metadata_round_trip(): void
    {
        $store = new DatabaseVectorStore();
        $metadata = ['título' => 'Seção 3 — prazos', 'tags' => ['frete', 'devolução']];
        $store->insert($this->chunk([1.0], 'Política de devolução 🚚', metadata: $metadata));

        [$result] = $store->search([1.0], 'tenant');

        $this->assertSame('Política de devolução 🚚', $result->content);
        // assertEquals: MySQL JSON columns may reorder object keys.
        $this->assertEquals($metadata, $result->metadata);
    }

    public function test_embeddings_are_stored_as_base64_of_normalised_float32(): void
    {
        (new DatabaseVectorStore())->insert($this->chunk([3.0, 4.0]));

        $this->assertSame(base64_encode(pack('g*', 0.6, 0.8)), DB::table('knowledge_chunks')->value('embedding'));
    }

    public function test_an_empty_connection_name_uses_the_default_connection(): void
    {
        (new DatabaseVectorStore(connection: ''))->insert($this->chunk([1.0]));

        $this->assertSame(1, DB::table('knowledge_chunks')->count());
    }

    private function chunk(
        array $embedding,
        string $content = 'content',
        string $tenant = 'tenant',
        string $collection = 'faq',
        string $source = 'source',
        array $metadata = [],
    ): KnowledgeChunk {
        return new KnowledgeChunk($tenant, $collection, $source, $content, $metadata, $embedding);
    }

    /**
     * @param  KnowledgeChunk[]  $chunks
     * @return list<string>
     */
    private function contents(array $chunks): array
    {
        return array_map(fn (KnowledgeChunk $chunk): string => $chunk->content, $chunks);
    }

    /**
     * @param  KnowledgeChunk[]  $chunks
     * @return array<string, float>
     */
    private function relevanceByContent(array $chunks): array
    {
        return array_combine($this->contents($chunks), array_map(fn (KnowledgeChunk $chunk) => $chunk->relevance, $chunks));
    }
}
