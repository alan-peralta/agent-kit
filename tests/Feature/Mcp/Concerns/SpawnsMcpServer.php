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

    /**
     * @param  list<string>  $extra
     * @param  string|null  $path  project root to serve; defaults to the AST fixture root
     * @return list<string>
     */
    protected function serverArguments(array $extra = [], ?string $path = null): array
    {
        return array_merge(
            [$this->packageRoot() . '/vendor/bin/testbench', 'agent-kit:mcp', '--path=' . ($path ?? $this->fixtureRoot())],
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
        $this->placeSkeletonEnvironmentFile();

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
     * The trimmed `pgrep -f` output for servers spawned with $arguments, so a test can assert both
     * that a spawned server is really running and that it is really gone afterwards.
     *
     * The pattern is derived from the argv we actually spawn instead of being hard-coded, because a
     * hard-coded one silently drifts: spawn() runs `<php> <testbench> agent-kit:mcp --path=<root>
     * --transport=stdio`, so a literal "agent-kit:mcp --transport=stdio" never appears in the child's
     * command line and such a pgrep can never match. `pgrep -f` matches an extended regex against the
     * whole (space-joined) command line, so we only have to escape the ERE metacharacters.
     *
     * @param list<string> $arguments the same array that was handed to spawn()
     */
    private function runningServerProcesses(array $arguments): string
    {
        $pattern = implode(' ', array_map(
            static fn (string $argument): string => preg_replace('/[.\\\\*+?\[\]^$(){}|]/', '\\\\$0', $argument),
            $arguments,
        ));

        // shell_exec() runs this through `sh -c`, whose own argv contains the pattern verbatim, so on a
        // package path without regex metacharacters the pattern would also match that wrapper and the
        // "no orphan process" assertion would fail spuriously. Splitting the first character into a
        // bracket expression still matches the server's real `agent-kit:mcp ...` argv, while the
        // wrapper's literal `[a]gent-kit:mcp` can never match it.
        $pattern = preg_replace('/agent-kit:mcp/', '[a]gent-kit:mcp', $pattern, 1);

        return trim((string) shell_exec('pgrep -f ' . escapeshellarg($pattern) . ' || true'));
    }

    private function skeletonEnvironmentFile(): string
    {
        return $this->packageRoot() . '/vendor/orchestra/testbench-core/laravel/.env';
    }

    /**
     * `Orchestra\Testbench\Console\Commander` (what `vendor/bin/testbench` runs) declares its own
     * `$environmentFile = '.env'` property, so `CopyTestbenchFiles::testbenchEnvironmentFile()`'s
     * `property_exists($this, 'environmentFile')` branch always wins before it ever checks the
     * `TESTBENCH_ENVIRONMENT_FILENAME` env var - that env var is dead code for this CLI. But
     * `Commander::laravel()` only copies an env file in at all when the skeleton has none yet
     * (`is_file(<skeleton>/.env)`), so pre-placing our harmless fixture there ourselves makes the
     * CLI skip the `.env.example` copy entirely and load our fixture instead, on every exit path
     * including a SIGKILL that skips Testbench's own cleanup.
     */
    private function placeSkeletonEnvironmentFile(): void
    {
        $skeletonEnvironmentFile = $this->skeletonEnvironmentFile();
        if (is_file($skeletonEnvironmentFile)) {
            return;
        }

        $fixture = $this->packageRoot() . '/tests/Fixtures/Mcp/testbench.env';
        if (!@copy($fixture, $skeletonEnvironmentFile)) {
            self::fail("Could not copy the harmless testbench env fixture from {$fixture} to {$skeletonEnvironmentFile}.");
        }
    }

    /**
     * Best-effort cleanup: undo placeSkeletonEnvironmentFile() so the next spawn() (or any other
     * test that boots the Testbench skeleton) sees a clean slate. Only remove the skeleton .env
     * when its contents still match our harmless fixture, so a real .env some other tool put
     * there - including one Testbench itself may have left after a graceful run that predates our
     * fixture being placed - is never touched.
     */
    private function removeSkeletonEnvironmentFile(): void
    {
        $skeletonEnvironmentFile = $this->skeletonEnvironmentFile();
        $fixture = $this->packageRoot() . '/tests/Fixtures/Mcp/testbench.env';
        if (!is_file($skeletonEnvironmentFile) || !is_file($fixture)) {
            return;
        }

        if (file_get_contents($skeletonEnvironmentFile) === file_get_contents($fixture)) {
            @unlink($skeletonEnvironmentFile);
        }
    }
}
