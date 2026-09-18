<?php

namespace Peralta\AgentKit\Tests\Feature\Refactoring;

use Composer\InstalledVersions;
use Peralta\AgentKit\Refactoring\Analysis\Index\CachedCodebaseIndexer;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexBuilder;
use Peralta\AgentKit\Refactoring\Analysis\Index\IndexSnapshotStore;
use Peralta\AgentKit\Refactoring\Application\DefaultRefactoringCapabilities;
use Peralta\AgentKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;

final class IndexCacheBindingTest extends TestCase
{
    public function test_the_index_builder_is_a_process_wide_cached_singleton(): void
    {
        $first = $this->app->make(CodebaseIndexBuilder::class);
        $second = $this->app->make(CodebaseIndexBuilder::class);

        self::assertInstanceOf(CachedCodebaseIndexer::class, $first);
        self::assertSame($first, $second);
    }

    /** @return array<string, array{0: mixed, 1: string|null}> */
    public static function snapshotPaths(): array
    {
        return [
            'null is the default directory' => [null, 'default'],
            'empty is the default directory' => ['', 'default'],
            'false disables snapshots' => [false, null],
            'any other string is the directory' => ['/tmp/agent-kit-custom-index', '/tmp/agent-kit-custom-index'],
        ];
    }

    #[DataProvider('snapshotPaths')]
    public function test_the_index_cache_path_selects_the_snapshot_directory(mixed $path, ?string $expected): void
    {
        $store = $this->snapshotStore($path);

        if ($expected === null) {
            self::assertNull($store);

            return;
        }

        self::assertInstanceOf(IndexSnapshotStore::class, $store);
        self::assertSame(
            $expected === 'default' ? storage_path('framework/cache/agent-kit/index') : $expected,
            (new ReflectionProperty(IndexSnapshotStore::class, 'directory'))->getValue($store),
        );
    }

    public function test_the_snapshot_context_names_the_parser_and_this_package_and_follows_the_facades(): void
    {
        $context = $this->context($this->snapshotStore(null));

        self::assertStringContainsString((string) InstalledVersions::getPrettyVersion('nikic/php-parser'), $context);
        self::assertStringContainsString(
            (string) (InstalledVersions::getReference('peralta/agent-kit') ?? InstalledVersions::getPrettyVersion('peralta/agent-kit')),
            $context,
        );

        config(['agent-kit.refactoring.facades' => ['App\\Facades\\']]);

        self::assertNotSame($context, $this->context($this->snapshotStore(null)));
    }

    public function test_capabilities_depend_on_the_builder_interface(): void
    {
        $parameters = (new ReflectionClass(DefaultRefactoringCapabilities::class))->getConstructor()->getParameters();
        $types = array_map(fn ($parameter) => $parameter->getType()?->getName(), $parameters);

        self::assertContains(CodebaseIndexBuilder::class, $types);
    }

    private function snapshotStore(mixed $path): ?IndexSnapshotStore
    {
        config(['agent-kit.mcp.index_cache.path' => $path]);
        $this->app->forgetInstance(CachedCodebaseIndexer::class);

        return (new ReflectionProperty(CachedCodebaseIndexer::class, 'snapshots'))->getValue($this->app->make(CachedCodebaseIndexer::class));
    }

    private function context(IndexSnapshotStore $store): string
    {
        return (new ReflectionProperty(IndexSnapshotStore::class, 'context'))->getValue($store);
    }
}
