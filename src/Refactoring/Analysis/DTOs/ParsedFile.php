<?php

namespace Peralta\AgentKit\Refactoring\Analysis\DTOs;

final readonly class ParsedFile
{
    public function __construct(
        public string $file,
        public array $symbols = [],
        public array $references = [],
        public array $diagnostics = [],
        public ?string $namespace = null,
        public array $imports = [],
    ) {}
}
