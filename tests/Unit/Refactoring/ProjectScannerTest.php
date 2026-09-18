<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring;

use Peralta\AgentKit\Refactoring\Support\PhpFileAnalyzer;
use Peralta\AgentKit\Refactoring\Support\ProjectScanner;
use PHPUnit\Framework\TestCase;

class ProjectScannerTest extends TestCase
{
    public function test_its_normalization_seam_preserves_a_filesystem_root(): void
    {
        $scanner = new ProjectScanner(new PhpFileAnalyzer());
        $method = new \ReflectionMethod($scanner, 'normalizedRoot');

        $this->assertSame(realpath(DIRECTORY_SEPARATOR), $method->invoke($scanner, DIRECTORY_SEPARATOR));
    }

    public function test_it_discovers_normalized_php_paths_without_analyzing_them(): void
    {
        $root = $this->fixtureRoot();
        mkdir($root . '/app', 0777, true);
        mkdir($root . '/vendor/pkg', 0777, true);
        file_put_contents($root . '/app/B.php', '<?php class B {}');
        file_put_contents($root . '/app/A.php', '<?php class A {}');
        file_put_contents($root . '/app/readme.txt', 'ignored');
        file_put_contents($root . '/vendor/pkg/V.php', '<?php class V {}');

        $scanner = new ProjectScanner(new PhpFileAnalyzer(), ['vendor']);
        $normalizedRoot = realpath($root);

        $this->assertSame([
            $normalizedRoot . '/app/A.php',
            $normalizedRoot . '/app/B.php',
        ], $scanner->phpFiles($root));

        $this->removeFixtureRoot($root);
    }

    public function test_it_scans_php_files_and_respects_exclusions(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-scan-' . uniqid();
        mkdir($root . '/app', 0777, true);
        mkdir($root . '/vendor/pkg', 0777, true);
        file_put_contents($root . '/app/A.php', '<?php class A {}');
        file_put_contents($root . '/vendor/pkg/B.php', '<?php class B {}');

        $scanner = new ProjectScanner(new PhpFileAnalyzer(), ['vendor']);
        $files = $scanner->scan($root);

        $this->assertCount(1, $files);
        $this->assertSame('app/A.php', str_replace('\\', '/', $files[0]->path));

        @unlink($root . '/app/A.php');
        @unlink($root . '/vendor/pkg/B.php');
        @rmdir($root . '/app');
        @rmdir($root . '/vendor/pkg');
        @rmdir($root . '/vendor');
        @rmdir($root);
    }

    public function test_it_skips_php_symlinks_whose_real_target_is_outside_the_project(): void
    {
        $parent = $this->fixtureRoot();
        $root = $parent . '/project';
        $outside = $parent . '/External.php';
        mkdir($root, 0777, true);
        file_put_contents($root . '/Internal.php', '<?php class Internal {}');
        file_put_contents($outside, '<?php class ExternalSecret {}');

        try {
            if (!function_exists('symlink') || !@symlink($outside, $root . '/Linked.php')) {
                $this->markTestSkipped('Symbolic links are not available in this environment.');
            }

            $scanner = new ProjectScanner(new PhpFileAnalyzer());
            $this->assertSame([(string) realpath($root . '/Internal.php')], $scanner->phpFiles($root));
            $this->assertSame(['Internal.php'], array_column($scanner->scan($root), 'path'));
        } finally {
            if (is_link($root . '/Linked.php')) {
                unlink($root . '/Linked.php');
            }
            unlink($root . '/Internal.php');
            unlink($outside);
            rmdir($root);
            rmdir($parent);
        }
    }

    public function test_it_never_descends_into_an_excluded_directory(): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            $this->markTestSkipped('Needs a filesystem that enforces directory permissions for this user.');
        }

        // An unreadable directory makes the traversal throw as soon as it is opened, so the
        // scan only succeeds if excluded trees (one and two segments deep) are never entered.
        $root = $this->fixtureRoot();
        mkdir($root . '/app', 0777, true);
        mkdir($root . '/vendor/locked', 0777, true);
        mkdir($root . '/bootstrap/cache/locked', 0777, true);
        file_put_contents($root . '/app/A.php', '<?php class A {}');
        file_put_contents($root . '/bootstrap/app.php', '<?php return 1;');
        chmod($root . '/vendor/locked', 0);
        chmod($root . '/bootstrap/cache/locked', 0);

        try {
            $scanner = new ProjectScanner(new PhpFileAnalyzer(), ['vendor', 'bootstrap/cache']);
            $normalizedRoot = realpath($root);

            $this->assertSame([
                $normalizedRoot . '/app/A.php',
                $normalizedRoot . '/bootstrap/app.php',
            ], $scanner->phpFiles($root));
        } finally {
            chmod($root . '/vendor/locked', 0777);
            chmod($root . '/bootstrap/cache/locked', 0777);
            $this->removeFixtureRoot($root);
        }
    }

    private function fixtureRoot(): string
    {
        return sys_get_temp_dir() . '/agent-kit-scan-' . bin2hex(random_bytes(6));
    }

    private function removeFixtureRoot(string $root): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($root);
    }
}
