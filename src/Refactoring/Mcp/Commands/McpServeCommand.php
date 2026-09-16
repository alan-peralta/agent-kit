<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Commands;

use Illuminate\Console\Command;
use Peralta\AgentKit\Refactoring\Mcp\McpConfigurationException;
use Peralta\AgentKit\Refactoring\Mcp\McpLoggerFactory;
use Peralta\AgentKit\Refactoring\Mcp\McpProjectRoot;
use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\HttpServerOptions;
use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\ReactHttpListener;
use Peralta\AgentKit\Refactoring\Mcp\Transport\StdioServerRunner;
use Psr\Log\LoggerInterface;

final class McpServeCommand extends Command
{
    protected $signature = 'agent-kit:mcp
        {--transport= : stdio or http; defaults to agent-kit.mcp.transport}
        {--path= : Project root to analyze; defaults to agent-kit.mcp.project_root or the Laravel base path}
        {--host= : HTTP bind host; defaults to agent-kit.mcp.http.host (127.0.0.1)}
        {--port= : HTTP bind port; defaults to agent-kit.mcp.http.port (8787)}
        {--allow-remote : Allow the HTTP transport to bind a non-loopback interface (a bearer token is still required)}';

    protected $description = 'Serve the Agent Kit refactoring capabilities to MCP clients (stdio or Streamable HTTP)';

    public function handle(StdioServerRunner $stdio, ReactHttpListener $http, McpLoggerFactory $loggers): int
    {
        $config = (array) config('agent-kit.mcp', []);
        if (!($config['enabled'] ?? true)) {
            return $this->refuse('The MCP server is disabled (AGENT_KIT_MCP_ENABLED=false).');
        }

        // stdout is the MCP wire on stdio; PHP notices and deprecations must not reach it.
        ini_set('display_errors', 'stderr');

        $transport = strtolower((string) ($this->option('transport') ?: ($config['transport'] ?? 'stdio')));

        try {
            $root = McpProjectRoot::fromPath((string) ($this->option('path') ?: ($config['project_root'] ?: base_path())));
            $logger = $loggers->create((array) ($config['logging'] ?? []));

            return match ($transport) {
                'stdio' => $this->serveStdio($stdio, $root, $logger),
                'http' => $http->listen($this->httpOptions((array) ($config['http'] ?? [])), $root, $logger),
                default => $this->refuse("Unsupported MCP transport: {$transport}. Use stdio or http."),
            };
        } catch (McpConfigurationException $exception) {
            return $this->refuse($exception->getMessage());
        }
    }

    private function serveStdio(StdioServerRunner $stdio, McpProjectRoot $root, LoggerInterface $logger): int
    {
        return $stdio->run($root, $logger, STDIN, STDOUT);
    }

    private function httpOptions(array $config): HttpServerOptions
    {
        $port = $this->option('port');

        return HttpServerOptions::fromConfig(
            $config,
            host: $this->option('host') ?: null,
            port: is_numeric($port) ? (int) $port : null,
            allowRemote: (bool) $this->option('allow-remote'),
        );
    }

    // Not named fail(): Laravel 11+ Command::fail() exists and throws.
    private function refuse(string $message): int
    {
        $this->output->getErrorStyle()->writeln('<error>' . $message . '</error>');

        return self::FAILURE;
    }
}
