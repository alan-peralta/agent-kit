<?php

namespace Peralta\AgentKit\Refactoring\Analysis\DTOs;

use Peralta\AgentKit\Refactoring\Analysis\Graph\Confidence;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyType;

final readonly class Reference
{
    public function __construct(
        public string $source,
        public ?string $sourceMethod,
        public ?string $target,
        public ?string $targetMethod,
        public DependencyType $type,
        public Confidence $confidence,
        public string $file,
        public int $line,
        public array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'source_method' => $this->sourceMethod,
            'target' => $this->target,
            'target_method' => $this->targetMethod,
            'type' => $this->type->value,
            'confidence' => $this->confidence->value,
            'file' => $this->file,
            'line' => $this->line,
            'metadata' => $this->metadata,
        ];
    }
}
