<?php

namespace Peralta\AgentKit\Tests\Feature\Mcp;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\ServerRequest;
use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Peralta\AgentKit\Refactoring\Application\RefactoringCapabilities;
use Peralta\AgentKit\Refactoring\Mcp\McpProjectRoot;
use Peralta\AgentKit\Refactoring\Mcp\McpServerFactory;
use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\HttpTransportFactory;
use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\HttpTransportOptions;
use Peralta\AgentKit\Tests\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

final class HttpTransportPipelineTest extends TestCase
{
    private const TOKEN = 'test-token-0123456789abcdef0123456789abcdef';
    private const ENDPOINT = 'http://127.0.0.1:8787/mcp';

    private Server $server;
    private HttpTransportFactory $factory;
    private InMemorySessionStore $sessions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessions = new InMemorySessionStore(3600);
        $this->server = $this->app->make(McpServerFactory::class)->create(
            McpProjectRoot::fromPath(dirname(__DIR__, 2) . '/Fixtures/Refactoring/Ast'),
            new NullLogger(),
            $this->sessions,
        );
        $this->factory = HttpTransportFactory::fromOptions($this->httpOptions());
    }

    public function test_options_preflight_is_answered_without_authentication(): void
    {
        self::assertSame(204, $this->send('OPTIONS', [])->getStatusCode());
    }

    public function test_options_from_a_disallowed_origin_is_403_even_without_authentication(): void
    {
        self::assertSame(403, $this->send('OPTIONS', ['Origin' => 'http://evil.example'])->getStatusCode());
        self::assertSame(204, $this->send('OPTIONS', ['Origin' => 'http://localhost:6274'])->getStatusCode());
    }

    public function test_cors_answers_only_the_configured_origins(): void
    {
        $configured = HttpTransportFactory::fromOptions($this->httpOptions(['allowed_origins' => 'http://localhost:6274']));
        $preflight = $configured->handle(
            $this->server,
            new ServerRequest('OPTIONS', self::ENDPOINT, ['Origin' => 'http://localhost:6274', 'Accept' => 'application/json, text/event-stream']),
            new NullLogger(),
        );

        self::assertSame(204, $preflight->getStatusCode());
        self::assertSame('http://localhost:6274', $preflight->getHeaderLine('Access-Control-Allow-Origin'));

        $unconfigured = $this->send('OPTIONS', ['Origin' => 'http://localhost:6274']);
        self::assertSame(204, $unconfigured->getStatusCode());
        self::assertFalse($unconfigured->hasHeader('Access-Control-Allow-Origin'));
    }

    public function test_requests_without_a_bearer_token_are_401_before_any_mcp_processing(): void
    {
        $response = $this->send('POST', [], $this->initialize());

        self::assertSame(401, $response->getStatusCode());
        self::assertStringStartsWith('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        self::assertFalse($response->hasHeader('Mcp-Session-Id'));
        $this->assertNoSecrets($response);
    }

    public function test_wrong_tokens_and_query_string_tokens_are_401(): void
    {
        self::assertSame(401, $this->send('POST', ['Authorization' => 'Bearer nope-' . self::TOKEN], $this->initialize())->getStatusCode());
        self::assertSame(401, $this->send('POST', [], $this->initialize(), self::ENDPOINT . '?access_token=' . self::TOKEN)->getStatusCode());
    }

    public function test_a_malformed_authorization_header_is_400_before_any_mcp_processing(): void
    {
        $basicScheme = $this->send('POST', ['Authorization' => 'Basic abc'], $this->initialize());
        self::assertSame(400, $basicScheme->getStatusCode());
        self::assertStringStartsWith('Bearer', $basicScheme->getHeaderLine('WWW-Authenticate'));
        self::assertFalse($basicScheme->hasHeader('Mcp-Session-Id'));

        $noToken = $this->send('POST', ['Authorization' => 'Bearer'], $this->initialize());
        self::assertSame(400, $noToken->getStatusCode());
        self::assertStringStartsWith('Bearer', $noToken->getHeaderLine('WWW-Authenticate'));
        self::assertFalse($noToken->hasHeader('Mcp-Session-Id'));
    }

    public function test_a_disallowed_origin_is_403_even_without_credentials(): void
    {
        $response = $this->send('POST', ['Origin' => 'http://evil.example'], $this->initialize());

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_a_disallowed_host_without_origin_is_403(): void
    {
        self::assertSame(403, $this->send('POST', ['Host' => 'evil.example'], $this->initialize())->getStatusCode());
    }

    public function test_allowlisted_origins_and_absent_origins_from_loopback_are_accepted(): void
    {
        self::assertSame(200, $this->send('POST', $this->auth(['Origin' => 'http://localhost:6274']), $this->initialize())->getStatusCode());
        self::assertSame(200, $this->send('POST', $this->auth(), $this->initialize())->getStatusCode());
    }

    public function test_get_is_405_with_an_allow_header(): void
    {
        $response = $this->send('GET', $this->auth());

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST, DELETE, OPTIONS', $response->getHeaderLine('Allow'));
    }

    public function test_initialize_opens_a_session_and_tools_call_returns_the_capability_envelope(): void
    {
        $initialize = $this->send('POST', $this->auth(), $this->initialize());
        self::assertSame(200, $initialize->getStatusCode());
        self::assertSame('application/json', $initialize->getHeaderLine('Content-Type'));
        $session = $initialize->getHeaderLine('Mcp-Session-Id');
        self::assertNotSame('', $session);
        $body = json_decode((string) $initialize->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('2025-11-25', $body['result']['protocolVersion']);
        self::assertSame('agent-kit-refactoring', $body['result']['serverInfo']['name']);

        self::assertSame(202, $this->send('POST', $this->auth(['Mcp-Session-Id' => $session]), $this->notification('notifications/initialized'))->getStatusCode());

        $call = $this->send('POST', $this->auth(['Mcp-Session-Id' => $session, 'MCP-Protocol-Version' => '2025-11-25']), $this->request(2, 'tools/call', [
            'name' => 'refactoring_impact',
            'arguments' => ['target' => 'Fixtures\\Payments\\PaymentService::charge'],
        ]));
        self::assertSame(200, $call->getStatusCode());
        $result = json_decode((string) $call->getBody(), true, flags: JSON_THROW_ON_ERROR)['result'];
        $expected = $this->app->make(RefactoringCapabilities::class)
            ->impact(McpProjectRoot::fromPath(dirname(__DIR__, 2) . '/Fixtures/Refactoring/Ast')->path, 'Fixtures\\Payments\\PaymentService::charge')
            ->toArray();
        self::assertSame($expected, $result['structuredContent']);
    }

    public function test_session_header_rules_follow_the_sdk(): void
    {
        $session = $this->openSession();

        self::assertSame(400, $this->send('POST', $this->auth(), $this->request(2, 'tools/list'))->getStatusCode(), 'missing session');
        self::assertSame(400, $this->send('POST', $this->auth(['Mcp-Session-Id' => 'not-a-uuid']), $this->request(2, 'tools/list'))->getStatusCode(), 'malformed session');
        self::assertSame(404, $this->send('POST', $this->auth(['Mcp-Session-Id' => '0f6c9b2e-4e3d-4a0e-9a4b-3c8f1e2d5a6b']), $this->request(2, 'tools/list'))->getStatusCode(), 'unknown session');
        self::assertSame(400, $this->send('POST', $this->auth(['Mcp-Session-Id' => $session, 'MCP-Protocol-Version' => '1999-01-01']), $this->request(2, 'tools/list'))->getStatusCode(), 'unsupported protocol version header');
        self::assertSame(200, $this->send('POST', $this->auth(['Mcp-Session-Id' => $session]), $this->request(2, 'tools/list'))->getStatusCode(), 'valid session');
    }

    public function test_delete_ends_the_session_and_requires_a_session_header(): void
    {
        $session = $this->openSession();

        self::assertSame(400, $this->send('DELETE', $this->auth())->getStatusCode());
        self::assertSame(200, $this->send('DELETE', $this->auth(['Mcp-Session-Id' => $session]))->getStatusCode());
        self::assertSame(404, $this->send('POST', $this->auth(['Mcp-Session-Id' => $session]), $this->request(2, 'tools/list'))->getStatusCode());
        self::assertFalse($this->sessions->exists(Uuid::fromString($session)));
    }

    public function test_invalid_json_is_a_parse_error_and_batches_are_answered_in_one_body(): void
    {
        $response = $this->send('POST', $this->auth(), '{not json');

        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(-32700, json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
    }

    public function test_bodies_over_the_limit_are_413(): void
    {
        $factory = HttpTransportFactory::fromOptions($this->httpOptions(['max_body_bytes' => 64]));
        $response = $factory->handle($this->server, new ServerRequest('POST', self::ENDPOINT, $this->auth(), str_repeat('{"jsonrpc":"2.0"}', 10)), new NullLogger());

        self::assertSame(413, $response->getStatusCode());
        $this->assertNoSecrets($response);
    }

    public function test_unhandled_transport_failures_are_500_without_stack_traces(): void
    {
        $body = FnStream::decorate(\GuzzleHttp\Psr7\Utils::streamFor('{}'), [
            'read' => static fn () => throw new \RuntimeException('disk exploded at /secret/path'),
            'getContents' => static fn () => throw new \RuntimeException('disk exploded at /secret/path'),
            'getSize' => static fn () => null,
        ]);

        $response = $this->factory->handle($this->server, new ServerRequest('POST', self::ENDPOINT, $this->auth(), $body), new NullLogger());

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['error' => 'internal_error', 'message' => 'The MCP server could not process the request.'], json_decode((string) $response->getBody(), true));
        self::assertStringNotContainsString('/secret/path', (string) $response->getBody());
        $this->assertNoSecrets($response);
    }

    private function openSession(): string
    {
        $response = $this->send('POST', $this->auth(), $this->initialize());
        self::assertSame(200, $response->getStatusCode());
        $this->send('POST', $this->auth(['Mcp-Session-Id' => $response->getHeaderLine('Mcp-Session-Id')]), $this->notification('notifications/initialized'));

        return $response->getHeaderLine('Mcp-Session-Id');
    }

    private function send(string $method, array $headers, ?string $body = null, string $uri = self::ENDPOINT): ResponseInterface
    {
        $headers += ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json'];
        $response = $this->factory->handle($this->server, new ServerRequest($method, $uri, $headers, $body), new NullLogger());
        $this->assertNoSecrets($response);

        return $response;
    }

    private function auth(array $headers = []): array
    {
        return $headers + ['Authorization' => 'Bearer ' . self::TOKEN];
    }

    private function initialize(): string
    {
        return $this->request(1, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'agent-kit-tests', 'version' => '1.0.0'],
        ]);
    }

    private function request(int $id, string $method, array $params = []): string
    {
        return json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params === [] ? (object) [] : $params], JSON_THROW_ON_ERROR);
    }

    private function notification(string $method): string
    {
        return json_encode(['jsonrpc' => '2.0', 'method' => $method], JSON_THROW_ON_ERROR);
    }

    private function assertNoSecrets(ResponseInterface $response): void
    {
        $body = (string) $response->getBody();
        $response->getBody()->rewind();
        self::assertStringNotContainsString(self::TOKEN, $body);
        self::assertStringNotContainsString('Stack trace', $body);
        self::assertStringNotContainsString('#0 ', $body);
    }

    private function httpOptions(array $overrides = []): HttpTransportOptions
    {
        return HttpTransportOptions::fromConfig(array_merge([
            'enabled' => true, 'path' => '/mcp', 'allow_remote' => false,
            'allowed_origins' => '', 'bearer_token' => self::TOKEN, 'max_body_bytes' => 1048576,
            'session_ttl' => 3600, 'cache_store' => null, 'time_limit' => 120,
        ], $overrides));
    }
}
