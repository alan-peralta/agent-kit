<?php

namespace Peralta\AgentKit\Tests\Feature\Database;

use Illuminate\Support\ServiceProvider;
use Peralta\AgentKit\AgentKitServiceProvider;
use Peralta\AgentKit\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class MigrationPublishingTest extends TestCase
{
    /** Publish tag => package directory it publishes into the application's database/migrations. */
    private const TAGS = [
        'agent-kit-migrations' => 'database/migrations',
        'agent-kit-pgvector-migrations' => 'database/knowledge/pgvector',
    ];

    public function test_each_migration_tag_publishes_one_package_directory_into_the_app_migrations(): void
    {
        foreach (self::TAGS as $tag => $directory) {
            $this->assertSame(
                [$this->packagePath($directory) => $this->app->databasePath('migrations')],
                $this->resolved(ServiceProvider::pathsToPublish(AgentKitServiceProvider::class, $tag)),
                $tag,
            );
        }
    }

    public function test_the_default_tag_ships_only_the_portable_migrations(): void
    {
        $this->assertSame(
            [
                '2026_05_05_000001_create_agent_messages_table.php',
                '2026_08_08_000003_create_agent_kit_metrics_table.php',
            ],
            $this->fileNames('database/migrations'),
        );
    }

    public function test_every_packaged_migration_belongs_to_exactly_one_tag(): void
    {
        $tagDirectories = array_map(fn (string $directory): string => $this->packagePath($directory), self::TAGS);

        foreach ($this->packagedMigrations() as $file) {
            $owners = array_filter($tagDirectories, fn (string $directory): bool => dirname($file) === $directory);

            $this->assertCount(1, $owners, basename($file) . ' must belong to exactly one publish tag.');
        }
    }

    private function packagePath(string $relative): string
    {
        return (string) realpath(dirname(__DIR__, 3) . '/' . $relative);
    }

    /**
     * @param  array<string, string>  $paths
     * @return array<string, string>
     */
    private function resolved(array $paths): array
    {
        $resolved = [];
        foreach ($paths as $from => $to) {
            $resolved[(string) realpath($from)] = $to;
        }

        return $resolved;
    }

    /** @return list<string> */
    private function fileNames(string $directory): array
    {
        $names = array_map('basename', glob($this->packagePath($directory) . '/*.php') ?: []);
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private function packagedMigrations(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->packagePath('database'), RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = (string) $file->getRealPath();
            }
        }
        sort($files);

        return $files;
    }
}
