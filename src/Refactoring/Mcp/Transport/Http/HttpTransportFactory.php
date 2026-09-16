<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport\Http;

use GuzzleHttp\Psr7\HttpFactory;
use Mcp\Server;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class HttpTransportFactory
{
    public function __construct(
        private readonly HttpServerOptions $options,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public static function fromOptions(HttpServerOptions $options): self
    {
        $factory = new HttpFactory();

        return new self($options, $factory, $factory);
    }

    /**
     * @return list<MiddlewareInterface> outermost first: CORS, Origin/Host allowlist, bearer auth.
     *
     * Bearer authentication is omitted only for `OPTIONS`: a CORS preflight never
     * carries an `Authorization` header (browsers strip credentials from preflight
     * requests by design), and the SDK answers `OPTIONS` with an empty 204 before
     * any JSON-RPC processing ever runs. CORS and the Origin/Host allowlist always
     * apply, `OPTIONS` included.
     */
    public function middleware(string $method): array
    {
        $middleware = [
            new CorsMiddleware(),
            new DnsRebindingProtectionMiddleware($this->options->allowedHosts, $this->responses, $this->streams),
        ];

        if ($method !== 'OPTIONS') {
            $middleware[] = new BearerTokenAuthenticationMiddleware(
                new StaticBearerTokenValidator($this->options->bearerToken),
                $this->responses,
                $this->streams,
            );
        }

        return $middleware;
    }

    public function create(ServerRequestInterface $request, LoggerInterface $logger): StreamableHttpTransport
    {
        return new StreamableHttpTransport(
            $request,
            $this->responses,
            $this->streams,
            $logger,
            $this->middleware($request->getMethod()),
            $this->options->maxBodyBytes,
        );
    }

    public function handle(Server $server, ServerRequestInterface $request, LoggerInterface $logger): ResponseInterface
    {
        if ($request->getUri()->getPath() !== $this->options->path) {
            return $this->json(404, ['error' => 'not_found', 'message' => 'The MCP endpoint is ' . $this->options->path . '.']);
        }

        try {
            $response = $server->run($this->create($request, $logger));
            if (!$response instanceof ResponseInterface) {
                throw new \UnexpectedValueException('The MCP transport did not produce a PSR-7 response.');
            }

            return $response;
        } catch (Throwable $exception) {
            $logger->error('Unhandled error while serving an MCP HTTP request.', ['exception' => $exception]);

            return $this->json(500, ['error' => 'internal_error', 'message' => 'The MCP server could not process the request.']);
        }
    }

    private function json(int $status, array $payload): ResponseInterface
    {
        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream(json_encode($payload, JSON_THROW_ON_ERROR)));
    }
}
