<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Agents;

use InvalidArgumentException;
use Peralta\AgentKit\Refactoring\Agents\AgentAdapter;
use Peralta\AgentKit\Refactoring\Agents\AgentAdapterRegistry;
use Peralta\AgentKit\Refactoring\Agents\AgentCommandRepository;
use Peralta\AgentKit\Refactoring\Agents\AgentConfigurationInstaller;
use Peralta\AgentKit\Refactoring\Agents\AgentTemplateRenderer;
use Peralta\AgentKit\Refactoring\Agents\ClaudeCodeAgentAdapter;
use Peralta\AgentKit\Refactoring\Agents\CursorAgentAdapter;
use Peralta\AgentKit\Refactoring\Agents\GeneratedAgentFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AgentConfigurationInstallerTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            $this->removeDirectory($directory);
        }
    }

    public function test_registry_preserves_order_and_resolves_exact_ids(): void
    {
        $cursor = new CursorAgentAdapter();
        $claude = new ClaudeCodeAgentAdapter();
        $registry = new AgentAdapterRegistry([$claude, $cursor]);

        self::assertSame(['claude', 'cursor'], $registry->ids());
        self::assertSame($cursor, $registry->get('cursor'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported coding agent: Cursor');
        $registry->get('Cursor');
    }

    #[DataProvider('invalidRegistryAdapters')]
    public function test_registry_rejects_empty_and_duplicate_ids(array $adapters, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new AgentAdapterRegistry($adapters);
    }

    public static function invalidRegistryAdapters(): array
    {
        return [
            'empty' => [[self::adapter('', [])], 'Coding agent adapter ID must not be empty.'],
            'duplicate' => [[self::adapter('same', []), self::adapter('same', [])], 'Duplicate coding agent adapter ID: same'],
        ];
    }

    public function test_it_creates_then_leaves_identical_files_unchanged(): void
    {
        $root = $this->temporaryDirectory();
        $installer = $this->installer();

        $created = $installer->install($root, ['cursor']);
        $unchanged = $installer->install($root, ['cursor']);

        self::assertCount(7, $created->created);
        self::assertSame([], $created->unchanged);
        self::assertSame([], $created->conflicts);
        self::assertSame([], $created->overwritten);
        self::assertTrue($created->successful());
        self::assertSame([], $unchanged->created);
        self::assertCount(7, $unchanged->unchanged);
        self::assertSame([], $unchanged->conflicts);
        self::assertTrue($unchanged->successful());

        foreach ($created->created as $path) {
            self::assertFileExists($root . '/' . $path);
        }
    }

    public function test_conflicts_are_preserved_while_other_files_are_created(): void
    {
        $root = $this->temporaryDirectory();
        $path = '.cursor/skills/refactor-impact/SKILL.md';
        $target = $root . '/' . $path;
        mkdir(dirname($target), 0777, true);
        file_put_contents($target, 'custom');

        $result = $this->installer()->install($root, ['cursor']);

        self::assertSame('custom', file_get_contents($target));
        self::assertSame([$path], $result->conflicts);
        self::assertCount(6, $result->created);
        self::assertFalse($result->successful());
    }

    public function test_force_atomically_overwrites_different_regular_files(): void
    {
        $root = $this->temporaryDirectory();
        $path = '.cursor/skills/refactor-impact/SKILL.md';
        $target = $root . '/' . $path;
        mkdir(dirname($target), 0777, true);
        file_put_contents($target, 'custom');
        chmod($target, 0640);

        $result = $this->installer()->install($root, ['cursor'], true);

        self::assertSame([$path], $result->overwritten);
        self::assertNotSame('custom', file_get_contents($target));
        self::assertSame(0640, fileperms($target) & 0777);
        self::assertSame([], glob($target . '.agent-kit-*') ?: []);
    }

    public function test_multiple_adapters_preserve_requested_artifact_order_and_normalize_paths(): void
    {
        $root = $this->temporaryDirectory();
        $first = self::adapter('first', [
            new GeneratedAgentFile('one\\file.md', 'one'),
            new GeneratedAgentFile('two/file.md', 'two'),
        ]);
        $second = self::adapter('second', [new GeneratedAgentFile('three/file.md', 'three')]);

        $result = $this->installer([$first, $second])->install($root, ['second', 'first']);

        self::assertSame(['three/file.md', 'one/file.md', 'two/file.md'], $result->created);
    }

    public function test_unknown_adapter_and_invalid_root_fail_stably(): void
    {
        $root = $this->temporaryDirectory();

        try {
            $this->installer()->install($root, ['missing']);
            self::fail('Unknown adapter was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Unsupported coding agent: missing', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Project root is not an existing directory:');
        $this->installer()->install($root . '/missing', ['cursor']);
    }

    #[DataProvider('unsafePaths')]
    public function test_it_rejects_unsafe_generated_paths(string $path): void
    {
        $root = $this->temporaryDirectory();
        $adapter = self::adapter('evil', [new GeneratedAgentFile($path, 'payload')]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe generated agent path:');
        $this->installer([$adapter])->install($root, ['evil']);
    }

    public static function unsafePaths(): array
    {
        return [
            'empty' => [''],
            'unix absolute' => ['/tmp/escape'],
            'windows absolute' => ['C:\\escape\\file'],
            'windows slash absolute' => ['C:/escape/file'],
            'windows drive relative' => ['C:escape/file'],
            'unc' => ['\\\\server\\share\\file'],
            'backslash rooted' => ['\\escape\\file'],
            'parent traversal' => ['safe/../escape'],
            'mixed traversal' => ['safe\\..\\escape'],
            'dot segment' => ['safe/./file'],
            'duplicate separator' => ['safe//file'],
            'nul' => ["safe/file\0.md"],
        ];
    }

    public function test_identical_duplicate_destinations_are_processed_once(): void
    {
        $root = $this->temporaryDirectory();
        $one = self::adapter('one', [new GeneratedAgentFile('same/file.md', 'same')]);
        $two = self::adapter('two', [new GeneratedAgentFile('same\\file.md', 'same')]);

        $result = $this->installer([$one, $two])->install($root, ['one', 'two']);

        self::assertSame(['same/file.md'], $result->created);
    }

    public function test_different_duplicate_destinations_are_rejected_before_writing(): void
    {
        $root = $this->temporaryDirectory();
        $one = self::adapter('one', [new GeneratedAgentFile('same/file.md', 'one')]);
        $two = self::adapter('two', [new GeneratedAgentFile('same/file.md', 'two')]);

        try {
            $this->installer([$one, $two])->install($root, ['one', 'two']);
            self::fail('Conflicting destinations were accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Generated agent path collision: same/file.md', $exception->getMessage());
        }

        self::assertFileDoesNotExist($root . '/same/file.md');
    }

    public function test_symlinked_parent_may_not_redirect_outside_the_project(): void
    {
        $root = $this->temporaryDirectory();
        $outside = $this->temporaryDirectory();
        symlink($outside, $root . '/redirect');
        $adapter = self::adapter('evil', [new GeneratedAgentFile('redirect/file.md', 'payload')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generated agent path escapes project root through symlink: redirect/file.md');
        $this->installer([$adapter])->install($root, ['evil']);
    }

    public function test_project_root_itself_may_be_a_symlink_and_is_canonicalized_once(): void
    {
        $root = $this->temporaryDirectory();
        $container = $this->temporaryDirectory();
        $linkedRoot = $container . '/project';
        symlink($root, $linkedRoot);
        $adapter = self::adapter('safe', [new GeneratedAgentFile('safe/file.md', 'payload')]);

        $result = $this->installer([$adapter])->install($linkedRoot, ['safe']);

        self::assertSame(['safe/file.md'], $result->created);
        self::assertSame('payload', file_get_contents($root . '/safe/file.md'));
    }

    public function test_existing_target_symlink_is_a_conflict_and_is_never_followed(): void
    {
        $root = $this->temporaryDirectory();
        $outside = $this->temporaryDirectory();
        $outsideFile = $outside . '/owned.md';
        file_put_contents($outsideFile, 'outside');
        mkdir($root . '/safe');
        symlink($outsideFile, $root . '/safe/file.md');
        $adapter = self::adapter('safe', [new GeneratedAgentFile('safe/file.md', 'payload')]);

        $result = $this->installer([$adapter])->install($root, ['safe']);

        self::assertSame(['safe/file.md'], $result->conflicts);
        self::assertSame('outside', file_get_contents($outsideFile));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to overwrite generated agent symlink: safe/file.md');
        $this->installer([$adapter])->install($root, ['safe'], true);
    }

    public function test_failed_atomic_overwrite_preserves_original_and_cleans_temporary_file(): void
    {
        $root = $this->temporaryDirectory();
        mkdir($root . '/safe');
        file_put_contents($root . '/safe/file.md', 'original');
        $adapter = self::adapter('safe', [new GeneratedAgentFile('safe/file.md', 'replacement')]);
        $installer = new AgentConfigurationInstaller(
            new AgentAdapterRegistry([$adapter]),
            new AgentCommandRepository(dirname(__DIR__, 4) . '/resources/agents/refactoring'),
            new AgentTemplateRenderer(),
            static fn (string $temporary, string $target): bool => false,
        );

        try {
            $installer->install($root, ['safe'], true);
            self::fail('Failed rename was treated as successful.');
        } catch (RuntimeException $exception) {
            self::assertSame('Could not install agent file: safe/file.md', $exception->getMessage());
        }

        self::assertSame('original', file_get_contents($root . '/safe/file.md'));
        self::assertSame([], glob($root . '/safe/file.md.agent-kit-*') ?: []);
    }

    /** @param list<AgentAdapter>|null $adapters */
    private function installer(?array $adapters = null): AgentConfigurationInstaller
    {
        return new AgentConfigurationInstaller(
            new AgentAdapterRegistry($adapters ?? [new CursorAgentAdapter(), new ClaudeCodeAgentAdapter()]),
            new AgentCommandRepository(dirname(__DIR__, 4) . '/resources/agents/refactoring'),
            new AgentTemplateRenderer(),
        );
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/agent-kit-installer-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0777, true));
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    /** @param list<GeneratedAgentFile> $files */
    private static function adapter(string $id, array $files): AgentAdapter
    {
        return new class($id, $files) implements AgentAdapter
        {
            /** @param list<GeneratedAgentFile> $files */
            public function __construct(private readonly string $adapterId, private readonly array $files) {}

            public function id(): string
            {
                return $this->adapterId;
            }

            public function generate(AgentCommandRepository $repository, AgentTemplateRenderer $renderer): array
            {
                return $this->files;
            }
        };
    }

    private function removeDirectory(string $directory): void
    {
        if (!file_exists($directory) && !is_link($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) {
                unlink($item->getPathname());
            } else {
                rmdir($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
