<?php

namespace Peralta\AgentKit\Knowledge\Stores;

use Illuminate\Support\Facades\DB;
use Peralta\AgentKit\Knowledge\Contracts\KnowledgeStore;
use Peralta\AgentKit\Knowledge\KnowledgeChunk;

class PgvectorStore implements KnowledgeStore
{
    public function __construct(
        protected string $connection = 'pgsql',
        protected string $table = 'knowledge_chunks',
    ) {}

    public function insert(KnowledgeChunk $chunk): void
    {
        $this->db()->table($this->table)->insert($this->chunkToRow($chunk));
    }

    public function insertBatch(array $chunks): void
    {
        if (empty($chunks)) return;

        // Postgres aceita inserts grandes, mas vamos chunkar pra evitar query gigante
        foreach (array_chunk($chunks, 100) as $batch) {
            $rows = array_map(fn($c) => $this->chunkToRow($c), $batch);
            $this->db()->table($this->table)->insert($rows);
        }
    }

    public function search(
        array $embedding,
        string $tenantId,
        ?string $collection = null,
        int $limit = 5,
        float $minRelevance = 0.0,
    ): array {
        $vectorParam = $this->vectorToString($embedding);

        $sql = "
            SELECT 
                id, tenant_id, collection, source, content, metadata,
                1 - (embedding <=> ?::vector) AS relevance
            FROM {$this->table}
            WHERE tenant_id = ?
        ";

        $bindings = [$vectorParam, $tenantId];

        if ($collection) {
            $sql .= " AND collection = ?";
            $bindings[] = $collection;
        }

        $sql .= " ORDER BY embedding <=> ?::vector LIMIT ?";
        $bindings[] = $vectorParam;
        $bindings[] = $limit;

        $rows = $this->db()->select($sql, $bindings);

        $chunks = [];
        foreach ($rows as $row) {
            if ($row->relevance < $minRelevance) continue;

            $chunks[] = new KnowledgeChunk(
                tenantId: $row->tenant_id,
                collection: $row->collection,
                source: $row->source,
                content: $row->content,
                metadata: is_string($row->metadata) ? json_decode($row->metadata, true) ?? [] : (array) $row->metadata,
                embedding: [],
                relevance: (float) $row->relevance,
                id: (int) $row->id,
            );
        }

        return $chunks;
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

    protected function chunkToRow(KnowledgeChunk $chunk): array
    {
        return [
            'tenant_id' => $chunk->tenantId,
            'collection' => $chunk->collection,
            'source' => $chunk->source,
            'content' => $chunk->content,
            'metadata' => json_encode($chunk->metadata, JSON_UNESCAPED_UNICODE),
            'embedding' => $this->vectorToString($chunk->embedding),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    protected function vectorToString(array $vector): string
    {
        return '[' . implode(',', $vector) . ']';
    }

    protected function db()
    {
        return DB::connection($this->connection);
    }
}
