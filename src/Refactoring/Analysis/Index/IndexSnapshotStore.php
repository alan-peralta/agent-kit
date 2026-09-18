<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Index;

/**
 * One file per project root holding the latest index, so a per-request process (PHP-FPM,
 * php artisan serve, each CLI command) does not rebuild it on every call. The file lives in
 * the application's own storage and is unserialized at the same trust level as Laravel's file cache.
 */
final class IndexSnapshotStore
{
    // Bump when the shape of CodebaseIndex or anything it holds changes.
    private const FORMAT = 1;

    private const TEMPORARY_PREFIX = 'index-';

    // A writer that died between tempnam() and rename() leaves its temporary file behind.
    private const ABANDONED_AFTER_SECONDS = 3600;

    /**
     * @param  string  $context  whatever else shapes the index besides the files (parser version,
     *                           configuration); a snapshot written under another context is ignored
     */
    public function __construct(
        private readonly string $directory,
        private readonly string $context = '',
    ) {}

    public function read(string $root, string $fingerprint): ?CodebaseIndex
    {
        $handle = @fopen($this->file($root), 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            // Check the header alone first, so a stale snapshot is rejected without loading its body.
            $header = $this->header($fingerprint);
            if (fgets($handle, strlen($header) + 1) !== $header) {
                return null;
            }

            $payload = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        $index = is_string($payload) ? @unserialize($payload) : null;

        return $index instanceof CodebaseIndex ? $index : null;
    }

    public function write(string $root, string $fingerprint, CodebaseIndex $index): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return;
        }

        $temporary = @tempnam($this->directory, self::TEMPORARY_PREFIX);
        if ($temporary === false) {
            return;
        }

        // tempnam() creates the file 0600; the CLI and PHP-FPM may run as different users of one group.
        @chmod($temporary, 0666 & ~umask());

        // Rename is atomic within a directory: a concurrent reader sees the old or the new snapshot, never half of one.
        if (!$this->writeFile($temporary, $this->header($fingerprint), serialize($index))
            || !@rename($temporary, $this->file($root))) {
            @unlink($temporary);

            return;
        }

        $this->removeAbandonedTemporaryFiles();
    }

    private function writeFile(string $path, string ...$parts): bool
    {
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            return false;
        }

        $written = true;
        foreach ($parts as $part) {
            if (@fwrite($handle, $part) !== strlen($part)) {
                $written = false;

                break;
            }
        }

        return @fclose($handle) && $written;
    }

    private function removeAbandonedTemporaryFiles(): void
    {
        $threshold = time() - self::ABANDONED_AFTER_SECONDS;
        foreach (@scandir($this->directory) ?: [] as $entry) {
            if (!str_starts_with($entry, self::TEMPORARY_PREFIX)) {
                continue;
            }

            $modified = @filemtime($this->path($entry));
            if ($modified !== false && $modified < $threshold) {
                @unlink($this->path($entry));
            }
        }
    }

    private function header(string $fingerprint): string
    {
        return 'agent-kit-index:' . self::FORMAT . ':' . sha1($this->context) . ':' . $fingerprint . "\n";
    }

    private function file(string $root): string
    {
        return $this->path(sha1($root) . '.idx');
    }

    private function path(string $name): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $name;
    }
}
