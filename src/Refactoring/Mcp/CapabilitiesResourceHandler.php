<?php

namespace Peralta\AgentKit\Refactoring\Mcp;

use Mcp\Schema\Content\TextResourceContents;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ResourceHandlerInterface;
use Peralta\AgentKit\Refactoring\Application\CapabilityResult;
use Peralta\AgentKit\Refactoring\Application\RefactoringCapabilities;

final class CapabilitiesResourceHandler implements ResourceHandlerInterface
{
    public function __construct(
        private readonly RefactoringCapabilities $capabilities,
        private readonly RefactoringToolCatalog $catalog,
        private readonly McpProjectRoot $root,
        private readonly string $serverName,
        private readonly string $serverVersion,
    ) {}

    public function read(string $uri, ClientGateway $gateway): TextResourceContents
    {
        return $this->readDocument($uri);
    }

    public function readDocument(string $uri): TextResourceContents
    {
        return new TextResourceContents(
            $uri,
            'application/json',
            json_encode($this->describe(), RefactoringToolHandler::JSON_FLAGS),
        );
    }

    public function describe(): array
    {
        $descriptors = [];
        foreach ($this->capabilities->describeCapabilities()->data['capabilities'] as $descriptor) {
            $descriptors[$descriptor['name']] = $descriptor;
        }

        $tools = [];
        foreach ($this->catalog->tools() as $definition) {
            $descriptor = $descriptors[$definition->capability] ?? null;
            $tools[] = [
                'name' => $definition->name,
                'capability' => $definition->capability,
                'title' => $definition->title,
                'description' => $definition->description,
                'targets' => $descriptor['targets'] ?? [],
                'cli_fallback' => $descriptor['cli_fallback'] ?? 'php artisan agent-kit:refactor-capabilities --json',
                'input_schema' => $definition->inputSchema,
                'output_schema' => $definition->outputSchema,
            ];
        }

        return [
            'schema_version' => CapabilityResult::SCHEMA_VERSION,
            'server' => ['name' => $this->serverName, 'version' => $this->serverVersion],
            'project_root' => $this->root->path,
            'tools' => $tools,
            'result_format' => [
                'success' => ['schema_version', 'capability', 'incomplete', 'data', 'diagnostics', 'unresolved'],
                'error' => ['schema_version', 'error' => ['code', 'message']],
                'incomplete_semantics' => 'incomplete=true means static analysis could not resolve every reference; diagnostics list parse problems and unresolved lists dynamic references. Nothing is invented to fill them.',
                'errors' => 'Domain failures are tool results with isError=true carrying the error envelope in structuredContent; schema violations are JSON-RPC -32602 errors.',
            ],
            'limitations' => [
                'Static PHP analysis only: no code execution, no runtime container resolution, no dynamic class strings, no reflection or macros.',
                'Every tool operates on the single project root fixed when the server started; paths outside it are rejected.',
                'The codebase index is rebuilt whenever any included PHP file changes; results always reflect the current files.',
                'Dependency does not prove breakage: treat impact results as potentially affected components to verify.',
            ],
            'mutation' => [
                'supported' => false,
                'note' => 'ANALYZE != MODIFY: this server only audits, analyzes and explains. No apply, edit, write or shell capability exists or is planned.',
            ],
        ];
    }
}
