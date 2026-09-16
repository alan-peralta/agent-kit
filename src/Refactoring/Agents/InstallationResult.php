<?php

namespace Peralta\AgentKit\Refactoring\Agents;

final readonly class InstallationResult
{
    /**
     * @param list<string> $created
     * @param list<string> $unchanged
     * @param list<string> $conflicts
     * @param list<string> $overwritten
     */
    public function __construct(
        public array $created = [],
        public array $unchanged = [],
        public array $conflicts = [],
        public array $overwritten = [],
    ) {}

    public function successful(): bool
    {
        return $this->conflicts === [];
    }
}
