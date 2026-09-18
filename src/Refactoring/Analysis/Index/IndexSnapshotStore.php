<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Index;

use Peralta\AgentKit\Refactoring\Mcp\McpServerFactory;

/**
 * One file per project root holding the latest index, so a per-request process (PHP-FPM,
 * php artisan serve, each CLI command) does not rebuild it on every call. The file lives in
 * the application's own storage and is unserialized at the same trust level as Laravel's file cache.
 */
final class IndexSnapshotStore
{
    // Bump when the shape of CodebaseIndex or anything it holds changes.
    private const FORMAT = 1;

    public function __construct(private readonly string $directory) {}

    public function read(string $root, string $fingerprint): ?CodebaseIndex
    {
        $contents = @file_get_contents($this->file($root));
        if ($contents === false) {
            return null;
        }

        $header = $this->header($fingerprint);
        if (!str_starts_with($contents, $header)) {
            return null;
        }

        $index = @unserialize(substr($contents, strlen($header)));

        return $index instanceof CodebaseIndex ? $index : null;
    }

    public function write(string $root, string $fingerprint, CodebaseIndex $index): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return;
        }

        $temporary = @tempnam($this->directory, 'index-');
        if ($temporary === false) {
            return;
        }

        // Rename is atomic within a directory: a concurrent reader sees the old or the new snapshot, never half of one.
        if (@file_put_contents($temporary, $this->header($fingerprint) . serialize($index)) === false
            || !@rename($temporary, $this->file($root))) {
            @unlink($temporary);
        }
    }

    private function header(string $fingerprint): string
    {
        return 'agent-kit-index:' . self::FORMAT . ':' . McpServerFactory::version() . ':' . $fingerprint . "\n";
    }

    private function file(string $root): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . sha1($root) . '.idx';
    }
}
