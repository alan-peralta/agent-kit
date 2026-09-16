<?php

namespace Peralta\AgentKit\Refactoring\Mcp;

use Illuminate\Log\LogManager;
use Monolog\Handler\StreamHandler;
use Psr\Log\LoggerInterface;

final class McpLoggerFactory
{
    public function __construct(private readonly LogManager $log) {}

    /** @param array{level?: string|null, channel?: string|null} $config */
    public function create(array $config): LoggerInterface
    {
        $channel = $config['channel'] ?? null;
        if (is_string($channel) && $channel !== '') {
            return $this->log->channel($channel);
        }

        // stdout is reserved for JSON-RPC on stdio; every log line goes to stderr.
        return $this->log->build([
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => ['stream' => 'php://stderr'],
            'level' => $config['level'] ?? 'info',
        ]);
    }
}
