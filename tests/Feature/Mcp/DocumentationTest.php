<?php

namespace Peralta\AgentKit\Tests\Feature\Mcp;

use Peralta\AgentKit\Refactoring\Mcp\RefactoringToolCatalog;
use PHPUnit\Framework\TestCase;

final class DocumentationTest extends TestCase
{
    public function test_docs_name_the_real_tools_command_and_variables_and_no_longer_call_mcp_future_work(): void
    {
        $root = dirname(__DIR__, 3);
        $mcp = (string) file_get_contents($root . '/MCP_SERVER.md');
        $readme = (string) file_get_contents($root . '/README.md');
        $refactoring = (string) file_get_contents($root . '/REFACTORING_AGENT.md');
        $setup = (string) file_get_contents($root . '/SETUP.md');
        $changelog = (string) file_get_contents($root . '/CHANGELOG.md');
        $env = (string) file_get_contents($root . '/.env.example');

        foreach ((new RefactoringToolCatalog())->names() as $tool) {
            self::assertStringContainsString($tool, $mcp, $tool);
            self::assertStringContainsString($tool, $refactoring, $tool);
        }
        foreach ([$mcp, $readme, $refactoring, $setup] as $document) {
            self::assertStringContainsString('agent-kit:mcp', $document);
        }
        foreach (['AGENT_KIT_MCP_ENABLED', 'AGENT_KIT_MCP_TRANSPORT', 'AGENT_KIT_MCP_PROJECT_ROOT', 'AGENT_KIT_MCP_HTTP_PATH', 'AGENT_KIT_MCP_ALLOW_REMOTE', 'AGENT_KIT_MCP_ALLOWED_ORIGINS', 'AGENT_KIT_MCP_BEARER_TOKEN'] as $variable) {
            self::assertStringContainsString($variable, $mcp, $variable);
            self::assertStringContainsString($variable, $env, $variable);
        }
        self::assertStringContainsString('agent-kit://refactoring/capabilities', $mcp);
        self::assertStringContainsString('@modelcontextprotocol/inspector', $mcp);
        self::assertStringContainsString('MCP_SERVER.md', $readme);
        self::assertStringContainsString('agent-kit:mcp', $changelog);

        foreach (['does not exist yet', 'is a future adapter', 'ainda não está disponível', 'integração futura', 'futuro servidor MCP', 'Future MCP'] as $stale) {
            self::assertStringNotContainsString($stale, $readme, $stale);
            self::assertStringNotContainsString($stale, $refactoring, $stale);
        }
        self::assertDoesNotMatchRegularExpression('/AGENT_KIT_MCP_BEARER_TOKEN=\S+/', $env, 'No token value may be committed.');
        self::assertDoesNotMatchRegularExpression('/Bearer [0-9a-f]{32,}/', $mcp, 'Docs must use placeholders, never real-looking tokens.');
    }
}
