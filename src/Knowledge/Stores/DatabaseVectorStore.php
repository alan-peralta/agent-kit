<?php

namespace Peralta\AgentKit\Knowledge\Stores;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Peralta\AgentKit\Exceptions\KnowledgeStoreException;
use Peralta\AgentKit\Knowledge\Contracts\KnowledgeStore;
use Peralta\AgentKit\Knowledge\KnowledgeChunk;

/**
 * Knowledge store for any database Laravel supports (MySQL, MariaDB, PostgreSQL, SQLite).
 *
 * Embeddings are stored as base64 of the L2-normalised vector packed as little-endian
 * float32 and ranked in PHP with a dot product, so every search reads all embeddings of
 * the tenant (and collection). Suited to a few thousand chunks per tenant and collection;
 * use pgvector or Qdrant beyond that.
 */
class DatabaseVectorStore implements KnowledgeStore
{
    private const INSERT_CHUNK_SIZE = 100;

    public function __construct(
        protected ?string $connection = null,
        protected string $table = 'knowledge_chunks',
        protected int $scanPageSize = 1000,
    ) {
        $this->connection = $connection !== '' ? $connection : null;
    }

    public function insert(KnowledgeChunk $chunk): void
    {
        $this->insertBatch([$chunk]);
    }

    public function insertBatch(array $chunks): void
    {
        if ($chunks === []) {
            return;
        }

        $this->assertConsistentDimensions($chunks);

        $now = now();
        $rows = [];
        foreach ($chunks as $chunk) {
            $rows[] = [
                'tenant_id' => $chunk->tenantId,
                'collection' => $chunk->collection,
                'source' => $chunk->source,
                'content' => $chunk->content,
                'metadata' => json_encode($chunk->metadata, JSON_UNESCAPED_UNICODE),
                'embedding' => base64_encode($this->pack($chunk->embedding)),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // One transaction per batch: a document is indexed completely or not at all.
        $this->db()->transaction(function () use ($rows): void {
            foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $batch) {
                $this->db()->table($this->table)->insert($batch);
            }
        });
    }

    public function search(
        array $embedding,
        string $tenantId,
        ?string $collection = null,
        int $limit = 5,
        float $minRelevance = 0.0,
    ): array {
        if ($limit <= 0) {
            return [];
        }

        if ($embedding === []) {
            throw new KnowledgeStoreException('Cannot search the knowledge base with an empty embedding.');
        }

        // Round-trip through float32 so the query has the stored precision and unpack()'s 1-based keys.
        $query = unpack('g*', $this->pack($embedding));
        $scores = $this->score($query, $tenantId, $collection, $minRelevance);

        return $this->hydrate($this->rank($scores, $limit), $scores);
    }

    public function deleteBySource(string $tenantId, string $source): int
    {
        return $this->db()
            ->table($this->table)
            ->where('tenant_id', $tenantId)
            ->where('source', $source)
            ->delete();
    }

    public function deleteByTenant(string $tenantId): int
    {
        return $this->db()
            ->table($this->table)
            ->where('tenant_id', $tenantId)
            ->delete();
    }

    /**
     * @param  array<int, float>  $query  1-based, as unpack() returns it
     * @return array<int, float> relevance by row id, only rows at or above $minRelevance
     */
    private function score(array $query, string $tenantId, ?string $collection, float $minRelevance): array
    {
        $dimensions = count($query);
        $scores = [];

        $rows = $this->db()
            ->table($this->table)
            ->select(['id', 'embedding'])
            ->where('tenant_id', $tenantId)
            ->when($collection !== null && $collection !== '', fn ($builder) => $builder->where('collection', $collection))
            ->lazyById($this->scanPageSize);

        foreach ($rows as $row) {
            $vector = $this->decode((string) $row->embedding);

            if (count($vector) !== $dimensions) {
                throw new KnowledgeStoreException(sprintf(
                    'Knowledge chunk %d has a %d-dimension embedding but the query has %d dimensions. '
                    . 'Re-index the knowledge base after changing the embedder.',
                    $row->id,
                    count($vector),
                    $dimensions,
                ));
            }

            $relevance = 0.0;
            foreach ($query as $i => $value) {
                $relevance += $value * $vector[$i];
            }
            $relevance = max(-1.0, min(1.0, $relevance));

            if ($relevance >= $minRelevance) {
                $scores[(int) $row->id] = $relevance;
            }
        }

        return $scores;
    }

    /**
     * @param  array<int, float>  $scores
     * @return list<int> the best ids first, ties broken by id
     */
    private function rank(array $scores, int $limit): array
    {
        $ids = array_keys($scores);
        usort($ids, fn (int $a, int $b): int => [$scores[$b], $a] <=> [$scores[$a], $b]);

        return array_slice($ids, 0, $limit);
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, float>  $scores
     * @return KnowledgeChunk[]
     */
    private function hydrate(array $ids, array $scores): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->db()
            ->table($this->table)
            ->select(['id', 'tenant_id', 'collection', 'source', 'content', 'metadata'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->id);

        $chunks = [];
        foreach ($ids as $id) {
            $row = $rows->get($id);

            if ($row === null) {
                continue; // deleted between the scan and this query
            }

            $chunks[] = new KnowledgeChunk(
                tenantId: $row->tenant_id,
                collection: $row->collection,
                source: $row->source,
                content: $row->content,
                metadata: $this->decodeMetadata($row->metadata),
                embedding: [],
                relevance: $scores[$id],
                id: $id,
            );
        }

        return $chunks;
    }

    /** @param  KnowledgeChunk[]  $chunks */
    private function assertConsistentDimensions(array $chunks): void
    {
        $dimensions = null;

        foreach ($chunks as $chunk) {
            $size = count($chunk->embedding);

            if ($size === 0) {
                throw new KnowledgeStoreException("Knowledge chunk from source '{$chunk->source}' has an empty embedding.");
            }

            $dimensions ??= $size;

            if ($size !== $dimensions) {
                throw new KnowledgeStoreException(
                    "All embeddings in a batch must have the same dimensions; got {$dimensions} and {$size}.",
                );
            }
        }
    }

    /** L2-normalises the vector and packs it as little-endian float32. A zero vector stays zero. */
    private function pack(array $vector): string
    {
        $values = array_map('floatval', array_values($vector));
        $norm = sqrt(array_sum(array_map(fn (float $value): float => $value * $value, $values)));

        if ($norm > 0.0) {
            $values = array_map(fn (float $value): float => $value / $norm, $values);
        }

        return pack('g*', ...$values);
    }

    /** @return array<int, float> 1-based, as unpack() returns it */
    private function decode(string $encoded): array
    {
        $binary = base64_decode($encoded, true);

        if ($binary === false || strlen($binary) % 4 !== 0) {
            throw new KnowledgeStoreException('Stored embedding is not base64-encoded float32 data.');
        }

        return $binary === '' ? [] : unpack('g*', $binary);
    }

    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (! is_string($metadata) || $metadata === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->connection);
    }
}
