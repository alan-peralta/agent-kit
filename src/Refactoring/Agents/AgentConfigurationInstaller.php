<?php

namespace Peralta\AgentKit\Refactoring\Agents;

use Closure;
use InvalidArgumentException;
use RuntimeException;

final class AgentConfigurationInstaller
{
    /** @param null|Closure(string, string): bool $rename */
    public function __construct(
        private readonly AgentAdapterRegistry $registry,
        private readonly AgentCommandRepository $repository,
        private readonly AgentTemplateRenderer $renderer,
        private readonly ?Closure $rename = null,
    ) {}

    /** @param list<string> $agentIds */
    public function install(string $projectRoot, array $agentIds, bool $force = false): InstallationResult
    {
        $root = $this->canonicalRoot($projectRoot);
        $artifacts = $this->artifacts($agentIds);
        $created = [];
        $unchanged = [];
        $conflicts = [];
        $overwritten = [];

        foreach ($artifacts as $path => $content) {
            $target = $root . '/' . $path;
            $this->assertContainedAncestors($root, $path);

            if (is_link($target)) {
                if ($force) {
                    throw new RuntimeException("Refusing to overwrite generated agent symlink: {$path}");
                }

                $conflicts[] = $path;
                continue;
            }

            if (file_exists($target)) {
                if (!is_file($target) || !is_readable($target)) {
                    throw new RuntimeException("Unable to read existing agent file: {$path}");
                }

                $existing = @file_get_contents($target);
                if ($existing === false) {
                    throw new RuntimeException("Unable to read existing agent file: {$path}");
                }

                if ($existing === $content) {
                    $unchanged[] = $path;
                    continue;
                }

                if (!$force) {
                    $conflicts[] = $path;
                    continue;
                }

                $this->writeAtomically($root, $path, $content);
                $overwritten[] = $path;
                continue;
            }

            $this->writeAtomically($root, $path, $content);
            $created[] = $path;
        }

        return new InstallationResult($created, $unchanged, $conflicts, $overwritten);
    }

    private function canonicalRoot(string $projectRoot): string
    {
        if ($projectRoot === '' || str_contains($projectRoot, "\0")) {
            throw new InvalidArgumentException("Project root is not an existing directory: {$projectRoot}");
        }

        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) {
            throw new InvalidArgumentException("Project root is not an existing directory: {$projectRoot}");
        }

        return rtrim(str_replace('\\', '/', $root), '/') ?: '/';
    }

    /**
     * @param list<string> $agentIds
     * @return array<string, string>
     */
    private function artifacts(array $agentIds): array
    {
        $artifacts = [];

        foreach ($agentIds as $id) {
            foreach ($this->registry->get($id)->generate($this->repository, $this->renderer) as $file) {
                $path = $this->normalizePath($file->path);

                if (isset($artifacts[$path])) {
                    if ($artifacts[$path] !== $file->content) {
                        throw new InvalidArgumentException("Generated agent path collision: {$path}");
                    }

                    continue;
                }

                $artifacts[$path] = $file->content;
            }
        }

        return $artifacts;
    }

    private function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $segments = explode('/', $normalized);

        if (
            $path === ''
            || str_contains($path, "\0")
            || str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:/', $normalized) === 1
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            throw new InvalidArgumentException("Unsafe generated agent path: {$path}");
        }

        return $normalized;
    }

    private function assertContainedAncestors(string $root, string $relativePath): void
    {
        $segments = explode('/', $relativePath);
        array_pop($segments);
        $current = $root;

        foreach ($segments as $segment) {
            $current .= '/' . $segment;

            if (!file_exists($current) && !is_link($current)) {
                break;
            }

            if (!is_dir($current)) {
                throw new RuntimeException("Generated agent parent is not a directory: {$relativePath}");
            }

            $canonical = realpath($current);
            if ($canonical === false || !$this->isWithinRoot($root, str_replace('\\', '/', $canonical))) {
                throw new RuntimeException("Generated agent path escapes project root through symlink: {$relativePath}");
            }
        }
    }

    private function isWithinRoot(string $root, string $path): bool
    {
        return $path === $root || str_starts_with($path, $root === '/' ? '/' : $root . '/');
    }

    private function writeAtomically(string $root, string $relativePath, string $content): void
    {
        $target = $root . '/' . $relativePath;
        $this->createDirectory($root, $relativePath);
        $this->assertContainedAncestors($root, $relativePath);

        if (is_link($target)) {
            throw new RuntimeException("Refusing to overwrite generated agent symlink: {$relativePath}");
        }

        $permissions = is_file($target) ? @fileperms($target) : false;
        $mode = $permissions !== false ? $permissions & 0777 : null;
        [$handle, $temporary] = $this->openTemporaryFile($target, $relativePath);

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException("Could not lock temporary agent file: {$relativePath}");
            }

            $remaining = $content;
            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);
                if ($written === false || $written === 0) {
                    throw new RuntimeException("Could not write temporary agent file: {$relativePath}");
                }
                $remaining = substr($remaining, $written);
            }

            if (!fflush($handle)) {
                throw new RuntimeException("Could not flush temporary agent file: {$relativePath}");
            }

            if ($mode !== null && !chmod($temporary, $mode)) {
                throw new RuntimeException("Could not preserve agent file permissions: {$relativePath}");
            }

            flock($handle, LOCK_UN);
            fclose($handle);
            $handle = null;

            if (!$this->replaceTarget($temporary, $target, $relativePath)) {
                throw new RuntimeException("Could not install agent file: {$relativePath}");
            }
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }

            if (file_exists($temporary) || is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function replaceTarget(string $temporary, string $target, string $relativePath): bool
    {
        if ($this->rename !== null) {
            return ($this->rename)($temporary, $target);
        }

        if (@rename($temporary, $target)) {
            return true;
        }

        if (DIRECTORY_SEPARATOR !== '\\' || !is_file($target)) {
            return false;
        }

        $backup = $target . '.agent-kit-backup-' . bin2hex(random_bytes(8));
        if (!@rename($target, $backup)) {
            return false;
        }

        if (@rename($temporary, $target)) {
            @unlink($backup);

            return true;
        }

        if (!@rename($backup, $target)) {
            throw new RuntimeException("Could not restore agent file after failed install: {$relativePath}");
        }

        return false;
    }

    private function createDirectory(string $root, string $relativePath): void
    {
        $segments = explode('/', dirname($relativePath));
        $current = $root;

        foreach ($segments as $segment) {
            if ($segment === '.') {
                continue;
            }

            $current .= '/' . $segment;
            if (is_dir($current)) {
                continue;
            }

            if (file_exists($current) || is_link($current)) {
                throw new RuntimeException("Could not create agent directory: {$relativePath}");
            }

            if (!@mkdir($current, 0777) && !is_dir($current)) {
                throw new RuntimeException("Could not create agent directory: {$relativePath}");
            }
        }
    }

    /** @return array{0: resource, 1: string} */
    private function openTemporaryFile(string $target, string $relativePath): array
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                $suffix = bin2hex(random_bytes(8));
            } catch (\Throwable $exception) {
                throw new RuntimeException("Could not create temporary agent filename: {$relativePath}", 0, $exception);
            }

            $temporary = $target . '.agent-kit-' . $suffix;
            $handle = @fopen($temporary, 'x+b');

            if ($handle !== false) {
                return [$handle, $temporary];
            }
        }

        throw new RuntimeException("Could not create temporary agent file: {$relativePath}");
    }
}
