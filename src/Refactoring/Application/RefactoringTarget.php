<?php

namespace Peralta\AgentKit\Refactoring\Application;

use InvalidArgumentException;

final readonly class RefactoringTarget
{
    private function __construct(
        public string $value,
        public ?string $method,
    ) {}

    public static function parse(string $target): self
    {
        $target = trim($target);

        if ($target === '' || substr_count($target, '::') > 1) {
            throw new InvalidArgumentException('The refactoring target is malformed.');
        }

        [$value, $method] = str_contains($target, '::')
            ? explode('::', $target, 2)
            : [$target, null];

        $value = ltrim(trim($value), '\\');
        $method = $method === null ? null : trim($method);

        if ($value === '' || $method === '') {
            throw new InvalidArgumentException('The refactoring target must contain a class and, when present, a method.');
        }

        return new self($value, $method);
    }
}
