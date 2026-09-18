<?php

namespace Peralta\AgentKit\Refactoring\Mcp;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/** Drops records below a minimum PSR-3 level before they reach a logger whose own level is not ours to set. */
final class LevelFilteringLogger extends AbstractLogger
{
    private const SEVERITY = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    private readonly int $minimum;

    // An unrecognised minimum level drops nothing, so a typo in the setting never silences the log.
    public function __construct(private readonly LoggerInterface $inner, string $level)
    {
        $this->minimum = self::SEVERITY[strtolower($level)] ?? 0;
    }

    public function log($level, $message, array $context = []): void
    {
        $severity = is_string($level) ? (self::SEVERITY[strtolower($level)] ?? null) : null;
        if ($severity !== null && $severity < $this->minimum) {
            return;
        }

        $this->inner->log($level, $message, $context);
    }
}
