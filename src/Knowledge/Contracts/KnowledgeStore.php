<?php

namespace Peralta\AgentKit\Knowledge\Contracts;

use Peralta\AgentKit\Knowledge\KnowledgeChunk;

interface KnowledgeStore
{
    public function insert(KnowledgeChunk $chunk): void;

    /**
     * @param  KnowledgeChunk[]  $chunks
     */
    public function insertBatch(array $chunks): void;

    /**
     * @return KnowledgeChunk[]
     */
    public function search(
        array $embedding,
        string $tenantId,
        ?string $collection = null,
        int $limit = 5,
        float $minRelevance = 0.0,
    ): array;

    public function deleteBySource(string $tenantId, string $source): int;

    public function deleteByTenant(string $tenantId): int;
}
