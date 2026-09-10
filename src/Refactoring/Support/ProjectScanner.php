<?php

namespace Peralta\AgentKit\Refactoring\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

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
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if ($this->isExcluded($path, $root)) {
                continue;
            }

            $files[] = $path;
        }

        sort($files, SORT_STRING);

        return $files;
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
