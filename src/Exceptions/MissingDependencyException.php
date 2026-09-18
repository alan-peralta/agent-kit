<?php

namespace Peralta\AgentKit\Exceptions;

use RuntimeException;

/**
 * A feature needs a Composer package that Agent Kit only suggests. The MCP server and the
 * Refactoring Agent's AST index are development tools, so the hint installs with --dev.
 */
final class MissingDependencyException extends RuntimeException
{
    public function __construct(
        public readonly string $package,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forFeature(string $feature, string $package): self
    {
        return new self($package, "{$feature} requires {$package}. Install it with: composer require --dev {$package}");
    }
}
