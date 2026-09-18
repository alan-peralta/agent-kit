<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport\Http;

use Peralta\AgentKit\Refactoring\Mcp\McpConfigurationException;

final readonly class HttpTransportOptions
{
    public const MIN_TOKEN_LENGTH = 32;

    private const DEFAULT_ALLOWED_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * @param  list<string>  $allowedHosts  hosts the DNS-rebinding allowlist accepts
     * @param  list<string>  $allowedOrigins  full origins CORS answers with, empty = no Access-Control-Allow-Origin
     */
    private function __construct(
        public string $path,
        public bool $allowRemote,
        public array $allowedHosts,
        public array $allowedOrigins,
        public string $bearerToken,
        public int $maxBodyBytes,
        public int $sessionTtl,
        public ?string $cacheStore,
        public int $timeLimit,
    ) {}

    /**
     * @param  array<string, mixed>  $config  the agent-kit.mcp.http array
     * @param  string  $appUrl  the host application's own URL (config('app.url')); its host is
     *                           always allowed, but never becomes a CORS origin on its own
     */
    public static function fromConfig(array $config, string $appUrl = ''): self
    {
        $token = (string) ($config['bearer_token'] ?? '');
        if (strlen($token) < self::MIN_TOKEN_LENGTH) {
            throw new McpConfigurationException(
                'The MCP HTTP transport requires AGENT_KIT_MCP_BEARER_TOKEN with at least 32 characters. Generate one with: php -r \'echo bin2hex(random_bytes(32));\'',
            );
        }

        $timeLimit = (int) ($config['time_limit'] ?? 120);
        if ($timeLimit < 0) {
            throw new McpConfigurationException("AGENT_KIT_MCP_HTTP_TIME_LIMIT must be zero or a positive integer, got {$timeLimit}.");
        }

        $allowedHosts = array_values(array_unique(array_merge(
            self::DEFAULT_ALLOWED_HOSTS,
            self::parseAllowedOrigins($appUrl),
            self::parseAllowedOrigins((string) ($config['allowed_origins'] ?? '')),
        )));

        $cacheStore = trim((string) ($config['cache_store'] ?? ''));

        return new self(
            // trim() on both ends, so '/mcp/', 'mcp' and '/mcp' all normalise to '/mcp' and the
            // bare root '/' stays '/' - the endpoint comparison in HttpTransportFactory is exact.
            path: self::normalizePath((string) ($config['path'] ?? '/mcp')),
            allowRemote: (bool) ($config['allow_remote'] ?? false),
            allowedHosts: $allowedHosts,
            allowedOrigins: self::parseOrigins((string) ($config['allowed_origins'] ?? '')),
            bearerToken: $token,
            maxBodyBytes: self::positive($config, 'max_body_bytes', 'AGENT_KIT_MCP_HTTP_MAX_BODY_BYTES'),
            sessionTtl: self::positive($config, 'session_ttl', 'AGENT_KIT_MCP_HTTP_SESSION_TTL'),
            cacheStore: $cacheStore === '' ? null : $cacheStore,
            timeLimit: $timeLimit,
        );
    }

    public static function normalizePath(string $path): string
    {
        return '/' . trim($path, '/');
    }

    /** @return list<string> lower-cased hosts, ports and schemes stripped, IPv6 kept bracketed */
    public static function parseAllowedOrigins(string $origins): array
    {
        $hosts = [];
        foreach (explode(',', $origins) as $origin) {
            $host = self::hostOf(trim($origin));
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * The configured entries that are full origins (they carry a scheme), lower-cased and reduced
     * to `scheme://host[:port]`. Bare hosts are deliberately skipped: they extend the host
     * allowlist only, and CORS must name an exact origin or stay silent (spec 10.2).
     *
     * @return list<string>
     */
    public static function parseOrigins(string $origins): array
    {
        $parsed = [];
        foreach (explode(',', $origins) as $origin) {
            $origin = strtolower(trim($origin));
            if ($origin === '' || !str_contains($origin, '://')) {
                continue;
            }

            $parts = parse_url($origin);
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
                continue;
            }

            $parsed[] = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }

        return array_values(array_unique($parsed));
    }

    private static function hostOf(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        if (str_contains($value, '://')) {
            $host = parse_url($value, PHP_URL_HOST);

            return is_string($host) ? $host : '';
        }
        if (str_starts_with($value, '[')) {
            $closing = strpos($value, ']');

            return $closing === false ? '' : substr($value, 0, $closing + 1);
        }
        // A bare IPv6 literal (::1, fe80::1) has several colons and no brackets; keep it whole, bracketed.
        if (substr_count($value, ':') > 1) {
            return '[' . $value . ']';
        }

        return explode(':', $value, 2)[0];
    }

    private static function positive(array $config, string $key, string $variable): int
    {
        $value = (int) ($config[$key] ?? 0);
        if ($value < 1) {
            throw new McpConfigurationException("{$variable} must be a positive integer, got {$value}.");
        }

        return $value;
    }
}
