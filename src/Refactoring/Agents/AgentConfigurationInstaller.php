<?php

namespace Peralta\AgentKit\Refactoring\Agents;

use InvalidArgumentException;
use RuntimeException;

/**
 * Installs generated files into a project tree that is trusted against hostile concurrent renames.
 *
 * Destination symlinks are never followed. Parent symlinks that resolve outside the root are
 * rejected; broken symlinks whose lexical target stays inside the root are content conflicts.
 * Parent containment is revalidated around publication, but PHP does not expose portable
 * openat(2) primitives needed to resist an adversary mutating the tree concurrently.
 */
final class AgentConfigurationInstaller
{
    public function __construct(
        private readonly AgentAdapterRegistry $registry,
        private readonly AgentCommandRepository $repository,
        private readonly AgentTemplateRenderer $renderer,
        private readonly ?AgentInstallationFilesystem $filesystem = null,
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
            if (!$this->parentPathIsUsable($root, $path)) {
                $conflicts[] = $path;
                continue;
            }

            if (is_link($target)) {
                $conflicts[] = $path;
                continue;
            }

            if (file_exists($target)) {
                if (!is_file($target) || !is_readable($target)) {
                    $conflicts[] = $path;
                    continue;
                }

                $existing = @file_get_contents($target);
                if ($existing === false) {
                    $conflicts[] = $path;
                    continue;
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

            $status = $this->createAtomically($root, $path, $content);
            if ($status === 'created') {
                $created[] = $path;
            } elseif ($status === 'unchanged') {
                $unchanged[] = $path;
            } else {
                $conflicts[] = $path;
            }
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
        $identities = [];

        foreach ($agentIds as $id) {
            foreach ($this->registry->get($id)->generate($this->repository, $this->renderer) as $file) {
                $path = $this->normalizePath($file->path);
                $identity = strtolower($path);

                if (isset($identities[$identity])) {
                    $existingPath = $identities[$identity];

                    if ($existingPath !== $path) {
                        throw new InvalidArgumentException("Generated agent path collision: {$existingPath} and {$path}");
                    }

                    if ($artifacts[$path] !== $file->content) {
                        throw new InvalidArgumentException("Generated agent path collision: {$path}");
                    }

                    continue;
                }

                $artifacts[$path] = $file->content;
                $identities[$identity] = $path;
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
            || preg_match('/\A[A-Za-z0-9._\/-]+\z/D', $normalized) !== 1
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || $this->hasWindowsReservedSegment($segments)
        ) {
            throw new InvalidArgumentException("Unsafe generated agent path: {$path}");
        }

        return $normalized;
    }

    /** @param list<string> $segments */
    private function hasWindowsReservedSegment(array $segments): bool
    {
        foreach ($segments as $segment) {
            if (str_ends_with($segment, '.') || str_ends_with($segment, ' ')) {
                return true;
            }

            $basename = strtoupper(explode('.', $segment, 2)[0]);
            if (preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/', $basename) === 1) {
                return true;
            }
        }

        return false;
    }

    private function parentPathIsUsable(string $root, string $relativePath): bool
    {
        $segments = explode('/', $relativePath);
        array_pop($segments);
        $current = $root;

        foreach ($segments as $segment) {
            $current .= '/' . $segment;

            if (!file_exists($current) && !is_link($current)) {
                break;
            }

            if (is_link($current)) {
                $canonical = realpath($current);

                if ($canonical === false) {
                    if (!$this->brokenSymlinkTargetsWithinRoot($root, $current)) {
                        throw new RuntimeException("Generated agent path escapes project root through symlink: {$relativePath}");
                    }

                    return false;
                }

                $canonical = str_replace('\\', '/', $canonical);
                if (!$this->isWithinRoot($root, $canonical)) {
                    throw new RuntimeException("Generated agent path escapes project root through symlink: {$relativePath}");
                }

                if (!is_dir($current)) {
                    return false;
                }

                continue;
            }

            if (!is_dir($current)) {
                return false;
            }

            $canonical = realpath($current);
            if ($canonical === false || !$this->isWithinRoot($root, str_replace('\\', '/', $canonical))) {
                throw new RuntimeException("Generated agent path escapes project root through symlink: {$relativePath}");
            }
        }

        return true;
    }

    private function brokenSymlinkTargetsWithinRoot(string $root, string $link): bool
    {
        $target = readlink($link);
        if ($target === false || $target === '') {
            return false;
        }

        $candidate = str_starts_with($target, '/')
            ? $target
            : dirname($link) . '/' . $target;
        $candidate = $this->normalizeAbsolutePath($candidate);
        $probe = $candidate;
        $suffix = [];

        while (!file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                return false;
            }

            array_unshift($suffix, basename($probe));
            $probe = $parent;
        }

        $canonical = realpath($probe);
        if ($canonical === false) {
            return false;
        }

        $candidate = $this->normalizeAbsolutePath(
            str_replace('\\', '/', $canonical) . ($suffix === [] ? '' : '/' . implode('/', $suffix)),
        );

        return $this->isWithinRoot($root, $candidate);
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }

    private function isWithinRoot(string $root, string $path): bool
    {
        return $path === $root || str_starts_with($path, $root === '/' ? '/' : $root . '/');
    }

    private function writeAtomically(string $root, string $relativePath, string $content): void
    {
        $target = $root . '/' . $relativePath;
        $this->createDirectory($root, $relativePath);
        if (!$this->parentPathIsUsable($root, $relativePath)) {
            throw new RuntimeException("Generated agent parent is not a directory: {$relativePath}");
        }

        if (is_link($target)) {
            throw new RuntimeException("Refusing to overwrite generated agent symlink: {$relativePath}");
        }

        $permissions = is_file($target) ? @fileperms($target) : false;
        $mode = $permissions !== false ? $permissions & 0777 : null;
        $temporary = $this->writeTemporaryFile($target, $relativePath, $content, $mode);

        try {
            if (!$this->parentPathIsUsable($root, $relativePath)) {
                throw new RuntimeException("Generated agent parent is not a directory: {$relativePath}");
            }
            if (!$this->replaceTarget($temporary, $target, $relativePath)) {
                throw new RuntimeException("Could not install agent file: {$relativePath}");
            }
        } finally {
            if (file_exists($temporary) || is_link($temporary)) {
                if (!$this->unlink($temporary)) {
                    throw new RuntimeException("Could not remove temporary agent file: {$temporary}");
                }
            }
        }
    }

    /** @return 'created'|'unchanged'|'conflict' */
    private function createAtomically(string $root, string $relativePath, string $content): string
    {
        $target = $root . '/' . $relativePath;
        $this->createDirectory($root, $relativePath);
        if (!$this->parentPathIsUsable($root, $relativePath)) {
            throw new RuntimeException("Generated agent parent is not a directory: {$relativePath}");
        }
        $temporary = $this->writeTemporaryFile($target, $relativePath, $content, null);

        try {
            if (!$this->parentPathIsUsable($root, $relativePath)) {
                throw new RuntimeException("Generated agent parent is not a directory: {$relativePath}");
            }

            if ($this->link($temporary, $target)) {
                return 'created';
            }

            if (!file_exists($target) && !is_link($target)) {
                throw new RuntimeException("Could not create agent file: {$relativePath}");
            }

            if (is_link($target) || !is_file($target) || !is_readable($target)) {
                return 'conflict';
            }

            $existing = @file_get_contents($target);

            return $existing !== false && $existing === $content ? 'unchanged' : 'conflict';
        } finally {
            if ((file_exists($temporary) || is_link($temporary)) && !$this->unlink($temporary)) {
                throw new RuntimeException("Could not remove temporary agent file: {$temporary}");
            }
        }
    }

    private function replaceTarget(string $temporary, string $target, string $relativePath): bool
    {
        if ($this->rename($temporary, $target)) {
            return true;
        }

        if (!$this->requiresBackupForOverwrite() || !is_file($target)) {
            return false;
        }

        $backup = $this->unusedBackupPath($target);
        if (!$this->rename($target, $backup)) {
            return false;
        }

        if ($this->rename($temporary, $target)) {
            if (!$this->unlink($backup)) {
                throw new RuntimeException("Could not remove agent backup after install: {$backup}");
            }

            return true;
        }

        if (!$this->rename($backup, $target)) {
            throw new RuntimeException("Could not restore agent file after failed install; recovery file: {$backup}");
        }

        return false;
    }

    private function unusedBackupPath(string $target): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $backup = $target . '.agent-kit-backup-' . bin2hex(random_bytes(8));
            if (!file_exists($backup) && !is_link($backup)) {
                return $backup;
            }
        }

        throw new RuntimeException("Could not reserve agent backup path: {$target}");
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

    private function writeTemporaryFile(string $target, string $relativePath, string $content, ?int $mode): string
    {
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

            return $temporary;
        } catch (\Throwable $exception) {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
            if ((file_exists($temporary) || is_link($temporary)) && !$this->unlink($temporary)) {
                throw new RuntimeException("Could not remove temporary agent file: {$temporary}", 0, $exception);
            }

            throw $exception;
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

    private function link(string $temporary, string $target): bool
    {
        return $this->filesystem !== null
            ? $this->filesystem->link($temporary, $target)
            : @link($temporary, $target);
    }

    private function rename(string $from, string $to): bool
    {
        return $this->filesystem !== null
            ? $this->filesystem->rename($from, $to)
            : @rename($from, $to);
    }

    private function unlink(string $path): bool
    {
        return $this->filesystem !== null
            ? $this->filesystem->unlink($path)
            : @unlink($path);
    }

    private function requiresBackupForOverwrite(): bool
    {
        return $this->filesystem !== null
            ? $this->filesystem->requiresBackupForOverwrite()
            : DIRECTORY_SEPARATOR === '\\';
    }
}
