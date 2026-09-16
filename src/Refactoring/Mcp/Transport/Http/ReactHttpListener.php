<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport\Http;

use Peralta\AgentKit\Refactoring\Analysis\Index\CachedCodebaseIndexer;
use Peralta\AgentKit\Refactoring\Mcp\McpConfigurationException;
use Peralta\AgentKit\Refactoring\Mcp\McpProjectRoot;
use Peralta\AgentKit\Refactoring\Mcp\McpServerFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use Throwable;

final class ReactHttpListener
{
    public function __construct(
        private readonly McpServerFactory $factory,
        private readonly CachedCodebaseIndexer $indexCache,
    ) {}

    public function listen(HttpServerOptions $options, McpProjectRoot $root, LoggerInterface $logger): int
    {
        if (!class_exists(HttpServer::class)) {
            throw new McpConfigurationException('The MCP HTTP transport requires react/http. Install it with: composer require react/http');
        }

        $sessions = new BoundedInMemorySessionStore($options->sessionTtl, $options->maxSessions);
        $server = $this->factory->create($root, $logger, $sessions);
        $transport = HttpTransportFactory::fromOptions($options);
        $loop = Loop::get();

        $http = new HttpServer(
            $loop,
            new StreamingRequestMiddleware(),
            new LimitConcurrentRequestsMiddleware($options->maxConcurrentRequests),
            $this->rejectOversizedBodies($options->maxBodyBytes),
            new RequestBodyBufferMiddleware($options->maxBodyBytes),
            static fn (ServerRequestInterface $request): ResponseInterface => $transport->handle($server, $request, $logger),
        );

        try {
            $socket = new SocketServer($options->bindUri(), [], $loop);
        } catch (Throwable $exception) {
            // React\Socket\SocketServer/TcpServer throw RuntimeException for a busy port or
            // permission problem, but InvalidArgumentException for a bind host that isn't an IP
            // literal (e.g. an unmapped hostname) - both must become a clean refusal, never an
            // uncaught crash.
            throw new McpConfigurationException(
                'Could not bind the MCP HTTP transport to ' . $options->bindUri() . ': ' . $exception->getMessage()
                . '. Use an IP literal for --host/AGENT_KIT_MCP_HTTP_HOST.',
            );
        }

        $this->closeIdleConnections($socket, $options->idleTimeout, $loop);
        $http->on('error', static fn (Throwable $error) => $logger->error('MCP HTTP server error.', ['exception' => $error]));
        $http->listen($socket);

        $stop = function (int $signal) use ($loop, $socket, $sessions, $logger): void {
            $logger->info('Stopping MCP HTTP server.', ['signal' => $signal]);
            $socket->close();
            $sessions->clear();
            $this->indexCache->clear();
            $loop->stop();
        };
        if (function_exists('pcntl_signal')) {
            $loop->addSignal(SIGINT, $stop);
            $loop->addSignal(SIGTERM, $stop);
        }

        $logger->info('MCP HTTP server listening.', [
            'endpoint' => 'http://' . $options->bindUri() . $options->path,
            'project_root' => $root->path,
            'allowed_hosts' => $options->allowedHosts,
        ]);
        $loop->run();

        return 0;
    }

    /**
     * `RequestBodyBufferMiddleware` never rejects an oversized body itself: per its own
     * documentation it silently discards the payload and hands the next handler an *empty*
     * body, so by the time the request reaches `HttpTransportFactory::handle()` the SDK's own
     * body-size guard sees 0 bytes and can no longer tell the request was too large. Reject
     * requests with a declared `Content-Length` over the limit here, before any buffering,
     * so oversized requests get the documented 413 instead of being silently truncated.
     */
    private function rejectOversizedBodies(int $maxBodyBytes): callable
    {
        // No return type here: on the happy path this returns whatever $next() returns, and
        // that is a React\Promise\PromiseInterface whenever a downstream middleware (namely
        // RequestBodyBufferMiddleware, for a non-empty body) buffers asynchronously - not yet
        // a ResponseInterface. Declaring ResponseInterface here would fail with a TypeError.
        return static function (ServerRequestInterface $request, callable $next) use ($maxBodyBytes) {
            $contentLength = $request->getHeaderLine('Content-Length');
            if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > $maxBodyBytes) {
                return new Response(413, ['Content-Type' => 'application/json'], json_encode([
                    'error' => 'payload_too_large',
                    'message' => "Request body exceeds the maximum allowed size of {$maxBodyBytes} bytes.",
                ], JSON_THROW_ON_ERROR));
            }

            return $next($request);
        };
    }

    private function closeIdleConnections(SocketServer $socket, int $idleTimeout, LoopInterface $loop): void
    {
        $socket->on('connection', static function (ConnectionInterface $connection) use ($idleTimeout, $loop): void {
            $timer = null;
            $arm = static function () use (&$timer, $connection, $idleTimeout, $loop): void {
                if ($timer !== null) {
                    $loop->cancelTimer($timer);
                }
                $timer = $loop->addTimer($idleTimeout, static fn () => $connection->close());
            };
            $arm();
            $connection->on('data', $arm);
            $connection->on('close', static function () use (&$timer, $loop): void {
                if ($timer !== null) {
                    $loop->cancelTimer($timer);
                }
            });
        });
    }
}
