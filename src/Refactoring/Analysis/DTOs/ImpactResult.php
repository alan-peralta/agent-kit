<?php

namespace Peralta\AgentKit\Refactoring\Analysis\DTOs;

final readonly class ImpactResult
{
    public function __construct(
        public string $target,
        public ?string $method,
        public int $directCallers,
        public int $structuralDependencies,
        public int $transitiveDependents,
        public int $affectedFiles,
        public string $risk,
        public array $direct,
        public array $structural,
        public array $transitive,
        public array $diagnostics,
    ) {}

    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'method' => $this->method,
            'direct_callers' => $this->directCallers,
            'structural_dependencies' => $this->structuralDependencies,
            'transitive_dependents' => $this->transitiveDependents,
            'affected_files' => $this->affectedFiles,
            'risk' => $this->risk,
            'direct' => $this->direct,
            'structural' => $this->structural,
            'transitive' => $this->transitive,
            'diagnostics' => array_map(fn ($diagnostic) => $diagnostic->toArray(), $this->diagnostics),
        ];
    }
}
