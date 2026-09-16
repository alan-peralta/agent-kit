<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring;

use Peralta\AgentKit\Refactoring\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

final class ProjectRootTest extends TestCase
{
    public function test_it_preserves_filesystem_roots_and_normalizes_ordinary_paths(): void
    {
        $this->assertSame(realpath(DIRECTORY_SEPARATOR), ProjectRoot::normalize(DIRECTORY_SEPARATOR));
        $this->assertSame('C:\\', ProjectRoot::normalize('C:\\'));
        $this->assertSame((string) realpath(sys_get_temp_dir()), ProjectRoot::normalize(sys_get_temp_dir() . '/'));
    }

    public function test_it_relativizes_a_child_of_the_filesystem_root(): void
    {
        $root = (string) realpath(DIRECTORY_SEPARATOR);
        $child = $root . (str_ends_with($root, DIRECTORY_SEPARATOR) ? '' : DIRECTORY_SEPARATOR)
            . 'tmp' . DIRECTORY_SEPARATOR . 'RootChild.php';

        $this->assertSame('tmp/RootChild.php', ProjectRoot::relative($root, $child));
        $this->assertTrue(ProjectRoot::contains($root, $child));
    }

    public function test_it_uses_the_existing_separator_for_a_windows_drive_root(): void
    {
        $this->assertTrue(ProjectRoot::contains('C:\\', 'C:\\src\\RootChild.php'));
        $this->assertSame('src/RootChild.php', ProjectRoot::relative('C:\\', 'C:\\src\\RootChild.php'));
    }
}
