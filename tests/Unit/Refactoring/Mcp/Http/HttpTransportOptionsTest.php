<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp\Http;

use Peralta\AgentKit\Refactoring\Mcp\McpConfigurationException;
use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\HttpTransportOptions;
use PHPUnit\Framework\TestCase;

final class HttpTransportOptionsTest extends TestCase
{
    private const TOKEN = 'test-token-0123456789abcdef0123456789abcdef';

    public function test_defaults_carry_the_configured_limits(): void
    {
        $options = HttpTransportOptions::fromConfig($this->config());

        self::assertSame('/mcp', $options->path);
        self::assertFalse($options->allowRemote);
        self::assertSame(['localhost', '127.0.0.1', '[::1]'], $options->allowedHosts);
        self::assertSame([], $options->allowedOrigins);
        self::assertSame(self::TOKEN, $options->bearerToken);
        self::assertSame(1048576, $options->maxBodyBytes);
        self::assertSame(3600, $options->sessionTtl);
        self::assertNull($options->cacheStore);
        self::assertSame(120, $options->timeLimit);
    }

    public function test_fromconfig_no_longer_checks_enabled(): void
    {
        $options = HttpTransportOptions::fromConfig($this->config(['enabled' => false]));

        self::assertSame('/mcp', $options->path);
    }

    public function test_short_tokens_are_rejected(): void
    {
        $this->expectException(McpConfigurationException::class);
        $this->expectExceptionMessage('at least 32 characters');
        HttpTransportOptions::fromConfig($this->config(['bearer_token' => 'too-short']));
    }

    public function test_missing_token_is_rejected(): void
    {
        $this->expectException(McpConfigurationException::class);
        $this->expectExceptionMessage('AGENT_KIT_MCP_BEARER_TOKEN');
        HttpTransportOptions::fromConfig($this->config(['bearer_token' => null]));
    }

    public function test_positive_integers_are_validated(): void
    {
        foreach ([
            ['max_body_bytes' => 0],
            ['session_ttl' => 0],
        ] as $override) {
            try {
                HttpTransportOptions::fromConfig($this->config($override));
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
            HttpTransportOptions::parseAllowedOrigins(' http://LOCALHOST:6274 , mcp.internal:9000, [::1]:8787,, https://claude.example/ '),
        );

        $options = HttpTransportOptions::fromConfig($this->config(['allowed_origins' => 'http://localhost:6274,tools.internal']));
        self::assertSame(['localhost', '127.0.0.1', '[::1]', 'tools.internal'], $options->allowedHosts);
    }

    public function test_only_entries_with_a_scheme_become_cors_origins(): void
    {
        self::assertSame(
            ['http://localhost:6274', 'https://claude.example'],
            HttpTransportOptions::parseOrigins(' http://LOCALHOST:6274 , mcp.internal:9000, https://claude.example/ '),
        );

        $options = HttpTransportOptions::fromConfig($this->config(['allowed_origins' => 'http://localhost:6274,tools.internal']));
        self::assertSame(['http://localhost:6274'], $options->allowedOrigins);
        self::assertContains('localhost', $options->allowedHosts);
        self::assertContains('tools.internal', $options->allowedHosts);

        self::assertSame([], HttpTransportOptions::fromConfig($this->config())->allowedOrigins);
    }

    public function test_the_endpoint_path_is_normalised(): void
    {
        self::assertSame('/mcp', HttpTransportOptions::fromConfig($this->config(['path' => '/mcp/']))->path);
        self::assertSame('/mcp', HttpTransportOptions::fromConfig($this->config(['path' => 'mcp']))->path);
        self::assertSame('/mcp', HttpTransportOptions::normalizePath('mcp/'));
    }

    public function test_an_empty_or_root_only_path_falls_back_to_mcp_instead_of_the_site_root(): void
    {
        self::assertSame('/mcp', HttpTransportOptions::normalizePath(''));
        self::assertSame('/mcp', HttpTransportOptions::normalizePath('/'));
        self::assertSame('/mcp', HttpTransportOptions::normalizePath('//'));
        self::assertSame('/mcp', HttpTransportOptions::fromConfig($this->config(['path' => '']))->path);
        self::assertSame('/mcp', HttpTransportOptions::fromConfig($this->config(['path' => '/']))->path);
    }

    public function test_the_app_urls_host_is_allowed_but_is_not_a_cors_origin(): void
    {
        $options = HttpTransportOptions::fromConfig($this->config(), appUrl: 'https://myapp.test');

        self::assertContains('myapp.test', $options->allowedHosts);
        self::assertSame([], $options->allowedOrigins);
    }

    public function test_cache_store_blank_and_null_both_mean_the_default_store(): void
    {
        self::assertNull(HttpTransportOptions::fromConfig($this->config(['cache_store' => '']))->cacheStore);
        self::assertNull(HttpTransportOptions::fromConfig($this->config(['cache_store' => null]))->cacheStore);
        self::assertSame('file', HttpTransportOptions::fromConfig($this->config(['cache_store' => 'file']))->cacheStore);
    }

    public function test_time_limit_defaults_to_120_and_zero_is_allowed(): void
    {
        self::assertSame(120, HttpTransportOptions::fromConfig($this->config())->timeLimit);
        self::assertSame(0, HttpTransportOptions::fromConfig($this->config(['time_limit' => 0]))->timeLimit);
    }

    public function test_a_negative_time_limit_is_rejected(): void
    {
        $this->expectException(McpConfigurationException::class);
        $this->expectExceptionMessage('AGENT_KIT_MCP_HTTP_TIME_LIMIT must be zero or a positive integer, got -1.');
        HttpTransportOptions::fromConfig($this->config(['time_limit' => -1]));
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'path' => '/mcp',
            'allow_remote' => false,
            'allowed_origins' => '',
            'bearer_token' => self::TOKEN,
            'max_body_bytes' => 1048576,
            'session_ttl' => 3600,
            'cache_store' => null,
            'time_limit' => 120,
        ], $overrides);
    }
}
