<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Commands;

use Peralta\AgentKit\Refactoring\Application\CapabilityException;
use Peralta\AgentKit\Refactoring\Commands\Support\AtomicAuditReportWriter;
use Peralta\AgentKit\Refactoring\Commands\Support\ReportFilesystem;
use PHPUnit\Framework\TestCase;

final class AtomicAuditReportWriterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/agent-kit-writer-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);

        parent::tearDown();
    }

    public function test_failure_after_install_rolls_back_originals_and_cleans_temporary_files(): void
    {
        $this->writeOriginalReports();
        $filesystem = new FaultInjectingReportFilesystem(failedMoveCalls: [4]);
        $writer = new AtomicAuditReportWriter($filesystem);

        try {
            $writer->write($this->directory, $this->replacementReports());
            self::fail('Expected report installation to fail.');
        } catch (CapabilityException $exception) {
            self::assertSame('OUTPUT_WRITE_FAILED', $exception->errorCode);
        }

        self::assertSame('old audit', file_get_contents($this->directory . '/audit.json'));
        self::assertSame('old markdown', file_get_contents($this->directory . '/audit.md'));
        self::assertSame('old baseline', file_get_contents($this->directory . '/baseline.json'));
        self::assertSame([], glob($this->directory . '/.agent-kit-*') ?: []);
    }

    public function test_restore_failure_preserves_backup_and_reports_its_recovery_path(): void
    {
        $this->writeOriginalReports();
        $filesystem = new FaultInjectingReportFilesystem(failedMoveCalls: [4, 5]);
        $writer = new AtomicAuditReportWriter($filesystem);

        try {
            $writer->write($this->directory, $this->replacementReports());
            self::fail('Expected report installation to fail.');
        } catch (CapabilityException $exception) {
            $backup = $this->directory . '/.agent-kit-backup-2';
            self::assertSame('OUTPUT_WRITE_FAILED', $exception->errorCode);
            self::assertStringContainsString('Recovery backup preserved at ' . $backup, $exception->getMessage());
            self::assertFileExists($backup);
            self::assertSame('old markdown', file_get_contents($backup));
        }

        self::assertSame('old audit', file_get_contents($this->directory . '/audit.json'));
        self::assertFileDoesNotExist($this->directory . '/audit.md');
        self::assertSame('old baseline', file_get_contents($this->directory . '/baseline.json'));
        self::assertSame(
            [$this->directory . '/.agent-kit-backup-2'],
            glob($this->directory . '/.agent-kit-*') ?: [],
        );
    }

    private function writeOriginalReports(): void
    {
        file_put_contents($this->directory . '/audit.json', 'old audit');
        file_put_contents($this->directory . '/audit.md', 'old markdown');
        file_put_contents($this->directory . '/baseline.json', 'old baseline');
    }

    /** @return array<string, string> */
    private function replacementReports(): array
    {
        return [
            'audit.json' => 'new audit',
            'audit.md' => 'new markdown',
            'baseline.json' => 'new baseline',
        ];
    }
}

final class FaultInjectingReportFilesystem implements ReportFilesystem
{
    private int $moveCalls = 0;

    private int $backupPaths = 0;

    /** @param list<int> $failedMoveCalls */
    public function __construct(private readonly array $failedMoveCalls) {}

    public function exists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($path);
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
        return mkdir($path, 0777, true);
    }

    public function createTemporaryFile(string $directory): string|false
    {
        return tempnam($directory, '.agent-kit-report-');
    }

    public function uniqueBackupPath(string $directory): string
    {
        return $directory . '/.agent-kit-backup-' . ++$this->backupPaths;
    }

    public function write(string $path, string $contents): int|false
    {
        return file_put_contents($path, $contents, LOCK_EX);
    }

    public function move(string $from, string $to): bool
    {
        $this->moveCalls++;
        if (in_array($this->moveCalls, $this->failedMoveCalls, true)) {
            return false;
        }

        return rename($from, $to);
    }

    public function delete(string $path): bool
    {
        return unlink($path);
    }
}
