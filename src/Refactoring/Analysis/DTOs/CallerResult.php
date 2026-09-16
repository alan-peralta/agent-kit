<?php

namespace Peralta\AgentKit\Refactoring\Analysis\DTOs;

final readonly class CallerResult
{
    /**
     * @param list<array<string, mixed>> $transitiveDependents
     * @param list<array<string, mixed>> $unresolved Project-wide references whose target could not be resolved
     */
    public function __construct(
        public string $target,
        public ?string $method,
        public array $directCallers,
        public array $structuralDependencies,
        public array $transitiveDependents,
        public array $unresolved,
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
            'unresolved' => $this->unresolved,
            // Target-less references cannot be attributed more narrowly than the project.
            'unresolved_scope' => 'project',
            'diagnostics' => array_map(fn ($diagnostic) => $diagnostic->toArray(), $this->diagnostics),
        ];
    }
}
