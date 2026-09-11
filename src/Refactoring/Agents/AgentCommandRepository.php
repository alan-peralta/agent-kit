<?php

namespace Peralta\AgentKit\Refactoring\Agents;

use InvalidArgumentException;
use RuntimeException;

final class AgentCommandRepository
{
    /** @var list<string> */
    private const COMMANDS = ['audit', 'analyze', 'callers', 'dependencies', 'impact', 'plan'];

    /** @var list<string> */
    private const RULES = ['core', 'laravel', 'smells', 'patterns'];

    public function __construct(private readonly string $root) {}

    /** @return list<string> */
    public function names(): array
    {
        return self::COMMANDS;
    }

    public function command(string $name): string
    {
        if (!in_array($name, self::COMMANDS, true)) {
            throw new InvalidArgumentException("Unknown refactoring agent command: {$name}");
        }

        return trim($this->read('instructions.md'))
            . "\n\n"
            . trim($this->read("commands/{$name}.md"))
            . "\n";
    }

    public function rules(): string
    {
        $rules = array_map(
            fn (string $name): string => trim($this->read("rules/{$name}.md")),
            self::RULES,
        );

        return implode("\n\n", $rules) . "\n";
    }

    private function read(string $path): string
    {
        $file = $this->root . '/' . $path;

        if (!is_file($file)) {
            throw new RuntimeException("Agent resource not found: {$path}");
        }

        if (!is_readable($file)) {
            throw new RuntimeException("Unable to read agent resource: {$path}");
        }

        $contents = @file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException("Unable to read agent resource: {$path}");
        }

        return $contents;
    }
}
