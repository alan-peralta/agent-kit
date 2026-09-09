<?php

namespace Peralta\AgentKit\Refactoring\Analysis\DTOs;

final readonly class SymbolDefinition
{
    public function __construct(
        public string $fqcn,
        public string $kind,
        public string $file,
        public int $line,
        public array $methods = [],
        public array $properties = [],
        public array $constants = [],
        public array $attributes = [],
    ) {}

    public function toArray(): array
    {
        return [
            'fqcn' => $this->fqcn,
            'kind' => $this->kind,
            'file' => $this->file,
            'line' => $this->line,
            'methods' => $this->methods,
            'properties' => $this->properties,
            'constants' => $this->constants,
            'attributes' => $this->attributes,
        ];
    }
}
