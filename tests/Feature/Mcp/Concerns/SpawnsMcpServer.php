<?php

namespace Peralta\AgentKit\Tests\Feature\Mcp\Concerns;

trait SpawnsMcpServer
{
    protected function packageRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    protected function fixtureRoot(): string
    {
        return $this->packageRoot() . '/tests/Fixtures/Refactoring/Ast';
    }

    /** @return list<string> */
    protected function serverArguments(array $extra = []): array
    {
        return array_merge(
            [$this->packageRoot() . '/vendor/bin/testbench', 'agent-kit:mcp', '--path=' . $this->fixtureRoot()],
            $extra,
        );
    }

    /** @return array<string, string> */
    protected function serverEnvironment(array $overrides = []): array
    {
        $environment = array_filter(getenv(), 'is_string');
        foreach (array_keys($environment) as $name) {
            if (str_starts_with($name, 'AGENT_KIT_MCP_')) {
                unset($environment[$name]);
            }
        }

        return array_merge($environment, ['APP_ENV' => 'testing'], $overrides);
    }

    /**
     * @return array{0: resource, 1: array{0: resource, 1: resource, 2: resource}}
     */
    protected function spawn(array $arguments, array $environment = []): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->packageRoot(),
            $this->serverEnvironment($environment),
        );
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    /** @return array{status: int, stdout: string, stderr: string} */
    protected function finish($process, array $pipes, float $timeoutSeconds = 30): array
    {
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);

        if ($status['running']) {
            proc_terminate($process, 9);
            self::fail("The MCP server did not exit within {$timeoutSeconds}s.\nSTDERR:\n{$stderr}");
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);

        return ['status' => $status['exitcode'], 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
