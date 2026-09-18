<?php

namespace Peralta\AgentKit\Tests\Feature\Mcp;

use GuzzleHttp\Client;
use Peralta\AgentKit\Tests\Feature\Mcp\Concerns\SpawnsMcpServer;
use PHPUnit\Framework\TestCase;

/**
 * Drives the MCP Streamable HTTP route (`agent-kit.mcp`) over a REAL PHP built-in server started
 * with `php vendor/bin/testbench serve`, unlike HttpRouteTest, which drives the same route
 * in-process. Each HTTP request here is handled by a fresh script run of the built-in server -
 * nothing survives in memory between requests - so this is the only test that proves MCP
 * sessions actually persist through the Laravel cache store the way a real `php artisan serve`
 * (or PHP-FPM, or Octane) deployment would need them to.
 *
 * `testbench serve` was chosen over a hand-rolled `php -S` + router.php script: Testbench's own
 * `Orchestra\Testbench\Foundation\PackageManifest::providersFromRoot()` merges this package's
 * composer.json `extra.laravel` config into the served skeleton's package manifest, so
 * `AgentKitServiceProvider` boots for the served app exactly as it would for a real consumer,
 * with no bespoke bootstrap of our own to keep in sync with the real one.
 *
 * The one wrinkle: `Illuminate\Foundation\Console\ServeCommand::startProcess()` decides what
 * environment to hand the `php -S` process it spawns from PHP's `$_ENV` superglobal, not
 * `getenv()` - and with this CLI's `variables_order` ini setting not including `E`, `$_ENV` never
 * reflects the environment proc_open() gave *this* process in the first place, regardless of
 * `--no-reload`. So a custom AGENT_KIT_MCP_HTTP_ENABLED=true handed to `testbench serve` itself
 * never reaches the served app, and the route stays 404. Baking the same directives straight
 * into the skeleton `.env` file's contents sidesteps that entirely:
 * `Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables` reads them off disk on every fresh
 * request, independent of both `$_ENV` and `getenv()`.
 */
final class HttpRouteServerTest extends TestCase
{
    use SpawnsMcpServer;

    private const TOKEN = 'http-server-test-token-0123456789abcdef0123456789abcdef012345678';

    /** @var resource|null */
    private $serverProcess;

    /** @var list<resource> */
    private array $serverPipes = [];

    private ?string $envFile = null;

    protected function tearDown(): void
    {
        $this->stopServer();

        parent::tearDown();
    }

    public function test_the_full_mcp_flow_survives_across_requests_on_a_real_http_server(): void
    {
        $port = $this->freePort();
        $this->startServer($port);

        $client = new Client(['http_errors' => false, 'timeout' => 10, 'connect_timeout' => 5]);
        $base = "http://127.0.0.1:{$port}/mcp";
        $headers = ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json'];

        // Confirms the served app really loaded our config (misconfigured/disabled routes never
        // get this far), not just that some route matched /mcp.
        $unauthorized = $client->post($base, ['headers' => $headers, 'body' => $this->initialize()]);
        self::assertSame(401, $unauthorized->getStatusCode());

        $initialize = $client->post($base, [
            'headers' => $headers + ['Authorization' => 'Bearer ' . self::TOKEN],
            'body' => $this->initialize(),
        ]);
        self::assertSame(200, $initialize->getStatusCode());
        $session = $initialize->getHeaderLine('Mcp-Session-Id');
        self::assertNotSame('', $session);

        $notified = $client->post($base, [
            'headers' => $headers + ['Authorization' => 'Bearer ' . self::TOKEN, 'Mcp-Session-Id' => $session],
            'body' => $this->notification('notifications/initialized'),
        ]);
        self::assertSame(202, $notified->getStatusCode());

        // A brand new PHP process handles this request: only the cache store (CACHE_STORE=file)
        // can be why the server still recognizes $session.
        $call = $client->post($base, [
            'headers' => $headers + ['Authorization' => 'Bearer ' . self::TOKEN, 'Mcp-Session-Id' => $session],
            'body' => $this->request(2, 'tools/call', ['name' => 'refactoring_audit', 'arguments' => []]),
        ]);
        self::assertSame(200, $call->getStatusCode());
        $decoded = json_decode((string) $call->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('audit', $decoded['result']['structuredContent']['capability'] ?? null);

        $delete = $client->delete($base, [
            'headers' => ['Authorization' => 'Bearer ' . self::TOKEN, 'Mcp-Session-Id' => $session],
        ]);
        self::assertSame(200, $delete->getStatusCode());
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($socket, "Could not reserve a free TCP port: {$errstr}");
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function startServer(int $port): void
    {
        $this->envFile = $this->packageRoot() . '/vendor/orchestra/testbench-core/laravel/.env';

        // spawn() only places the harmless fixture .env when the skeleton has none yet, so writing
        // ours first makes it keep this one: see the class docblock for why the served app needs
        // these directives on disk rather than passed as process environment variables.
        file_put_contents($this->envFile, implode("\n", [
            'APP_ENV=testing',
            "APP_URL=http://127.0.0.1:{$port}",
            'AGENT_KIT_MCP_HTTP_ENABLED=true',
            'AGENT_KIT_MCP_BEARER_TOKEN=' . self::TOKEN,
            'AGENT_KIT_MCP_PROJECT_ROOT=' . $this->fixtureRoot(),
            'CACHE_STORE=file',
            '',
        ]));

        [$this->serverProcess, $this->serverPipes] = $this->spawn([
            $this->packageRoot() . '/vendor/bin/testbench', 'serve', '--host=127.0.0.1', "--port={$port}",
        ]);
        fclose($this->serverPipes[0]);

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
            if ($probe !== false) {
                fclose($probe);

                return;
            }
            usleep(100000);
        }

        self::fail("The built-in server never accepted connections on 127.0.0.1:{$port}.");
    }

    /** Stops the server and cleans up even when the test above failed before reaching here. */
    private function stopServer(): void
    {
        if (is_resource($this->serverProcess)) {
            $status = proc_get_status($this->serverProcess);
            if ($status['running']) {
                // 15 = SIGTERM. A literal, not the constant, so this keeps working without the
                // pcntl extension loaded - the same reason finish() signals with a literal 9.
                proc_terminate($this->serverProcess, 15);
            }

            try {
                $this->finish($this->serverProcess, $this->serverPipes, 10);
            } finally {
                $this->cleanUpServedState();
            }

            return;
        }

        $this->cleanUpServedState();
    }

    /**
     * Undoes what startServer() wrote outside the harmless fixture that removeSkeletonEnvironmentFile()
     * (part of finish()'s own cleanup) already handles: our own .env contents, and the file cache
     * the served process's Psr16SessionStore wrote under the skeleton's storage directory.
     */
    private function cleanUpServedState(): void
    {
        if ($this->envFile !== null && is_file($this->envFile)) {
            @unlink($this->envFile);
        }

        $cache = $this->packageRoot() . '/vendor/orchestra/testbench-core/laravel/storage/framework/cache/data';
        foreach (glob($cache . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->deleteDirectory($entry) : @unlink($entry);
        }
    }

    private function deleteDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
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
