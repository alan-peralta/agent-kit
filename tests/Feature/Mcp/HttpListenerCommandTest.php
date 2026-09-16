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

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 15);
            $this->finish($this->process, $this->pipes, 10);
        }
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

    private function startListener(string $host = '127.0.0.1'): int
    {
        $port = $this->freePort();
        [$this->process, $this->pipes] = $this->spawn(
            $this->serverArguments(['--transport=http', '--host=' . $host, '--port=' . $port]),
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

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, $error);
        $port = (int) explode(':', stream_socket_get_name($socket, false))[1];
        fclose($socket);

        return $port;
    }
}
