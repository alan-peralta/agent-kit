<?php

namespace Peralta\AgentKit\Refactoring\Mcp;

use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;

final readonly class ToolDefinition
{
    public function __construct(
        public string $name,
        public string $capability,
        public string $title,
        public string $description,
        public array $inputSchema,
        public array $outputSchema,
    ) {}

    public function requiresTarget(): bool
    {
        return isset($this->inputSchema['properties']['target']);
    }

    public function toTool(): Tool
    {
        return new Tool(
            name: $this->name,
            title: $this->title,
            inputSchema: $this->inputSchema,
            description: $this->description,
            annotations: new ToolAnnotations(
                title: $this->title,
                readOnlyHint: true,
                destructiveHint: false,
                idempotentHint: true,
                openWorldHint: false,
            ),
            outputSchema: $this->outputSchema,
        );
    }
}
