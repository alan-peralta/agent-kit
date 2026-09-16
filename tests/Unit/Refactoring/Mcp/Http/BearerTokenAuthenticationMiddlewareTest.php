<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp\Http;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\BearerTokenAuthenticationMiddleware;
use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\StaticBearerTokenValidator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class BearerTokenAuthenticationMiddlewareTest extends TestCase
{
    private const TOKEN = 'test-token-0123456789abcdef0123456789abcdef';

    public function test_a_valid_bearer_token_reaches_the_handler(): void
    {
        $response = $this->process(['Authorization' => 'Bearer ' . self::TOKEN]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('handled', (string) $response->getBody());
    }

    public function test_a_missing_header_is_401_with_a_bearer_challenge(): void
    {
        $response = $this->process([]);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringStartsWith('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('unauthorized', json_decode((string) $response->getBody(), true)['error']);
    }

    public function test_an_invalid_token_is_401_and_never_echoed(): void
    {
        $response = $this->process(['Authorization' => 'Bearer wrong-' . self::TOKEN]);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('error="invalid_token"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertStringNotContainsString(self::TOKEN, (string) $response->getBody());
        self::assertStringNotContainsString(self::TOKEN, $response->getHeaderLine('WWW-Authenticate'));
    }

    public function test_a_malformed_scheme_is_400(): void
    {
        self::assertSame(400, $this->process(['Authorization' => 'Basic abc'])->getStatusCode());
        self::assertSame(400, $this->process(['Authorization' => 'Bearer'])->getStatusCode());
    }

    public function test_a_token_in_the_query_string_is_ignored(): void
    {
        $response = $this->process([], 'http://127.0.0.1:8787/mcp?access_token=' . self::TOKEN);

        self::assertSame(401, $response->getStatusCode());
    }

    private function process(array $headers, string $uri = 'http://127.0.0.1:8787/mcp'): ResponseInterface
    {
        $factory = new HttpFactory();
        $middleware = new BearerTokenAuthenticationMiddleware(new StaticBearerTokenValidator(self::TOKEN), $factory, $factory);
        $handler = new class($factory) implements RequestHandlerInterface {
            public function __construct(private readonly HttpFactory $factory) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200)->withBody($this->factory->createStream('handled'));
            }
        };

        return $middleware->process(new ServerRequest('POST', $uri, $headers), $handler);
    }
}
