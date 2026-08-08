<?php

namespace Peralta\AgentKit\Knowledge;

class KnowledgeChunk
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $collection,
        public readonly string $source,
        public readonly string $content,
        public readonly array $metadata = [],
        public readonly array $embedding = [],
        public readonly ?float $relevance = null,
        public readonly int|string|null $id = null,
    ) {}
}
