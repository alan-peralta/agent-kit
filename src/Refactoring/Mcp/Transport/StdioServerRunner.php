<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport;

use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Transport\StdioTransport;
use Peralta\AgentKit\Refactoring\Analysis\Index\CachedCodebaseIndexer;
use Peralta\AgentKit\Refactoring\Mcp\McpProjectRoot;
use Peralta\AgentKit\Refactoring\Mcp\McpServerFactory;
use Psr\Log\LoggerInterface;

final class StdioServerRunner
{
    public function __construct(
        private readonly McpServerFactory $factory,
        private readonly CachedCodebaseIndexer $indexCache,
    ) {}

    /**
     * @param resource $input
     * @param resource $output
     */
    public function run(McpProjectRoot $root, LoggerInterface $logger, $input, $output): int
    {
        // One long-lived client per process: the session must never expire while idle,
        // and garbage collection has nothing to collect.
        $server = $this->factory->create($root, $logger, new InMemorySessionStore(PHP_INT_MAX), gcProbability: 0);
        $control = new StdioRunnerControl();
        $transport = new StdioTransport($input, $output, $logger, $control);
        $restoreSignals = $this->installSignalHandlers($control, $logger);

        try {
            $logger->info('MCP stdio server listening.', ['project_root' => $root->path]);

            return (int) $server->run($transport);
        } finally {
            $restoreSignals();
            $this->indexCache->clear();
        }
    }

    private function installSignalHandlers(StdioRunnerControl $control, LoggerInterface $logger): \Closure
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return static function (): void {};
        }

        pcntl_async_signals(true);
        $handler = static function (int $signal) use ($control, $logger): void {
            $logger->info('Stopping MCP stdio server on signal.', ['signal' => $signal]);
            $control->stop();
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);

        return static function (): void {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
        };
    }
}
