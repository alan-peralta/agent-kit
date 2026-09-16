<?php

namespace Peralta\AgentKit\Refactoring\Commands\Support;

final class NativeReportFilesystem implements ReportFilesystem
{
    public function exists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    public function isLink(string $path): bool
    {
        return is_link($path);
    }

    public function isRegularFile(string $path): bool
    {
        return is_file($path) && !is_link($path);
    }

    public function isWritable(string $path): bool
    {
        return is_writable($path);
    }

    public function makeDirectory(string $path): bool
    {
        return @mkdir($path, 0777, true);
    }

    public function realPath(string $path): string|false
    {
        return realpath($path);
    }

    public function createTemporaryFile(string $directory): string|false
    {
        return @tempnam($directory, '.agent-kit-report-');
    }

    public function uniqueBackupPath(string $directory): string
    {
        do {
            $path = $directory . DIRECTORY_SEPARATOR . '.agent-kit-backup-' . bin2hex(random_bytes(12));
        } while ($this->exists($path));

        return $path;
    }

    public function write(string $path, string $contents): int|false
    {
        return @file_put_contents($path, $contents, LOCK_EX);
    }

    public function move(string $from, string $to): bool
    {
        return @rename($from, $to);
    }

    public function delete(string $path): bool
    {
        return @unlink($path);
    }
}
