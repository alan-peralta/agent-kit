<?php

namespace Peralta\AgentKit\Tests\Feature\Mcp;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Peralta\AgentKit\Tests\TestCase;

/**
 * Exercises the MCP Streamable HTTP transport through the actual Laravel route
 * (`agent-kit.mcp`), as opposed to HttpTransportPipelineTest, which drives the
 * PSR-7 pipeline directly. The JSON-RPC body helpers below are intentionally
 * copied from HttpTransportPipelineTest rather than shared, so the two test
 * classes stay independent.
 */
final class HttpRouteTest extends TestCase
{
    private const TOKEN = 'test-http-route-token-0123456789abcdef0123456789abcdef0123456789';
    private const SHORT_TOKEN = 'too-short-token-2026';

    private ?string $sessionCachePath = null;

    protected function defineEnvironment($app): void
    {
        $name = $this->name();

        $app['config']->set('agent-kit.mcp.enabled', !str_contains($name, 'route_does_not_exist_when_mcp_is_disabled'));
        $app['config']->set('agent-kit.mcp.http.enabled', !str_contains($name, 'route_does_not_exist_when_http_is_disabled'));
        $app['config']->set('agent-kit.mcp.project_root', dirname(__DIR__, 2) . '/Fixtures/Refactoring/Ast');
        $app['config']->set('agent-kit.mcp.http.bearer_token', self::TOKEN);
        $app['config']->set('agent-kit.mcp.http.allow_remote', false);
        $app['config']->set('agent-kit.mcp.http.allowed_origins', '');
        $app['config']->set('agent-kit.mcp.http.max_body_bytes', 1048576);
        $app['config']->set('agent-kit.mcp.http.session_ttl', 3600);
        $app['config']->set('agent-kit.mcp.http.cache_store', null);
        $app['config']->set('agent-kit.mcp.http.time_limit', 120);
        $app['config']->set('cache.default', 'array');

        // The only scenario that needs a cache store surviving process teardown: everything
        // else uses the in-memory array store, which is simpler and just as valid for them.
        if (str_contains($name, 'sessions_survive_a_new_application_instance')) {
            $path = $this->sessionCachePath ??= sys_get_temp_dir() . '/agent-kit-mcp-http-route-' . bin2hex(random_bytes(6));
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
            $app['config']->set('cache.default', 'file');
            $app['config']->set('cache.stores.file.path', $path);
        }
    }

    protected function tearDown(): void
    {
        if ($this->sessionCachePath !== null && is_dir($this->sessionCachePath)) {
            File::deleteDirectory($this->sessionCachePath);
        }

        parent::tearDown();
    }

    public function test_route_does_not_exist_when_http_is_disabled(): void
    {
        self::assertFalse(Route::has('agent-kit.mcp'));
        self::assertSame(404, $this->get('/mcp')->getStatusCode());
    }

    public function test_route_does_not_exist_when_mcp_is_disabled(): void
    {
        self::assertFalse(Route::has('agent-kit.mcp'));
        self::assertSame(404, $this->get('/mcp')->getStatusCode());
    }

    public function test_options_preflight_is_204_without_a_token(): void
    {
        self::assertSame(204, $this->send('OPTIONS', [])->getStatusCode());
    }

    public function test_missing_token_is_401_with_a_bearer_challenge(): void
    {
        $response = $this->send('POST', [], $this->initialize());

        self::assertSame(401, $response->getStatusCode());
        self::assertStringStartsWith('Bearer', (string) $response->headers->get('WWW-Authenticate'));
    }

    public function test_wrong_token_is_401(): void
    {
        $response = $this->send('POST', ['Authorization' => 'Bearer wrong-' . self::TOKEN], $this->initialize());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_disallowed_host_is_403(): void
    {
        $response = $this->send('POST', $this->auth(), $this->initialize(), 'http://evil.test/mcp');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Forbidden: Invalid Host header.', $response->getContent());
    }

    public function test_a_disallowed_origin_is_403_even_with_a_valid_token_and_a_loopback_client(): void
    {
        $response = $this->send('POST', $this->auth(['Origin' => 'http://evil.example']), $this->initialize());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Forbidden: Invalid Origin header.', $response->getContent());
    }

    public function test_the_route_carries_no_middleware(): void
    {
        self::assertSame([], Route::getRoutes()->getByName('agent-kit.mcp')->gatherMiddleware());
    }

    public function test_get_is_405(): void
    {
        self::assertSame(405, $this->send('GET', $this->auth())->getStatusCode());
    }

    public function test_a_non_loopback_client_is_403_and_allow_remote_lets_it_through(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5']);

        $forbidden = $this->send('POST', $this->auth(), $this->initialize());
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame(
            '{"error":"forbidden","message":"The MCP HTTP transport only accepts loopback clients. Set AGENT_KIT_MCP_ALLOW_REMOTE=true to accept others."}',
            $forbidden->getContent(),
        );

        config(['agent-kit.mcp.http.allow_remote' => true]);

        $allowed = $this->send('POST', $this->auth(), $this->initialize());
        self::assertSame(200, $allowed->getStatusCode());
    }

    public function test_a_short_bearer_token_is_503_with_the_verbatim_misconfigured_body_and_no_secrets(): void
    {
        config(['agent-kit.mcp.http.bearer_token' => self::SHORT_TOKEN]);

        $response = $this->send('POST', [], $this->initialize());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            '{"error":"misconfigured","message":"The MCP HTTP transport is not configured correctly; see the application log."}',
            $response->getContent(),
        );
        self::assertStringNotContainsString(self::SHORT_TOKEN, (string) $response->getContent());
        self::assertStringNotContainsString('AGENT_KIT_MCP_BEARER_TOKEN', (string) $response->getContent());
    }

    public function test_an_unknown_cache_store_is_503_with_the_verbatim_misconfigured_body_and_no_secrets(): void
    {
        config(['agent-kit.mcp.http.cache_store' => 'missing']);

        $response = $this->send('POST', [], $this->initialize());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            '{"error":"misconfigured","message":"The MCP HTTP transport is not configured correctly; see the application log."}',
            $response->getContent(),
        );
        self::assertStringNotContainsString('missing', (string) $response->getContent());
        self::assertStringNotContainsString('Cache store', (string) $response->getContent());
    }

    public function test_bodies_over_the_limit_are_413(): void
    {
        config(['agent-kit.mcp.http.max_body_bytes' => 64]);

        $response = $this->send('POST', $this->auth(), str_repeat('{"jsonrpc":"2.0"}', 10));

        self::assertSame(413, $response->getStatusCode());
    }

    public function test_the_full_mcp_flow_over_the_route(): void
    {
        $initialize = $this->send('POST', $this->auth(), $this->initialize());
        self::assertSame(200, $initialize->getStatusCode());
        $session = (string) $initialize->headers->get('Mcp-Session-Id');
        self::assertNotSame('', $session);
        self::assertSame('2025-11-25', $initialize->json('result.protocolVersion'));

        self::assertSame(
            202,
            $this->send('POST', $this->auth(['Mcp-Session-Id' => $session]), $this->notification('notifications/initialized'))->getStatusCode(),
        );

        $list = $this->send('POST', $this->auth(['Mcp-Session-Id' => $session]), $this->request(2, 'tools/list'));
        self::assertSame(200, $list->getStatusCode());
        self::assertContains('refactoring_audit', array_column((array) $list->json('result.tools'), 'name'));

        $call = $this->send('POST', $this->auth(['Mcp-Session-Id' => $session]), $this->request(3, 'tools/call', [
            'name' => 'refactoring_audit',
            'arguments' => [],
        ]));
        self::assertSame(200, $call->getStatusCode());
        self::assertSame('audit', $call->json('result.structuredContent.capability'));

        self::assertSame(200, $this->send('DELETE', $this->auth(['Mcp-Session-Id' => $session]))->getStatusCode());

        self::assertSame(
            404,
            $this->send('POST', $this->auth(['Mcp-Session-Id' => $session]), $this->request(4, 'tools/list'))->getStatusCode(),
        );
    }

    public function test_sessions_survive_a_new_application_instance(): void
    {
        $session = $this->openSession();

        $this->refreshApplication();

        $list = $this->send('POST', $this->auth(['Mcp-Session-Id' => $session]), $this->request(2, 'tools/list'));
        self::assertSame(200, $list->getStatusCode());
    }

    private function openSession(): string
    {
        $response = $this->send('POST', $this->auth(), $this->initialize());
        self::assertSame(200, $response->getStatusCode());
        $session = (string) $response->headers->get('Mcp-Session-Id');
        $this->send('POST', $this->auth(['Mcp-Session-Id' => $session]), $this->notification('notifications/initialized'));

        return $session;
    }

    private function send(string $method, array $headers, ?string $body = null, string $uri = '/mcp'): TestResponse
    {
        $headers += ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json'];
        $server = $this->transformHeadersToServerVars($headers);

        return $this->call($method, $uri, [], [], [], $server, $body);
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
}
