<?php

namespace Peralta\AgentKit\Refactoring\Mcp;

use LogicException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Peralta\AgentKit\Refactoring\Application\CapabilityException;
use Peralta\AgentKit\Refactoring\Application\CapabilityResult;
use Peralta\AgentKit\Refactoring\Application\RefactoringCapabilities;

final class RefactoringToolHandler implements ToolHandlerInterface
{
    // Same flags as the CLI --json renderer so both adapters emit identical text.
    public const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly RefactoringCapabilities $capabilities,
        private readonly McpProjectRoot $root,
        private readonly ToolDefinition $definition,
    ) {}

    public function execute(array $arguments, ClientGateway $gateway): CallToolResult
    {
        return $this->call($arguments);
    }

    /** @param array<string, mixed> $arguments */
    public function call(array $arguments): CallToolResult
    {
        try {
            $envelope = $this->dispatch($arguments)->toArray();
        } catch (CapabilityException $exception) {
            $envelope = $exception->toArray();

            return new CallToolResult(
                [new TextContent(json_encode($envelope, self::JSON_FLAGS))],
                isError: true,
                structuredContent: $envelope,
            );
        }

        return new CallToolResult(
            [new TextContent(json_encode($envelope, self::JSON_FLAGS))],
            isError: false,
            structuredContent: $envelope,
        );
    }

    /** @param array<string, mixed> $arguments */
    private function dispatch(array $arguments): CapabilityResult
    {
        $root = $this->root->path;

        return match ($this->definition->capability) {
            'capability_discovery' => $this->capabilities->describeCapabilities(),
            'audit' => $this->capabilities->audit($root),
            'analyze' => $this->capabilities->analyze($root, $this->target($arguments)),
            'find_callers' => $this->capabilities->findCallers($root, $this->target($arguments)),
            'dependencies' => $this->capabilities->dependencies($root, $this->target($arguments)),
            'impact' => $this->capabilities->impact($root, $this->target($arguments)),
            default => throw new LogicException("Unmapped capability: {$this->definition->capability}"),
        };
    }

    /** @param array<string, mixed> $arguments */
    private function target(array $arguments): string
    {
        $target = $arguments['target'] ?? null;
        if (!is_string($target)) {
            throw new CapabilityException('INVALID_TARGET', 'The target argument must be a string.');
        }

        $target = trim($target);
        // A NUL or any other control character inside the target can only come from a malformed
        // client: it never occurs in a class name or a path, and it would otherwise travel into
        // filesystem calls and log lines. Refuse it as a domain error, like any other bad target.
        if (preg_match('/[\x00-\x1F\x7F]/', $target) === 1) {
            throw new CapabilityException('INVALID_TARGET', 'The target argument must not contain control characters.');
        }

        return $target;
    }
}
