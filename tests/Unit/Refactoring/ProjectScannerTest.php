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
