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

        return array_merge($environment, [
            'APP_ENV' => 'testing',
            // Point Testbench's env-copy step at a harmless fixture instead of .env.example,
            // so a spawned server that ends abnormally leaves nothing meaningful behind.
            'TESTBENCH_ENVIRONMENT_FILENAME' => 'tests/Fixtures/Mcp/testbench.env',
        ], $overrides);
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

        $timedOut = $status['running'];
        if ($timedOut) {
            proc_terminate($process, 9);
            // Give the OS a moment to actually reap the killed process, draining whatever it
            // still flushes, so the cleanup below runs even in this abnormal-exit path -
            // the exact case that used to leave the Testbench skeleton .env behind.
            $killDeadline = microtime(true) + 2;
            do {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $killDeadline);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        $this->closeProcess($process, $pipes);

        if ($timedOut) {
            self::fail("The MCP server did not exit within {$timeoutSeconds}s.\nSTDERR:\n{$stderr}");
        }

        return ['status' => $status['exitcode'], 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Release the process's OS resources and run the best-effort skeleton-env cleanup. Shared by
     * the normal-exit and timeout paths in finish() so a force-killed process is cleaned up
     * exactly like a naturally-exited one, instead of skipping cleanup on the timeout path.
     */
    private function closeProcess($process, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
        $this->removeSkeletonEnvironmentFile();
    }

    /**
     * Best-effort cleanup: Testbench copies our fixture env into the skeleton app's .env on every
     * run. A process that ends abnormally (e.g. SIGKILL after a timeout) skips Testbench's own
     * termination cleanup and leaves that copy behind, where it pollutes every other test that
     * boots the Testbench skeleton. Only remove it when its contents still match our harmless
     * fixture, so a real .env some other tool put there is never touched.
     */
    private function removeSkeletonEnvironmentFile(): void
    {
        $skeletonEnvironmentFile = $this->packageRoot() . '/vendor/orchestra/testbench-core/laravel/.env';
        $fixture = $this->packageRoot() . '/tests/Fixtures/Mcp/testbench.env';
        if (!is_file($skeletonEnvironmentFile) || !is_file($fixture)) {
            return;
        }

        if (file_get_contents($skeletonEnvironmentFile) === file_get_contents($fixture)) {
            @unlink($skeletonEnvironmentFile);
        }
    }
}
