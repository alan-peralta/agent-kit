<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Graph;

final readonly class DependencyNode
{
    public function __construct(
        public string $fqcn,
        public string $kind,
        public string $file,
        public int $line,
    ) {}

    public function toArray(): array
    {
        return [
            'fqcn' => $this->fqcn,
            'kind' => $this->kind,
            'file' => $this->file,
            'line' => $this->line,
        ];
    }
}
