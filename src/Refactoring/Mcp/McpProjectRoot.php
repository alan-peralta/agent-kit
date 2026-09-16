<?php

namespace Peralta\AgentKit\Refactoring\Mcp;

use Peralta\AgentKit\Refactoring\Support\ProjectRoot;

final readonly class McpProjectRoot
{
    private function __construct(public string $path) {}

    public static function fromPath(string $path): self
    {
        $path = trim($path);
        if ($path === '') {
            throw new McpConfigurationException('The MCP project root must not be empty.');
        }

        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new McpConfigurationException("The MCP project root does not exist or is not a directory: {$path}");
        }

        return new self(ProjectRoot::normalize($real));
    }
}
