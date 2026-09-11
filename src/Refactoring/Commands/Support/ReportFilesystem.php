<?php

namespace Peralta\AgentKit\Refactoring\Commands\Support;

interface ReportFilesystem
{
    public function exists(string $path): bool;

    public function isDirectory(string $path): bool;

    public function isLink(string $path): bool;

    public function isRegularFile(string $path): bool;

    public function isWritable(string $path): bool;

    public function makeDirectory(string $path): bool;

    public function realPath(string $path): string|false;

    public function createTemporaryFile(string $directory): string|false;

    public function uniqueBackupPath(string $directory): string;

    public function write(string $path, string $contents): int|false;

    public function move(string $from, string $to): bool;

    public function delete(string $path): bool;
}
