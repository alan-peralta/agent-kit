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
        self::assertSame('127.0.0.1', $config['http']['host']);
        self::assertSame(8787, $config['http']['port']);
        self::assertSame('/mcp', $config['http']['path']);
        self::assertFalse($config['http']['allow_remote']);
        self::assertSame('', $config['http']['allowed_origins']);
        self::assertNull($config['http']['bearer_token']);
        self::assertSame(1048576, $config['http']['max_body_bytes']);
        self::assertSame(60, $config['http']['idle_timeout']);
        self::assertSame(4, $config['http']['max_concurrent_requests']);
        self::assertSame(3600, $config['http']['session_ttl']);
        self::assertSame(100, $config['http']['max_sessions']);
        self::assertSame(1, $config['index_cache']['max_entries']);
        self::assertSame('info', $config['logging']['level']);
        self::assertNull($config['logging']['channel']);
    }

    public function test_the_sdk_is_installed(): void
    {
        self::assertTrue(class_exists(\Mcp\Server::class));
        self::assertTrue(class_exists(\React\Http\HttpServer::class), 'react/http must be a dev dependency so the HTTP listener is tested.');
    }
}
