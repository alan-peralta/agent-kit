<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport\Http;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Mcp\Server;
use Mcp\Server\Session\Psr16SessionStore;
use Peralta\AgentKit\Exceptions\MissingDependencyException;
use Peralta\AgentKit\Refactoring\Mcp\LevelFilteringLogger;
use Peralta\AgentKit\Refactoring\Mcp\McpProjectRoot;
use Peralta\AgentKit\Refactoring\Mcp\McpServerFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
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
            // mcp/sdk is only suggested; a route enabled without it is a deployment misconfiguration.
            if (!class_exists(Server::class)) {
                throw MissingDependencyException::forFeature('The MCP server', 'mcp/sdk');
            }

            $options = HttpTransportOptions::fromConfig((array) ($config['http'] ?? []), (string) config('app.url', ''));
            $root = McpProjectRoot::fromPath((string) (($config['project_root'] ?? null) ?: base_path()));
            // An unknown store name throws here too: a configuration error, hence 503.
            $sessions = new Psr16SessionStore($this->cache->store($options->cacheStore), 'agent-kit-mcp-session-', $options->sessionTtl);
        } catch (Throwable $exception) {
            $logger->error('The MCP HTTP transport is misconfigured.', ['reason' => $exception->getMessage()]);

            return new JsonResponse(['error' => 'misconfigured', 'message' => 'The MCP HTTP transport is not configured correctly; see the application log.'], 503);
        }

        if (!$options->allowRemote && !self::isLoopback((string) $request->ip())) {
            return new JsonResponse(['error' => 'forbidden', 'message' => 'The MCP HTTP transport only accepts loopback clients. Set AGENT_KIT_MCP_ALLOW_REMOTE=true to accept others.'], 403);
        }

        $savedTimeLimit = null;
        if ($options->timeLimit > 0) {
            $savedTimeLimit = ini_get('max_execution_time');
            set_time_limit($options->timeLimit);
        }

        try {
            $server = $this->servers->create($root, $logger, $sessions);

            return LaravelPsrBridge::toLaravelResponse(
                HttpTransportFactory::fromOptions($options)->handle($server, LaravelPsrBridge::toPsrRequest($request), $logger),
                $logger,
            );
        } catch (Throwable $exception) {
            $logger->error('Unhandled error while serving an MCP HTTP request.', ['exception' => $exception]);

            return new JsonResponse(['error' => 'internal_error', 'message' => 'The MCP server could not process the request.'], 500);
        } finally {
            // A long-lived worker (Octane) must not keep this request's limit for the next one.
            if ($savedTimeLimit !== null) {
                set_time_limit((int) $savedTimeLimit);
            }
        }
    }

    /** stdout is not the wire here, so without a configured channel the application's default log is the right place. */
    private function logger(array $config): LoggerInterface
    {
        $channel = $config['channel'] ?? null;
        if (is_string($channel) && $channel !== '') {
            return $this->log->channel($channel);
        }

        return new LevelFilteringLogger($this->log->channel(), (string) (($config['level'] ?? null) ?: LogLevel::INFO));
    }

    private static function isLoopback(string $ip): bool
    {
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $ip = substr($ip, 7);
        }

        return $ip === '::1' || (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($ip, '127.'));
    }
}
