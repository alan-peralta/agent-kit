<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Agents;

use InvalidArgumentException;
use Peralta\AgentKit\Refactoring\Agents\AgentCommandRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AgentCommandRepositoryTest extends TestCase
{
    private string $resources;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resources = dirname(__DIR__, 4) . '/resources/agents/refactoring';
    }

    public function test_it_exposes_commands_in_the_canonical_order(): void
    {
        $repository = new AgentCommandRepository($this->resources);

        self::assertSame(
            ['audit', 'analyze', 'callers', 'dependencies', 'impact', 'plan'],
            $repository->names(),
        );
    }

    public function test_every_command_prepends_the_same_deterministic_first_instructions(): void
    {
        $repository = new AgentCommandRepository($this->resources);
        $instructions = trim((string) file_get_contents($this->resources . '/instructions.md'));

        foreach ($repository->names() as $name) {
            $content = $repository->command($name);

            self::assertStringStartsWith($instructions . "\n\n# ", $content, $name);
            self::assertStringContainsString('ANALYZE != MODIFY', $content, $name);
            self::assertStringContainsString('MCP tools', $content, $name);
            self::assertStringContainsString('agent-kit:refactor-capabilities --json', $content, $name);
            self::assertStringContainsString('FACTS, INTERPRETATION, and RECOMMENDATIONS', $content, $name);
            self::assertStringContainsString('UNKNOWN or UNRESOLVED DYNAMIC REFERENCE', $content, $name);
            self::assertStringEndsWith("\n", $content, $name);
            self::assertFalse(str_ends_with($content, "\n\n"), $name);
        }
    }

    public function test_it_returns_each_complete_operation_body(): void
    {
        $repository = new AgentCommandRepository($this->resources);

        self::assertStringContainsString('Return Executive Summary, Architecture Score', $repository->command('audit'));
        self::assertStringContainsString('Return Target, Responsibilities, Metrics', $repository->command('analyze'));
        self::assertStringContainsString('Return DIRECT CALLERS, STRUCTURAL DEPENDENCIES', $repository->command('callers'));
        self::assertStringContainsString('Return UPSTREAM DEPENDENCIES, DOWNSTREAM DEPENDENTS', $repository->command('dependencies'));
        self::assertStringContainsString('dependency does not prove breakage', $repository->command('impact'));
        self::assertStringContainsString('Do not modify code.', $repository->command('plan'));
    }

    public function test_rules_are_composed_in_the_canonical_order_with_one_trailing_newline(): void
    {
        $repository = new AgentCommandRepository($this->resources);
        $expected = implode("\n\n", array_map(
            fn (string $name): string => trim((string) file_get_contents($this->resources . "/rules/{$name}.md")),
            ['core', 'laravel', 'smells', 'patterns'],
        )) . "\n";

        self::assertSame($expected, $repository->rules());
        self::assertTrue(
            strpos($expected, '# Refactoring safety') < strpos($expected, '# Laravel awareness')
            && strpos($expected, '# Laravel awareness') < strpos($expected, '# Code smells')
            && strpos($expected, '# Code smells') < strpos($expected, '# Patterns'),
        );
        self::assertStringContainsString('A DESIGN PATTERN IS NOT A GOAL.', $expected);
    }

    public function test_every_command_names_its_read_only_mcp_tool_and_never_an_apply_tool(): void
    {
        $repository = new AgentCommandRepository(dirname(__DIR__, 4) . '/resources/agents/refactoring');
        $tools = [
            'audit' => 'refactoring_audit',
            'analyze' => 'refactoring_analyze',
            'callers' => 'refactoring_callers',
            'dependencies' => 'refactoring_dependencies',
            'impact' => 'refactoring_impact',
            'plan' => 'refactoring_impact',
        ];

        foreach ($tools as $name => $tool) {
            $content = $repository->command($name);
            self::assertStringContainsString("`{$tool}`", $content, $name);
            self::assertStringContainsString('refactoring_capabilities', $content, $name);
            self::assertStringNotContainsString('refactoring_apply', $content, $name);
            self::assertStringNotContainsString('does not exist yet', $content, $name);
        }
    }

    public function test_it_rejects_unknown_command_names_before_resolving_a_path(): void
    {
        $repository = new AgentCommandRepository($this->resources);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown refactoring agent command: ../instructions');

        $repository->command('../instructions');
    }

    public function test_it_reports_a_missing_resource_with_a_stable_error(): void
    {
        $repository = new AgentCommandRepository(sys_get_temp_dir() . '/agent-kit-missing-resources-' . bin2hex(random_bytes(8)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Agent resource not found: instructions.md');

        $repository->command('audit');
    }
}
