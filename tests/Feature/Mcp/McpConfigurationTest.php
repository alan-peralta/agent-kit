<?php

namespace Peralta\AgentKit\Tests\Feature\Mcp;

use Peralta\AgentKit\Tests\TestCase;

final class McpConfigurationTest extends TestCase
{
    public function test_mcp_defaults_are_local_only_and_http_is_disabled(): void
    {
        $config = config('agent-kit.mcp');

        self::assertTrue($config['enabled']);
        self::assertSame('stdio', $config['transport']);
        self::assertNull($config['project_root']);
        self::assertFalse($config['http']['enabled']);
        self::assertSame('/mcp', $config['http']['path']);
        self::assertFalse($config['http']['allow_remote']);
        self::assertSame('', $config['http']['allowed_origins']);
        self::assertNull($config['http']['bearer_token']);
        self::assertSame(1048576, $config['http']['max_body_bytes']);
        self::assertSame(3600, $config['http']['session_ttl']);
        self::assertNull($config['http']['cache_store']);
        self::assertSame(120, $config['http']['time_limit']);
        self::assertSame(1, $config['index_cache']['max_entries']);
        self::assertSame('info', $config['logging']['level']);
        self::assertNull($config['logging']['channel']);
    }

    public function test_the_sdk_is_installed(): void
    {
        self::assertTrue(class_exists(\Mcp\Server::class));
    }

    public function test_the_package_default_for_the_index_cache_path_is_null(): void
    {
        // TestCase::getEnvironmentSetUp() overrides this to false so tests never share snapshots;
        // read the shipped config file directly to check the package's own default.
        $config = require __DIR__ . '/../../../config/agent-kit.php';

        self::assertArrayHasKey('path', $config['mcp']['index_cache']);
        self::assertNull($config['mcp']['index_cache']['path']);
    }
}
