<?php

namespace Peralta\AgentKit\Refactoring\Support;

final class ProjectRoot
{
    public static function normalize(string $root): string
    {
        $root = realpath($root) ?: $root;
        if (dirname($root) === $root || preg_match('/^[A-Za-z]:[\\\\\/]$/', $root) === 1) {
            return $root;
        }

        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    public static function contains(string $root, string $path): bool
    {
        $root = self::normalize($root);

        return $path === $root || str_starts_with($path, self::prefix($root));
    }

    public static function relative(string $root, string $path): string
    {
        $root = self::normalize($root);
        $prefix = self::prefix($root);

        return str_starts_with($path, $prefix)
            ? str_replace('\\', '/', substr($path, strlen($prefix)))
            : str_replace('\\', '/', $path);
    }

    private static function prefix(string $root): string
    {
        return preg_match('/[\\\\\/]$/', $root) === 1 ? $root : $root . DIRECTORY_SEPARATOR;
    }
}
