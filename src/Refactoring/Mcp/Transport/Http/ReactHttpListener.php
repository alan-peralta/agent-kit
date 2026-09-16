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
    /** Bind hosts that accept connections on every interface, as HttpServerOptions normalises them. */
    private const WILDCARD_HOSTS = ['0.0.0.0', '[::]'];

    /**
     * Listener-wide "something was served at" timestamp, bumped whenever a request handler
     * returns. Tool execution blocks the single-threaded loop, so a call that runs longer
     * than the idle timeout has no `data` event to prove the connection is alive; without
     * this the idle timer expires mid-call and the response it produced is thrown away.
     */
    private float $lastActivity = 0.0;

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
        $this->lastActivity = microtime(true);

        $http = new HttpServer(
            $loop,
            new StreamingRequestMiddleware(),
            new LimitConcurrentRequestsMiddleware($options->maxConcurrentRequests),
            $this->rejectOversizedBodies($options->maxBodyBytes),
            new RequestBodyBufferMiddleware($options->maxBodyBytes),
            function (ServerRequestInterface $request) use ($transport, $server, $logger): ResponseInterface {
                try {
                    return $transport->handle($server, $request, $logger);
                } finally {
                    $this->lastActivity = microtime(true);
                }
            },
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

        $stop = static function (int $signal) use ($loop, $socket, $logger): void {
            $logger->info('Stopping MCP HTTP server.', ['signal' => $signal]);
            $socket->close();
            $loop->stop();
        };
        if (function_exists('pcntl_signal')) {
            $loop->addSignal(SIGINT, $stop);
            $loop->addSignal(SIGTERM, $stop);
        }

        if ($options->allowRemote && in_array($options->host, self::WILDCARD_HOSTS, true)) {
            $logger->warning('Bound to a wildcard address; clients must use a hostname or IP listed in AGENT_KIT_MCP_ALLOWED_ORIGINS or requests are answered 403.');
        }

        $logger->info('MCP HTTP server listening.', [
            'endpoint' => 'http://' . $options->bindUri() . $options->path,
            'project_root' => $root->path,
            'allowed_hosts' => $options->allowedHosts,
        ]);

        try {
            $loop->run();
        } finally {
            // Every exit path - a signal, a stopped loop or an exception escaping the loop -
            // must release the sessions and the in-memory AST index, not just the signal one.
            $sessions->clear();
            $this->indexCache->clear();
        }

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

    /**
     * A periodic check per connection, not a one-shot timer re-armed on `data`: a one-shot timer
     * armed before a slow tool call expires *while* the handler blocks the loop, ReactPHP drains
     * expired timers before the write phase, and `Connection::close()` then discards the response
     * that handler had already buffered. Comparing against a timestamp instead means an expired
     * deadline is re-evaluated rather than acted on blindly.
     *
     * Activity is the newest of this connection's last `data` event and the listener-wide
     * $lastActivity. The latter is not per-connection on purpose: mapping a response back to its
     * connection would mean matching REMOTE_ADDR/REMOTE_PORT from the request's server params,
     * and the only cost of the coarser signal is that a busy listener keeps *other* idle
     * connections open a little longer - never that a live request is cut off.
     */
    private function closeIdleConnections(SocketServer $socket, int $idleTimeout, LoopInterface $loop): void
    {
        $socket->on('connection', function (ConnectionInterface $connection) use ($idleTimeout, $loop): void {
            $lastData = microtime(true);
            $connection->on('data', static function () use (&$lastData): void {
                $lastData = microtime(true);
            });

            $timer = $loop->addPeriodicTimer(min($idleTimeout, 1.0), function () use (&$lastData, $connection, $idleTimeout): void {
                if (microtime(true) - max($lastData, $this->lastActivity) >= $idleTimeout) {
                    $connection->close();
                }
            });
            $connection->on('close', static function () use ($timer, $loop): void {
                $loop->cancelTimer($timer);
            });
        });
    }
}
