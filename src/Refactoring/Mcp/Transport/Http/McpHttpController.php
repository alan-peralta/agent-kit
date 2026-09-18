<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport\Http;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Mcp\Server\Session\Psr16SessionStore;
use Peralta\AgentKit\Refactoring\Mcp\McpProjectRoot;
use Peralta\AgentKit\Refactoring\Mcp\McpServerFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class McpHttpController
{
    public function __construct(
        private readonly McpServerFactory $servers,
        private readonly CacheFactory $cache,
        private readonly LogManager $log,
    ) {}

    public function __invoke(Request $request): Response
    {
        $config = (array) config('agent-kit.mcp', []);
        $logger = $this->logger((array) ($config['logging'] ?? []));

        try {
            $options = HttpTransportOptions::fromConfig((array) ($config['http'] ?? []), (string) config('app.url', ''));
            $root = McpProjectRoot::fromPath((string) (($config['project_root'] ?? null) ?: base_path()));
            // Illuminate\Contracts\Cache\Repository (what CacheFactory::store() returns) already
            // implements PSR-16's CacheInterface, so it satisfies Psr16SessionStore without an
            // adapter. An unknown store name throws here too (InvalidArgumentException) - that is
            // as much a deployment misconfiguration as a bad token or project root, and must not
            // reach an unauthenticated client as a raw exception.
            $sessions = new Psr16SessionStore($this->cache->store($options->cacheStore), 'agent-kit-mcp-session-', $options->sessionTtl);
        } catch (Throwable $exception) {
            $logger->error('The MCP HTTP transport is misconfigured.', ['reason' => $exception->getMessage()]);

            return new JsonResponse(['error' => 'misconfigured', 'message' => 'The MCP HTTP transport is not configured correctly; see the application log.'], 503);
        }

        if (!$options->allowRemote && !self::isLoopback((string) $request->ip())) {
            return new JsonResponse(['error' => 'forbidden', 'message' => 'The MCP HTTP transport only accepts loopback clients. Set AGENT_KIT_MCP_ALLOW_REMOTE=true to accept others.'], 403);
        }

        if ($options->timeLimit > 0) {
            set_time_limit($options->timeLimit);
        }

        $server = $this->servers->create($root, $logger, $sessions);

        return LaravelPsrBridge::toLaravelResponse(
            HttpTransportFactory::fromOptions($options)->handle($server, LaravelPsrBridge::toPsrRequest($request), $logger),
            $logger,
        );
    }

    /** stdout is not the wire here, so without a configured channel the application's default log is the right place. */
    private function logger(array $config): LoggerInterface
    {
        $channel = $config['channel'] ?? null;

        return is_string($channel) && $channel !== '' ? $this->log->channel($channel) : $this->log->channel();
    }

    private static function isLoopback(string $ip): bool
    {
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $ip = substr($ip, 7);
        }

        return $ip === '::1' || (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($ip, '127.'));
    }
}
