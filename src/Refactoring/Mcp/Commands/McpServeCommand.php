<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Commands;

use Illuminate\Console\Command;
use Mcp\Server;
use Peralta\AgentKit\Exceptions\MissingDependencyException;
use Peralta\AgentKit\Refactoring\Mcp\McpConfigurationException;
use Peralta\AgentKit\Refactoring\Mcp\McpLoggerFactory;
use Peralta\AgentKit\Refactoring\Mcp\McpProjectRoot;
use Peralta\AgentKit\Refactoring\Mcp\Transport\StdioServerRunner;
use Psr\Log\LoggerInterface;

final class McpServeCommand extends Command
{
    protected $signature = 'agent-kit:mcp
        {--transport= : stdio or http; defaults to agent-kit.mcp.transport}
        {--path= : Project root to analyze; defaults to agent-kit.mcp.project_root or the Laravel base path}';

    protected $description = 'Serve the Agent Kit refactoring capabilities to MCP clients (stdio or Streamable HTTP)';

    public function handle(StdioServerRunner $stdio, McpLoggerFactory $loggers): int
    {
        $config = (array) config('agent-kit.mcp', []);
        if (!($config['enabled'] ?? true)) {
            return $this->refuse('The MCP server is disabled (AGENT_KIT_MCP_ENABLED=false).');
        }

        // stdout is the MCP wire on stdio; PHP notices and deprecations must not reach it.
        ini_set('display_errors', 'stderr');

        $transport = strtolower((string) ($this->option('transport') ?: ($config['transport'] ?? 'stdio')));

        try {
            // mcp/sdk is only suggested: say what to install instead of failing deep inside the SDK.
            if (!class_exists(Server::class)) {
                throw MissingDependencyException::forFeature('The MCP server', 'mcp/sdk');
            }

            $root = McpProjectRoot::fromPath((string) ($this->option('path') ?: ($config['project_root'] ?: base_path())));
            $logger = $loggers->create((array) ($config['logging'] ?? []));

            return match ($transport) {
                'stdio' => $this->serveStdio($stdio, $root, $logger),
                'http' => $this->refuse($this->httpUnavailableMessage((array) ($config['http'] ?? []))),
                default => $this->refuse("Unsupported MCP transport: {$transport}. Use stdio or http."),
            };
        } catch (McpConfigurationException|MissingDependencyException $exception) {
            return $this->refuse($exception->getMessage());
        }
    }

    private function serveStdio(StdioServerRunner $stdio, McpProjectRoot $root, LoggerInterface $logger): int
    {
        // The SDK transport closes the streams it is given when the session ends. Hand it
        // duplicates so the process's own STDIN/STDOUT stay open for whatever still runs
        // afterwards (Laravel's error rendering, a console application's terminating callbacks).
        return $stdio->run($root, $logger, fopen('php://stdin', 'r'), fopen('php://stdout', 'w'));
    }

    /**
     * The Streamable HTTP transport is now served by a route of the host application, not by
     * this command: the ReactPHP HTTP server this used to run on cannot coexist with Guzzle 8,
     * which the rest of the package depends on. Point the operator at the route instead of
     * trying to listen here.
     */
    private function httpUnavailableMessage(array $config): string
    {
        $path = '/' . trim((string) ($config['path'] ?? '/mcp'), '/');

        return "The Streamable HTTP transport is served by your application at {$path} when AGENT_KIT_MCP_HTTP_ENABLED=true; start it with php artisan serve or your web server. See MCP_SERVER.md.";
    }

    // Not named fail(): Laravel 11+ Command::fail() exists and throws.
    private function refuse(string $message): int
    {
        $this->output->getErrorStyle()->writeln('<error>' . $message . '</error>');

        return self::FAILURE;
    }
}
