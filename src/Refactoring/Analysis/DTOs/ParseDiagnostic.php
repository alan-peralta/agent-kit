<?php

namespace Peralta\AgentKit\Refactoring\Analysis\DTOs;

final readonly class ParseDiagnostic
{
    public function __construct(
        public string $file,
        public int $line,
        public string $message,
    ) {}

    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'message' => $this->message,
        ];
    }
}
