<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp\Http;

use Peralta\AgentKit\Refactoring\Mcp\McpConfigurationException;
use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\HttpServerOptions;
use PHPUnit\Framework\TestCase;

final class HttpServerOptionsTest extends TestCase
{
    private const TOKEN = 'test-token-0123456789abcdef0123456789abcdef';

    public function test_defaults_bind_loopback_and_carry_the_configured_limits(): void
    {
        $options = HttpServerOptions::fromConfig($this->config());

        self::assertSame('127.0.0.1', $options->host);
        self::assertSame(8787, $options->port);
        self::assertSame('/mcp', $options->path);
        self::assertFalse($options->allowRemote);
        self::assertSame(['localhost', '127.0.0.1', '[::1]'], $options->allowedHosts);
        self::assertSame(self::TOKEN, $options->bearerToken);
        self::assertSame(1048576, $options->maxBodyBytes);
        self::assertSame(60, $options->idleTimeout);
        self::assertSame(4, $options->maxConcurrentRequests);
        self::assertSame(3600, $options->sessionTtl);
        self::assertSame(100, $options->maxSessions);
        self::assertSame('127.0.0.1:8787', $options->bindUri());
    }

    public function test_http_must_be_enabled_explicitly(): void
    {
        $this->expectException(McpConfigurationException::class);
        $this->expectExceptionMessage('AGENT_KIT_MCP_HTTP_ENABLED=true');
        HttpServerOptions::fromConfig($this->config(['enabled' => false]));
    }

    public function test_a_non_loopback_bind_requires_allow_remote(): void
    {
        $this->expectException(McpConfigurationException::class);
        $this->expectExceptionMessage('--allow-remote');
        HttpServerOptions::fromConfig($this->config(), host: '0.0.0.0');
    }

    public function test_allow_remote_adds_the_bind_host_to_the_allowlist_but_keeps_the_token_mandatory(): void
    {
        $options = HttpServerOptions::fromConfig($this->config(), host: '192.168.1.10', allowRemote: true);
        self::assertTrue($options->allowRemote);
        self::assertContains('192.168.1.10', $options->allowedHosts);

        $this->expectException(McpConfigurationException::class);
        $this->expectExceptionMessage('AGENT_KIT_MCP_BEARER_TOKEN');
        HttpServerOptions::fromConfig($this->config(['bearer_token' => null]), host: '0.0.0.0', allowRemote: true);
    }

    public function test_short_tokens_are_rejected(): void
    {
        $this->expectException(McpConfigurationException::class);
        $this->expectExceptionMessage('at least 32 characters');
        HttpServerOptions::fromConfig($this->config(['bearer_token' => 'too-short']));
    }

    public function test_ports_and_limits_are_validated(): void
    {
        foreach ([
            ['port' => 0],
            ['port' => 70000],
            ['max_body_bytes' => 0],
            ['idle_timeout' => 0],
            ['max_concurrent_requests' => 0],
            ['session_ttl' => 0],
            ['max_sessions' => 0],
        ] as $override) {
            try {
                HttpServerOptions::fromConfig($this->config($override));
                self::fail('Expected rejection for ' . json_encode($override));
            } catch (McpConfigurationException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_allowed_origins_are_reduced_to_lower_case_hosts(): void
    {
        self::assertSame(
            ['localhost', 'mcp.internal', '[::1]', 'claude.example'],
            HttpServerOptions::parseAllowedOrigins(' http://LOCALHOST:6274 , mcp.internal:9000, [::1]:8787,, https://claude.example/ '),
        );

        $options = HttpServerOptions::fromConfig($this->config(['allowed_origins' => 'http://localhost:6274,tools.internal']));
        self::assertSame(['localhost', '127.0.0.1', '[::1]', 'tools.internal'], $options->allowedHosts);
    }

    public function test_ipv6_binds_are_bracketed_in_the_bind_uri(): void
    {
        self::assertSame('[::1]:8787', HttpServerOptions::fromConfig($this->config(), host: '::1')->bindUri());
    }

    public function test_localhost_binds_the_ipv4_loopback_address(): void
    {
        $options = HttpServerOptions::fromConfig($this->config(), host: 'localhost');

        self::assertSame('127.0.0.1:8787', $options->bindUri());
        self::assertContains('localhost', $options->allowedHosts);
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'host' => '127.0.0.1',
            'port' => 8787,
            'path' => '/mcp',
            'allow_remote' => false,
            'allowed_origins' => '',
            'bearer_token' => self::TOKEN,
            'max_body_bytes' => 1048576,
            'idle_timeout' => 60,
            'max_concurrent_requests' => 4,
            'session_ttl' => 3600,
            'max_sessions' => 100,
        ], $overrides);
    }
}
