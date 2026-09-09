<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Ast;

final class NameContext
{
    public function __construct(
        private ?string $class = null,
        private ?string $parent = null,
    ) {}

    public function set(?string $class, ?string $parent): void
    {
        $this->class = $class;
        $this->parent = $parent;
    }

    public function resolve(string $name): ?string
    {
        return match (strtolower($name)) {
            'self', 'static' => $this->class,
            'parent' => $this->parent,
            default => ltrim($name, '\\'),
        };
    }
}
