<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring;

use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyGraph;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndex;
use Peralta\AgentKit\Refactoring\Analysis\Index\IndexSnapshotStore;
use PHPUnit\Framework\TestCase;

final class IndexSnapshotStoreTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->directories) as $directory) {
            $this->removeDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_a_written_snapshot_is_read_back_equal_for_the_same_root_and_fingerprint(): void
    {
        $store = new IndexSnapshotStore($this->directory());
        $index = $this->index();

        $store->write('/project', 'fingerprint-a', $index);

        self::assertEquals($index, $store->read('/project', 'fingerprint-a'));
    }

    public function test_a_different_fingerprint_reads_null(): void
    {
        $store = new IndexSnapshotStore($this->directory());
        $store->write('/project', 'fingerprint-a', $this->index());

        self::assertNull($store->read('/project', 'fingerprint-b'));
    }

    public function test_a_different_root_reads_null(): void
    {
        $store = new IndexSnapshotStore($this->directory());
        $store->write('/project-a', 'fingerprint', $this->index());

        self::assertNull($store->read('/project-b', 'fingerprint'));
    }

    public function test_a_different_context_reads_null(): void
    {
        $directory = $this->directory();
        $index = $this->index();
        (new IndexSnapshotStore($directory, 'context-a'))->write('/project', 'fingerprint', $index);

        self::assertNull((new IndexSnapshotStore($directory, 'context-b'))->read('/project', 'fingerprint'));
        self::assertEquals($index, (new IndexSnapshotStore($directory, 'context-a'))->read('/project', 'fingerprint'));
    }

    public function test_a_file_without_a_header_line_reads_null(): void
    {
        $directory = $this->directory();
        mkdir($directory, 0777, true);
        file_put_contents($directory . '/' . sha1('/project') . '.idx', str_repeat('x', 65536));

        self::assertNull((new IndexSnapshotStore($directory))->read('/project', 'fingerprint'));
    }

    public function test_the_snapshot_file_mode_follows_the_umask(): void
    {
        $directory = $this->directory();
        $previous = umask(0002);

        try {
            (new IndexSnapshotStore($directory))->write('/project', 'fingerprint', $this->index());
        } finally {
            umask($previous);
        }

        clearstatcache();
        self::assertSame(0664, fileperms($directory . '/' . sha1('/project') . '.idx') & 0777);
    }

    public function test_a_successful_write_removes_temporary_files_older_than_an_hour(): void
    {
        $directory = $this->directory();
        mkdir($directory, 0777, true);
        touch($directory . '/index-stale', time() - 7200);
        touch($directory . '/index-fresh');
        touch($directory . '/unrelated-stale', time() - 7200);

        (new IndexSnapshotStore($directory))->write('/project', 'fingerprint', $this->index());

        self::assertFileDoesNotExist($directory . '/index-stale');
        self::assertFileExists($directory . '/index-fresh');
        self::assertFileExists($directory . '/unrelated-stale');
        self::assertFileExists($directory . '/' . sha1('/project') . '.idx');
    }

    public function test_a_corrupt_snapshot_reads_null_without_a_warning(): void
    {
        $directory = $this->directory();
        $store = new IndexSnapshotStore($directory);
        $store->write('/project', 'fingerprint', $this->index());

        // Keep the header (it carries the format/version/fingerprint check) and replace the
        // serialized payload with garbage, simulating a truncated or bit-rotten snapshot file.
        $file = $directory . '/' . sha1('/project') . '.idx';
        $header = strtok((string) file_get_contents($file), "\n") . "\n";
        file_put_contents($file, $header . 'not-a-valid-serialized-payload');

        self::assertNull($store->read('/project', 'fingerprint'));
    }

    public function test_a_missing_directory_is_created_on_write(): void
    {
        $directory = $this->directory() . '/nested/deeper';
        $store = new IndexSnapshotStore($directory);
        $index = $this->index();

        $store->write('/project', 'fingerprint', $index);

        self::assertDirectoryExists($directory);
        self::assertEquals($index, $store->read('/project', 'fingerprint'));
    }

    public function test_a_write_into_an_unwritable_directory_does_not_throw(): void
    {
        $directory = $this->directory();
        mkdir($directory, 0777, true);
        chmod($directory, 0555);

        if (@touch($directory . '/probe')) {
            unlink($directory . '/probe');
            chmod($directory, 0755);
            self::markTestSkipped('This platform/user can write into mode-555 directories (likely root).');
        }

        $store = new IndexSnapshotStore($directory);

        try {
            $store->write('/project', 'fingerprint', $this->index());
        } finally {
            chmod($directory, 0755);
        }

        self::assertNull($store->read('/project', 'fingerprint'));
    }

    private function index(): CodebaseIndex
    {
        return new CodebaseIndex([], new DependencyGraph());
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir() . '/agent-kit-index-snapshot-' . bin2hex(random_bytes(6));
        $this->directories[] = $directory;

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        chmod($directory, 0755);
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
