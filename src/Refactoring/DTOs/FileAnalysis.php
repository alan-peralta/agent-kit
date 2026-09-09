<?php

namespace Peralta\AgentKit\Refactoring\DTOs;

final readonly class FileAnalysis
{
    public function __construct(
        public string $path,
        public int $lines,
        public int $methods,
        public int $dependencies,
        public int $branches,
        public array $smells = [],
    ) {}

    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'lines' => $this->lines,
            'methods' => $this->methods,
            'dependencies' => $this->dependencies,
            'branches' => $this->branches,
            'smells' => $this->smells,
        ];
    }
}
