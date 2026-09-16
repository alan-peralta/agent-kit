<?php

namespace Peralta\AgentKit\Tests\Feature\Mcp;

use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Peralta\AgentKit\Refactoring\Mcp\RefactoringToolCatalog;
use Peralta\AgentKit\Tests\Feature\Mcp\Concerns\SpawnsMcpServer;
use PHPUnit\Framework\TestCase;

final class StdioServerCommandTest extends TestCase
{
    use SpawnsMcpServer;

    public function test_the_sdk_client_completes_the_full_lifecycle_over_stdio(): void
    {
        $client = Client::builder()
            ->setClientInfo('agent-kit-tests', '1.0.0')
            ->setInitTimeout(20)
            ->setRequestTimeout(60)
            ->setMaxRetries(0)
            ->build();
        $client->connect(new StdioTransport(
            PHP_BINARY,
            $this->serverArguments(['--transport=stdio']),
            cwd: $this->packageRoot(),
            env: $this->serverEnvironment(),
        ));

        try {
            self::assertSame('agent-kit-refactoring', $client->getServerInfo()?->name);
            self::assertSame((new RefactoringToolCatalog())->names(), array_map(fn ($tool) => $tool->name, $client->listTools()->tools));

            $impact = $client->callTool('refactoring_impact', ['target' => 'Fixtures\\Payments\\PaymentService::charge']);
            self::assertFalse($impact->isError);
            self::assertSame('impact', $impact->structuredContent['capability']);
            self::assertSame('charge', $impact->structuredContent['data']['method']);

            self::assertSame('capability_discovery', $client->callTool('refactoring_capabilities')->structuredContent['capability']);
            self::assertArrayHasKey('summary', $client->callTool('refactoring_audit')->structuredContent['data']);
            self::assertSame('CheckoutService.php', $client->callTool('refactoring_analyze', ['target' => 'CheckoutService.php'])->structuredContent['data']['target']);
            self::assertNotEmpty($client->callTool('refactoring_callers', ['target' => 'Fixtures\\Payments\\PaymentService::charge'])->structuredContent['data']['direct_callers']);
            self::assertNotEmpty($client->callTool('refactoring_dependencies', ['target' => 'Fixtures\\Checkout\\CheckoutService'])->structuredContent['data']['upstream_dependencies']);

            $missing = $client->callTool('refactoring_impact', ['target' => 'Missing\\Service']);
            self::assertTrue($missing->isError);
            self::assertSame('TARGET_NOT_FOUND', $missing->structuredContent['error']['code']);

            $resources = $client->listResources()->resources;
            self::assertSame(RefactoringToolCatalog::RESOURCE_URI, $resources[0]->uri);
            $document = json_decode($client->readResource(RefactoringToolCatalog::RESOURCE_URI)->contents[0]->text, true, flags: JSON_THROW_ON_ERROR);
            self::assertFalse($document['mutation']['supported']);
        } finally {
            $client->disconnect();
        }
    }

    public function test_stdout_carries_only_json_rpc_and_logs_go_to_stderr(): void
    {
        [$process, $pipes] = $this->spawn($this->serverArguments(['--transport=stdio']), ['AGENT_KIT_MCP_LOG_LEVEL' => 'debug']);
        fwrite($pipes[0], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1'],
        ]]) . "\n");
        fwrite($pipes[0], "{not json\n");
        fwrite($pipes[0], json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']) . "\n");
        fwrite($pipes[0], json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => (object) []]) . "\n");
        fclose($pipes[0]);

        $run = $this->finish($process, $pipes);

        self::assertSame(0, $run['status'], $run['stderr']);
        $lines = array_values(array_filter(explode("\n", $run['stdout']), fn ($line) => trim($line) !== ''));
        self::assertNotEmpty($lines);
        $codes = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            self::assertIsArray($decoded, "Non JSON-RPC output on stdout: {$line}");
            self::assertSame('2.0', $decoded['jsonrpc']);
            $codes[] = $decoded['error']['code'] ?? null;
        }
        self::assertContains(-32700, $codes);
        self::assertStringContainsString('StdioTransport', $run['stderr']);
        self::assertStringNotContainsString('StdioTransport', $run['stdout']);
    }

    public function test_an_invalid_root_fails_at_startup_without_touching_stdout(): void
    {
        [$process, $pipes] = $this->spawn([$this->packageRoot() . '/vendor/bin/testbench', 'agent-kit:mcp', '--path=/definitely/missing/root']);
        fclose($pipes[0]);

        $run = $this->finish($process, $pipes);

        self::assertSame(1, $run['status']);
        self::assertSame('', $run['stdout']);
        self::assertStringContainsString('does not exist or is not a directory', $run['stderr']);
    }

    public function test_a_disabled_server_refuses_to_start(): void
    {
        [$process, $pipes] = $this->spawn($this->serverArguments(), ['AGENT_KIT_MCP_ENABLED' => 'false']);
        fclose($pipes[0]);

        $run = $this->finish($process, $pipes);

        self::assertSame(1, $run['status']);
        self::assertSame('', $run['stdout']);
        self::assertStringContainsString('AGENT_KIT_MCP_ENABLED', $run['stderr']);
    }

    public function test_an_unknown_transport_is_rejected(): void
    {
        [$process, $pipes] = $this->spawn($this->serverArguments(['--transport=carrier-pigeon']));
        fclose($pipes[0]);

        $run = $this->finish($process, $pipes);

        self::assertSame(1, $run['status']);
        self::assertStringContainsString('Unsupported MCP transport', $run['stderr']);
    }

    public function test_sigterm_stops_the_server_with_exit_code_zero(): void
    {
        if (!function_exists('posix_kill') || !function_exists('pcntl_signal')) {
            self::markTestSkipped('pcntl/posix are required for signal handling.');
        }
        [$process, $pipes] = $this->spawn($this->serverArguments(['--transport=stdio']));
        // Wait until the server logs that it is listening before signalling it.
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + 20;
        $stderr = '';
        while (microtime(true) < $deadline && !str_contains($stderr, 'listening')) {
            $stderr .= (string) stream_get_contents($pipes[2]);
            usleep(50000);
        }
        self::assertStringContainsString('listening', $stderr);

        posix_kill(proc_get_status($process)['pid'], SIGTERM);
        $run = $this->finish($process, $pipes, 10);

        self::assertSame(0, $run['status'], $run['stderr']);
    }

    public function test_spawning_the_server_leaves_no_env_example_copy_in_the_testbench_skeleton(): void
    {
        [$process, $pipes] = $this->spawn($this->serverArguments(['--transport=stdio']));
        fclose($pipes[0]);

        $this->finish($process, $pipes);

        $skeletonEnvironmentFile = $this->packageRoot() . '/vendor/orchestra/testbench-core/laravel/.env';
        $leftBehindIsHarmless = !is_file($skeletonEnvironmentFile)
            || file_get_contents($skeletonEnvironmentFile) === file_get_contents($this->packageRoot() . '/tests/Fixtures/Mcp/testbench.env');
        self::assertTrue($leftBehindIsHarmless, "{$skeletonEnvironmentFile} exists and does not match the harmless fixture.");
    }
}
