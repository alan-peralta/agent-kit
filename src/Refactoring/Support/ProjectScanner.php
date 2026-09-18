<?php

namespace Peralta\AgentKit\Refactoring\Support;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ProjectScanner
{
    public function __construct(
        private readonly PhpFileAnalyzer $analyzer,
        private readonly array $excludedDirectories = [],
    ) {}

    public function phpFiles(string $root): array
    {
        $root = $this->normalizedRoot($root);
        if (!is_dir($root)) {
            throw new \InvalidArgumentException("Diretório não encontrado: {$root}");
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            // Prune excluded directories instead of filtering their files afterwards: a vendor
            // tree or an agent's worktrees can hold hundreds of thousands of files. The prune sees
            // the traversal path, not the canonical one; a file inside the root is always also
            // reachable through its own link-free path, so the canonical check below decides.
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                fn (SplFileInfo $entry): bool => !$entry->isDir() || !$this->isExcluded($entry->getPathname(), $root),
            ),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $canonical = realpath($path);
            if ($canonical === false
                || !ProjectRoot::contains($root, $canonical)
                || $this->isExcluded($canonical, $root)) {
                continue;
            }

            $files[$canonical] = $canonical;
        }

        sort($files, SORT_STRING);

        return array_values($files);
    }

    public function scan(string $root): array
    {
        $root = $this->normalizedRoot($root);
        $files = array_map(function (string $path) use ($root) {
            $relative = ProjectRoot::relative($root, $path);

            return $this->analyzer->analyze($path, $relative);
        }, $this->phpFiles($root));

        usort($files, fn ($a, $b) => count($b->smells) <=> count($a->smells) ?: $b->lines <=> $a->lines);

        return $files;
    }

    private function isExcluded(string $path, string $root): bool
    {
        $relative = ProjectRoot::relative($root, $path);
        foreach ($this->excludedDirectories as $directory) {
            $directory = trim(str_replace('\\', '/', $directory), '/');
            if ($directory !== '' && ($relative === $directory || str_starts_with($relative, $directory . '/'))) {
                return true;
            }
        }
        return false;
    }

    private function normalizedRoot(string $root): string
    {
        return ProjectRoot::normalize($root);
    }
}
