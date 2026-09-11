<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Agents;

use Error;
use Peralta\AgentKit\Refactoring\Agents\AgentAdapter;
use Peralta\AgentKit\Refactoring\Agents\AgentCommandRepository;
use Peralta\AgentKit\Refactoring\Agents\AgentTemplateRenderer;
use Peralta\AgentKit\Refactoring\Agents\ClaudeCodeAgentAdapter;
use Peralta\AgentKit\Refactoring\Agents\CursorAgentAdapter;
use Peralta\AgentKit\Refactoring\Agents\GeneratedAgentFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class AgentAdapterTest extends TestCase
{
    private const VALUES = [
        'cli_audit' => 'php artisan agent-kit:refactor-audit',
        'cli_analyze' => 'php artisan agent-kit:refactor-analyze',
        'cli_callers' => 'php artisan agent-kit:refactor-callers',
        'cli_dependencies' => 'php artisan agent-kit:refactor-dependencies',
        'cli_impact' => 'php artisan agent-kit:refactor-impact',
    ];

    #[DataProvider('adapters')]
    public function test_adapter_output_matches_the_exact_golden_inventory(
        AgentAdapter $adapter,
        string $fixture,
        array $expectedPaths,
    ): void {
        $files = $adapter->generate($this->repository(), new AgentTemplateRenderer());
        $actualPaths = array_map(fn (GeneratedAgentFile $file): string => $file->path, $files);

        self::assertCount(7, $files);
        self::assertSame($expectedPaths, $actualPaths);
        $expectedInventory = $expectedPaths;
        sort($expectedInventory);
        self::assertSame($expectedInventory, $this->fixtureInventory($fixture));

        foreach ($files as $file) {
            $expected = file_get_contents($fixture . '/' . $file->path);

            self::assertNotFalse($expected, $file->path);
            self::assertSame($expected, $file->content, $file->path);
        }
    }

    #[DataProvider('adapters')]
    public function test_generation_is_deterministic_and_uses_unique_safe_relative_paths(
        AgentAdapter $adapter,
        string $fixture,
        array $expectedPaths,
    ): void {
        $first = $adapter->generate($this->repository(), new AgentTemplateRenderer());
        $second = $adapter->generate($this->repository(), new AgentTemplateRenderer());
        $paths = array_map(fn (GeneratedAgentFile $file): string => $file->path, $first);

        self::assertEquals($first, $second);
        self::assertSame($expectedPaths, $paths);
        self::assertSame($paths, array_values(array_unique($paths)));

        foreach ($paths as $path) {
            self::assertSame(1, preg_match('#^\.(?:cursor|claude)/(?:skills|rules)/[A-Za-z0-9._/-]+$#', $path), $path);
            self::assertStringNotContainsString('..', $path);
            self::assertFalse(str_starts_with($path, '/'));
        }
    }

    public function test_cursor_uses_native_frontmatter_and_the_canonical_semantic_bodies(): void
    {
        $repository = $this->repository();
        $files = (new CursorAgentAdapter())->generate($repository, new AgentTemplateRenderer());

        foreach (array_slice($files, 0, 6) as $offset => $file) {
            $name = $repository->names()[$offset];

            self::assertStringStartsWith("---\nname: refactor-{$name}\ndescription: ", $file->content);
            self::assertStringEndsWith($this->renderedCommand($repository, $name), $file->content);
            self::assertStringContainsString('## Execution priority', $file->content);
            self::assertDoesNotMatchRegularExpression('/\{\{[^{}]+\}\}/', $file->content);
        }

        $rule = $files[6];
        self::assertStringStartsWith("---\ndescription: ", $rule->content);
        self::assertStringContainsString("\nalwaysApply: true\n---\n\n", $rule->content);
        self::assertStringEndsWith($repository->rules(), $rule->content);
    }

    public function test_claude_uses_explicit_only_skill_frontmatter_and_shared_rules_without_metadata(): void
    {
        $repository = $this->repository();
        $files = (new ClaudeCodeAgentAdapter())->generate($repository, new AgentTemplateRenderer());

        foreach (array_slice($files, 0, 6) as $offset => $file) {
            $name = $repository->names()[$offset];

            self::assertStringStartsWith("---\nname: refactor-{$name}\ndescription: ", $file->content);
            self::assertStringContainsString("\ndisable-model-invocation: true\n---\n\n", $file->content);
            self::assertStringEndsWith($this->renderedCommand($repository, $name), $file->content);
            self::assertStringContainsString('## Execution priority', $file->content);
            self::assertDoesNotMatchRegularExpression('/\{\{[^{}]+\}\}/', $file->content);
        }

        self::assertSame($repository->rules(), $files[6]->content);
    }

    public function test_adapters_have_stable_identifiers(): void
    {
        self::assertSame('cursor', (new CursorAgentAdapter())->id());
        self::assertSame('claude', (new ClaudeCodeAgentAdapter())->id());
    }

    public function test_generated_files_are_immutable(): void
    {
        $file = new GeneratedAgentFile('safe/path.md', 'content');

        $this->expectException(Error::class);
        $file->path = 'changed.md';
    }

    public static function adapters(): array
    {
        $expected = dirname(__DIR__, 3) . '/Fixtures/Refactoring/Agents/Expected';
        $commands = ['audit', 'analyze', 'callers', 'dependencies', 'impact', 'plan'];

        return [
            'cursor' => [
                new CursorAgentAdapter(),
                "{$expected}/cursor",
                [
                    ...array_map(fn (string $name): string => ".cursor/skills/refactor-{$name}/SKILL.md", $commands),
                    '.cursor/rules/agent-kit-refactoring.mdc',
                ],
            ],
            'claude' => [
                new ClaudeCodeAgentAdapter(),
                "{$expected}/claude",
                [
                    ...array_map(fn (string $name): string => ".claude/skills/refactor-{$name}/SKILL.md", $commands),
                    '.claude/rules/agent-kit-refactoring.md',
                ],
            ],
        ];
    }

    private function repository(): AgentCommandRepository
    {
        return new AgentCommandRepository(dirname(__DIR__, 4) . '/resources/agents/refactoring');
    }

    private function renderedCommand(AgentCommandRepository $repository, string $name): string
    {
        return (new AgentTemplateRenderer())->render($repository->command($name), self::VALUES);
    }

    /** @return list<string> */
    private function fixtureInventory(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $paths[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }

        sort($paths);

        return $paths;
    }
}
