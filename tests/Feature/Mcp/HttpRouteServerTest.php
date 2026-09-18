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
    private bool $envFileExistedBeforeTest = false;
    private ?string $originalEnvContents = null;
    private ?string $writtenEnvContents = null;

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

    /**
     * startServer() has to overwrite the skeleton .env with its own MCP directives even when a
     * real, pre-existing file is already there (a developer's own local skeleton config, for
     * instance) - the served app needs those directives on disk to load the route at all. Proves
     * that file comes back byte-for-byte once the server stops, instead of staying clobbered.
     */
    public function test_a_pre_existing_skeleton_env_survives_the_run_unchanged(): void
    {
        $envFile = $this->packageRoot() . '/vendor/orchestra/testbench-core/laravel/.env';
        // The sentinel stands in for a developer's own file; a real one already there is kept aside.
        $developerEnv = is_file($envFile) ? (string) file_get_contents($envFile) : null;
        $sentinel = "SENTINEL_DEVELOPER_ENV=do-not-lose-me\nDB_CONNECTION=sqlite\n";
        file_put_contents($envFile, $sentinel);

        try {
            $port = $this->freePort();
            $this->startServer($port);

            // A light sanity check that the server is really up and serving our config, not just that
            // the port happened to accept a connection.
            $client = new Client(['http_errors' => false, 'timeout' => 10, 'connect_timeout' => 5]);
            $response = $client->post("http://127.0.0.1:{$port}/mcp", [
                'headers' => ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json'],
                'body' => $this->initialize(),
            ]);
            self::assertSame(401, $response->getStatusCode());

            // Stop and restore now, inside the test, so the byte-for-byte assertion below runs
            // against the actually-restored file rather than after PHPUnit has already moved on.
            $this->stopServer();

            self::assertSame(
                $sentinel,
                file_get_contents($envFile),
                'A pre-existing skeleton .env must survive the test run byte-for-byte.',
            );
        } finally {
            // Stops a server a failed assertion left running (and puts the sentinel back), then
            // replaces the sentinel with whatever was there before this test: the developer's own
            // file byte-for-byte, or nothing. envFile is cleared first so tearDown()'s own
            // stopServer() does not restore the sentinel a second time.
            $this->stopServer();
            $this->envFile = null;
            if ($developerEnv !== null) {
                file_put_contents($envFile, $developerEnv);
            } elseif (is_file($envFile) && file_get_contents($envFile) === $sentinel) {
                @unlink($envFile);
            }
        }
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

        // Remember whatever is already there - a developer's own local skeleton .env, or nothing
        // at all - so stopServer() can put it back exactly, byte-for-byte, instead of the blanket
        // delete SpawnsMcpServer::removeSkeletonEnvironmentFile() does for its own harmless
        // fixture (which never overwrites an existing file in the first place, so it never needs
        // a restore path; this test does overwrite one, so it needs one).
        $this->envFileExistedBeforeTest = is_file($this->envFile);
        $this->originalEnvContents = $this->envFileExistedBeforeTest ? (string) file_get_contents($this->envFile) : null;

        // Write ours unconditionally, even over a real pre-existing file: see the class docblock
        // for why the served app needs these directives on disk rather than passed as process
        // environment variables.
        $this->writtenEnvContents = implode("\n", [
            'APP_ENV=testing',
            "APP_URL=http://127.0.0.1:{$port}",
            'AGENT_KIT_MCP_HTTP_ENABLED=true',
            'AGENT_KIT_MCP_BEARER_TOKEN=' . self::TOKEN,
            'AGENT_KIT_MCP_PROJECT_ROOT=' . $this->fixtureRoot(),
            'CACHE_STORE=file',
            'AGENT_KIT_MCP_INDEX_CACHE_PATH=false',
            '',
        ]);
        file_put_contents($this->envFile, $this->writtenEnvContents);

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

        self::fail("The built-in server never accepted connections on 127.0.0.1:{$port}.\n" . $this->drainServerOutput());
    }

    /**
     * Non-blocking read of whatever the server has already written, for the readiness-timeout
     * failure message - the same diagnostics SpawnsMcpServer::finish() attaches on its own
     * timeout path, which this method never reaches since the server is still running.
     */
    private function drainServerOutput(): string
    {
        if (!is_resource($this->serverProcess) || !isset($this->serverPipes[1], $this->serverPipes[2])) {
            return '(no server process to read output from)';
        }

        stream_set_blocking($this->serverPipes[1], false);
        stream_set_blocking($this->serverPipes[2], false);
        $stdout = (string) stream_get_contents($this->serverPipes[1]);
        $stderr = (string) stream_get_contents($this->serverPipes[2]);

        return "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}";
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
     * Undoes what startServer() wrote outside the harmless-fixture removal that finish()'s own
     * cleanup (removeSkeletonEnvironmentFile()) already handles - which never fires here anyway,
     * since our contents never equal that fixture's: our own .env contents, and the file cache
     * the served process's Psr16SessionStore wrote under the skeleton's storage directory.
     */
    private function cleanUpServedState(): void
    {
        $this->restoreSkeletonEnvironmentFile();

        $cache = $this->packageRoot() . '/vendor/orchestra/testbench-core/laravel/storage/framework/cache/data';
        foreach (glob($cache . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->deleteDirectory($entry) : @unlink($entry);
        }
    }

    /**
     * Restores a real, pre-existing skeleton .env byte-for-byte. Otherwise, removes this test's
     * own file - but only while it still holds exactly what startServer() wrote, the same
     * conservative equality check SpawnsMcpServer::removeSkeletonEnvironmentFile() uses for its
     * harmless fixture, so a write from anything else during the run is never clobbered.
     */
    private function restoreSkeletonEnvironmentFile(): void
    {
        if ($this->envFile === null) {
            return;
        }

        if ($this->envFileExistedBeforeTest) {
            file_put_contents($this->envFile, $this->originalEnvContents);

            return;
        }

        if (is_file($this->envFile) && file_get_contents($this->envFile) === $this->writtenEnvContents) {
            @unlink($this->envFile);
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
