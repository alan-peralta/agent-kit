<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp;

use Peralta\AgentKit\Refactoring\Mcp\McpConfigurationException;
use Peralta\AgentKit\Refactoring\Mcp\McpProjectRoot;
use PHPUnit\Framework\TestCase;

final class McpProjectRootTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/agent-kit-mcp-root-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/sub', 0777, true);
        file_put_contents($this->directory . '/file.php', '<?php');
    }

    protected function tearDown(): void
    {
        if (is_link($this->directory . '/link')) {
            unlink($this->directory . '/link');
        }
        unlink($this->directory . '/file.php');
        rmdir($this->directory . '/sub');
        rmdir($this->directory);
    }

    public function test_it_canonicalizes_an_existing_directory(): void
    {
        $root = McpProjectRoot::fromPath($this->directory . '/sub/../');

        self::assertSame(realpath($this->directory), $root->path);
    }

    public function test_it_resolves_symlinks_to_their_real_path(): void
    {
        if (!function_exists('symlink') || !@symlink($this->directory . '/sub', $this->directory . '/link')) {
            self::markTestSkipped('Symbolic links are not available.');
        }

        self::assertSame(realpath($this->directory . '/sub'), McpProjectRoot::fromPath($this->directory . '/link')->path);
    }

    public function test_it_rejects_a_missing_directory(): void
    {
        $this->expectException(McpConfigurationException::class);
        $this->expectExceptionMessage('does not exist or is not a directory');
        McpProjectRoot::fromPath($this->directory . '/missing');
    }

    public function test_it_rejects_a_regular_file(): void
    {
        $this->expectException(McpConfigurationException::class);
        McpProjectRoot::fromPath($this->directory . '/file.php');
    }

    public function test_it_rejects_an_empty_path(): void
    {
        $this->expectException(McpConfigurationException::class);
        $this->expectExceptionMessage('must not be empty');
        McpProjectRoot::fromPath('   ');
    }
}
