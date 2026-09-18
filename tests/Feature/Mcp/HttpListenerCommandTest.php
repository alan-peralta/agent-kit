<?php

namespace Peralta\AgentKit\Tests\Feature\Mcp;

use GuzzleHttp\Client as HttpClient;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Peralta\AgentKit\Refactoring\Mcp\RefactoringToolCatalog;
use Peralta\AgentKit\Tests\Feature\Mcp\Concerns\SpawnsMcpServer;
use PHPUnit\Framework\TestCase;

final class HttpListenerCommandTest extends TestCase
{
    use SpawnsMcpServer;

    private const TOKEN = 'test-token-0123456789abcdef0123456789abcdef';

    /** @var resource|null */
    private $process = null;
    private array $pipes = [];
    private ?string $generatedProject = null;

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 15);
            $this->finish($this->process, $this->pipes, 10);
        }
        $this->removeGeneratedProject();
        parent::tearDown();
    }

    public function test_the_sdk_client_completes_the_lifecycle_over_http_and_delete_closes_the_session(): void
    {
        $port = $this->startListener();
        $client = Client::builder()->setClientInfo('agent-kit-tests', '1.0.0')->setInitTimeout(20)->setRequestTimeout(60)->setMaxRetries(0)->build();
        $client->connect(new HttpTransport("http://127.0.0.1:{$port}/mcp", ['Authorization' => 'Bearer ' . self::TOKEN], new HttpClient()));

        try {
            self::assertSame((new RefactoringToolCatalog())->names(), array_map(fn ($tool) => $tool->name, $client->listTools()->tools));
            $impact = $client->callTool('refactoring_impact', ['target' => 'Fixtures\\Payments\\PaymentService::charge']);
            self::assertFalse($impact->isError);
            self::assertSame('charge', $impact->structuredContent['data']['method']);
            self::assertTrue($client->callTool('refactoring_impact', ['target' => 'Missing\\Service'])->isError);
            self::assertSame(RefactoringToolCatalog::RESOURCE_URI, $client->listResources()->resources[0]->uri);
        } finally {
            $client->disconnect();
        }
    }

    public function test_plain_http_clients_get_the_documented_status_codes(): void
    {
        $port = $this->startListener();
        $http = new HttpClient(['base_uri' => "http://127.0.0.1:{$port}", 'http_errors' => false]);

        self::assertSame(401, $http->post('/mcp', ['body' => '{}'])->getStatusCode());
        self::assertSame(405, $http->get('/mcp', ['headers' => ['Authorization' => 'Bearer ' . self::TOKEN]])->getStatusCode());
        self::assertSame(404, $http->post('/elsewhere', ['headers' => ['Authorization' => 'Bearer ' . self::TOKEN]])->getStatusCode());
        self::assertSame(403, $http->post('/mcp', ['headers' => ['Origin' => 'http://evil.example']])->getStatusCode());
        self::assertSame(413, $http->post('/mcp', [
            'headers' => ['Authorization' => 'Bearer ' . self::TOKEN, 'Content-Type' => 'application/json'],
            'body' => str_repeat('{"jsonrpc":"2.0"}', 200),
        ])->getStatusCode());
    }

    public function test_idle_connections_are_closed_after_the_configured_timeout(): void
    {
        $port = $this->startListener();
        $socket = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 5);
        self::assertIsResource($socket, $error);
        stream_set_timeout($socket, 6);

        $started = microtime(true);
        $read = fread($socket, 1);
        $elapsed = microtime(true) - $started;
        $meta = stream_get_meta_data($socket);

        self::assertFalse($meta['timed_out'], 'The server kept an idle connection open past the idle timeout.');
        self::assertTrue($read === '' || $read === false);
        self::assertGreaterThan(0.5, $elapsed);
        fclose($socket);
    }

    public function test_a_slow_call_is_not_dropped_by_the_idle_timeout(): void
    {
        $port = $this->startListener('127.0.0.1', $this->generateLargeProject());
        $client = Client::builder()->setClientInfo('agent-kit-tests', '1.0.0')->setInitTimeout(20)->setRequestTimeout(60)->setMaxRetries(0)->build();
        // `Connection: close` is not decoration: curl transparently replays a request when the
        // *reused* connection dies before any byte of the response arrives, and that replay hits
        // a warm index cache and returns in milliseconds - which would hide a dropped response
        // behind a doubled analysis. One request per connection makes the drop observable, the
        // way any MCP client that does not pool connections would experience it.
        $http = new HttpClient(['headers' => ['Connection' => 'close']]);
        $client->connect(new HttpTransport("http://127.0.0.1:{$port}/mcp", ['Authorization' => 'Bearer ' . self::TOKEN], $http));

        try {
            // AGENT_KIT_MCP_HTTP_IDLE_TIMEOUT is 1s here: the analysis blocks the event loop for
            // longer than that, so the per-connection idle timer expires while the response is
            // being produced. It must not close the connection and discard that response.
            $started = microtime(true);
            $result = $client->callTool('refactoring_analyze', ['target' => 'SlowProject\\Generated\\Klass0']);
            $elapsed = microtime(true) - $started;

            self::assertFalse($result->isError, json_encode($result->structuredContent));
            self::assertSame('analyze', $result->structuredContent['capability']);
            if ($elapsed < 1.5) {
                self::markTestSkipped(sprintf(
                    'This machine analyzed the generated project in %.2fs, too close to the 1s idle timeout for this test to prove anything.',
                    $elapsed,
                ));
            }
        } finally {
            $client->disconnect();
        }
    }

    public function test_remote_binds_are_refused_without_opt_in(): void
    {
        [$process, $pipes] = $this->spawn($this->serverArguments(['--transport=http', '--host=0.0.0.0', '--port=' . $this->freePort()]), $this->httpEnvironment());
        fclose($pipes[0]);
        $run = $this->finish($process, $pipes);

        self::assertSame(1, $run['status']);
        self::assertSame('', $run['stdout']);
        self::assertStringContainsString('--allow-remote', $run['stderr']);
    }

    public function test_http_refuses_to_start_without_a_token_or_when_disabled(): void
    {
        [$process, $pipes] = $this->spawn($this->serverArguments(['--transport=http', '--port=' . $this->freePort()]), $this->httpEnvironment(['AGENT_KIT_MCP_BEARER_TOKEN' => '']));
        fclose($pipes[0]);
        $run = $this->finish($process, $pipes);
        self::assertSame(1, $run['status']);
        self::assertStringContainsString('AGENT_KIT_MCP_BEARER_TOKEN', $run['stderr']);

        [$process, $pipes] = $this->spawn($this->serverArguments(['--transport=http', '--port=' . $this->freePort()]), $this->httpEnvironment(['AGENT_KIT_MCP_HTTP_ENABLED' => 'false']));
        fclose($pipes[0]);
        $run = $this->finish($process, $pipes);
        self::assertSame(1, $run['status']);
        self::assertStringContainsString('AGENT_KIT_MCP_HTTP_ENABLED', $run['stderr']);
    }

    public function test_a_non_numeric_port_is_refused(): void
    {
        [$process, $pipes] = $this->spawn($this->serverArguments(['--transport=http', '--port=80o80']), $this->httpEnvironment());
        fclose($pipes[0]);
        $run = $this->finish($process, $pipes);

        self::assertSame(1, $run['status']);
        self::assertSame('', $run['stdout']);
        self::assertStringContainsString('--port option must be an integer', $run['stderr']);
    }

    public function test_localhost_binds_the_loopback_interface(): void
    {
        $port = $this->startListener('localhost');
        $http = new HttpClient(['base_uri' => "http://127.0.0.1:{$port}", 'http_errors' => false]);

        self::assertSame(401, $http->post('/mcp', ['body' => '{}'])->getStatusCode());
    }

    public function test_a_hostname_bind_is_refused_cleanly(): void
    {
        [$process, $pipes] = $this->spawn(
            $this->serverArguments(['--transport=http', '--host=mcp.internal', '--allow-remote', '--port=' . $this->freePort()]),
            $this->httpEnvironment(),
        );
        fclose($pipes[0]);
        $run = $this->finish($process, $pipes);

        self::assertSame(1, $run['status']);
        self::assertSame('', $run['stdout']);
        self::assertStringContainsString('Could not bind the MCP HTTP transport', $run['stderr']);
    }

    private function startListener(string $host = '127.0.0.1', ?string $path = null): int
    {
        $port = $this->freePort();
        [$this->process, $this->pipes] = $this->spawn(
            $this->serverArguments(['--transport=http', '--host=' . $host, '--port=' . $port], $path),
            $this->httpEnvironment(),
        );
        fclose($this->pipes[0]);

        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            $probe = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.2);
            if (is_resource($probe)) {
                fclose($probe);

                return $port;
            }
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                stream_set_blocking($this->pipes[2], false);
                self::fail('The HTTP listener exited early: ' . stream_get_contents($this->pipes[2]));
            }
            usleep(100000);
        }

        self::fail('The HTTP listener did not accept connections within 20s.');
    }

    private function httpEnvironment(array $overrides = []): array
    {
        return array_merge([
            'AGENT_KIT_MCP_HTTP_ENABLED' => 'true',
            'AGENT_KIT_MCP_BEARER_TOKEN' => self::TOKEN,
            'AGENT_KIT_MCP_HTTP_IDLE_TIMEOUT' => '1',
            'AGENT_KIT_MCP_HTTP_MAX_BODY_BYTES' => '1024',
        ], $overrides);
    }

    /**
     * A throw-away project big enough that indexing it takes clearly longer than the 1s idle
     * timeout, so a tool call really does outlive the timer instead of only nearly doing so.
     */
    private function generateLargeProject(): string
    {
        $root = sys_get_temp_dir() . '/agent-kit-mcp-slow-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root, 0777, true), "Could not create {$root}.");
        $this->generatedProject = $root;

        $written = 0;
        for ($file = 0; $file < 400; $file++) {
            $source = "<?php\n\nnamespace SlowProject\\Generated;\n\n";
            for ($class = 0; $class < 3; $class++) {
                $name = $class === 0 ? "Klass{$file}" : "Klass{$file}_{$class}";
                $collaborator = $class === 2 ? "Klass{$file}" : 'Klass' . $file . '_' . ($class + 1);
                $source .= "class {$name}\n{\n";
                for ($method = 0; $method < 8; $method++) {
                    $next = ($method + 1) % 8;
                    $source .= "    public function method{$method}(int \$value): int\n    {\n";
                    $source .= "        \$collaborator = new {$collaborator}();\n";
                    $source .= "        \$total = \$collaborator->method{$next}(\$value);\n";
                    for ($line = 0; $line < 5; $line++) {
                        $source .= "        \$total += \$value * {$line};\n";
                    }
                    $source .= "        return \$total;\n    }\n\n";
                }
                $source .= "}\n\n";
            }
            $written += file_put_contents($root . "/File{$file}.php", $source) === false ? 0 : 1;
        }
        self::assertSame(400, $written, "Could not write the generated project in {$root}.");

        return $root;
    }

    private function removeGeneratedProject(): void
    {
        if ($this->generatedProject === null) {
            return;
        }

        foreach (glob($this->generatedProject . '/*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->generatedProject);
        $this->generatedProject = null;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, $error);
        $port = (int) explode(':', stream_socket_get_name($socket, false))[1];
        fclose($socket);

        return $port;
    }
}
