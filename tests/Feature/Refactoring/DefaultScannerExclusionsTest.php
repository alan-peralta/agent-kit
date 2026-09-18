<?php

namespace Peralta\AgentKit\Tests\Feature\Refactoring;

use Peralta\AgentKit\Refactoring\Support\ProjectScanner;
use Peralta\AgentKit\Tests\TestCase;

final class DefaultScannerExclusionsTest extends TestCase
{
    public function test_the_default_configuration_skips_claude_and_git_worktrees(): void
    {
        // Coding agents keep full checkouts of the project (vendor included) under these
        // directories; scanning them multiplies the work and duplicates every class.
        $root = sys_get_temp_dir() . '/agent-kit-default-exclusions-' . bin2hex(random_bytes(6));
        mkdir($root . '/app', 0777, true);
        mkdir($root . '/.claude/worktrees/feature/app', 0777, true);
        mkdir($root . '/.worktrees/feature/app', 0777, true);
        file_put_contents($root . '/app/A.php', '<?php class A {}');
        file_put_contents($root . '/.claude/worktrees/feature/app/A.php', '<?php class A {}');
        file_put_contents($root . '/.worktrees/feature/app/A.php', '<?php class A {}');

        try {
            $files = $this->app->make(ProjectScanner::class)->phpFiles($root);

            self::assertSame([realpath($root) . '/app/A.php'], $files);
        } finally {
            foreach (['/.claude/worktrees/feature/app/A.php', '/.worktrees/feature/app/A.php', '/app/A.php'] as $file) {
                unlink($root . $file);
            }
            foreach (['/.claude/worktrees/feature/app', '/.claude/worktrees/feature', '/.claude/worktrees', '/.claude', '/.worktrees/feature/app', '/.worktrees/feature', '/.worktrees', '/app', ''] as $directory) {
                rmdir($root . $directory);
            }
        }
    }
}
