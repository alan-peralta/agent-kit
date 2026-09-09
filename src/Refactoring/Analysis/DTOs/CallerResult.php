<?php

namespace Peralta\AgentKit\Refactoring\Analysis\DTOs;

final readonly class CallerResult
{
    public function __construct(
        public string $target,
        public ?string $method,
        public array $directCallers,
        public array $structuralDependencies,
        public array $diagnostics,
    ) {}

    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'method' => $this->method,
            'direct_callers' => $this->directCallers,
            'structural_dependencies' => $this->structuralDependencies,
            'diagnostics' => array_map(fn ($diagnostic) => $diagnostic->toArray(), $this->diagnostics),
        ];
    }
}
