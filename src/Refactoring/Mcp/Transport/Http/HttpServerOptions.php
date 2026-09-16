<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport\Http;

use Peralta\AgentKit\Refactoring\Mcp\McpConfigurationException;

final readonly class HttpServerOptions
{
    public const MIN_TOKEN_LENGTH = 32;

    private const LOOPBACK_HOSTS = ['127.0.0.1', '::1', '[::1]', 'localhost'];
    private const DEFAULT_ALLOWED_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /** @param list<string> $allowedHosts */
    private function __construct(
        public string $host,
        public int $port,
        public string $path,
        public bool $allowRemote,
        public array $allowedHosts,
        public string $bearerToken,
        public int $maxBodyBytes,
        public int $idleTimeout,
        public int $maxConcurrentRequests,
        public int $sessionTtl,
        public int $maxSessions,
    ) {}

    /** @param array<string, mixed> $config the agent-kit.mcp.http array */
    public static function fromConfig(array $config, ?string $host = null, ?int $port = null, bool $allowRemote = false): self
    {
        if (!($config['enabled'] ?? false)) {
            throw new McpConfigurationException('The MCP HTTP transport is disabled. Set AGENT_KIT_MCP_HTTP_ENABLED=true to enable it.');
        }

        $host = self::hostOf((string) ($host ?? $config['host'] ?? '127.0.0.1'));
        $allowRemote = $allowRemote || (bool) ($config['allow_remote'] ?? false);
        if (!in_array($host, self::LOOPBACK_HOSTS, true) && !$allowRemote) {
            throw new McpConfigurationException(
                "Refusing to bind the MCP HTTP transport to {$host}: pass --allow-remote (or set AGENT_KIT_MCP_ALLOW_REMOTE=true) to expose it beyond loopback.",
            );
        }

        $token = (string) ($config['bearer_token'] ?? '');
        if (strlen($token) < self::MIN_TOKEN_LENGTH) {
            throw new McpConfigurationException(
                'The MCP HTTP transport requires AGENT_KIT_MCP_BEARER_TOKEN with at least 32 characters. Generate one with: php -r \'echo bin2hex(random_bytes(32));\'',
            );
        }

        $port = $port ?? (int) ($config['port'] ?? 8787);
        if ($port < 1 || $port > 65535) {
            throw new McpConfigurationException("The MCP HTTP port must be between 1 and 65535, got {$port}.");
        }

        $allowedHosts = array_values(array_unique(array_merge(
            self::DEFAULT_ALLOWED_HOSTS,
            self::parseAllowedOrigins((string) ($config['allowed_origins'] ?? '')),
            $allowRemote ? [$host] : [],
        )));

        return new self(
            host: $host,
            port: $port,
            path: '/' . ltrim((string) ($config['path'] ?? '/mcp'), '/'),
            allowRemote: $allowRemote,
            allowedHosts: $allowedHosts,
            bearerToken: $token,
            maxBodyBytes: self::positive($config, 'max_body_bytes', 'AGENT_KIT_MCP_HTTP_MAX_BODY_BYTES'),
            idleTimeout: self::positive($config, 'idle_timeout', 'AGENT_KIT_MCP_HTTP_IDLE_TIMEOUT'),
            maxConcurrentRequests: self::positive($config, 'max_concurrent_requests', 'AGENT_KIT_MCP_HTTP_MAX_CONCURRENT'),
            sessionTtl: self::positive($config, 'session_ttl', 'AGENT_KIT_MCP_HTTP_SESSION_TTL'),
            maxSessions: self::positive($config, 'max_sessions', 'AGENT_KIT_MCP_HTTP_MAX_SESSIONS'),
        );
    }

    public function bindUri(): string
    {
        // React\Socket\SocketServer binds IP literals only; `localhost` (accepted above as loopback) is a name, not one.
        $host = $this->host === 'localhost' ? '127.0.0.1' : $this->host;
        $host = str_contains($host, ':') && !str_starts_with($host, '[') ? "[{$host}]" : $host;

        return $host . ':' . $this->port;
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
