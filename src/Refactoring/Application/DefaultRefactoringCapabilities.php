<?php

namespace Peralta\AgentKit\Refactoring\Application;

use InvalidArgumentException;
use Peralta\AgentKit\Refactoring\Analysis\CallerAnalyzer;
use Peralta\AgentKit\Refactoring\Analysis\DTOs\SymbolDefinition;
use Peralta\AgentKit\Refactoring\Analysis\ImpactAnalyzer;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndex;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexer;
use Peralta\AgentKit\Refactoring\Support\PhpFileAnalyzer;
use Peralta\AgentKit\Refactoring\Support\ProjectScanner;
use Peralta\AgentKit\Refactoring\Support\RefactoringReport;

final class DefaultRefactoringCapabilities implements RefactoringCapabilities
{
    public function __construct(
        private readonly ProjectScanner $scanner,
        private readonly PhpFileAnalyzer $fileAnalyzer,
        private readonly RefactoringReport $report,
        private readonly CodebaseIndexer $indexer,
        private readonly CallerAnalyzer $callers,
        private readonly ImpactAnalyzer $impactAnalyzer,
    ) {}

    public function describeCapabilities(): CapabilityResult
    {
        return new CapabilityResult('capability_discovery', [
            'capabilities' => [
                [
                    'name' => 'audit',
                    'targets' => ['project'],
                    'cli_fallback' => 'php artisan agent-kit:refactor-audit --json',
                    'json' => true,
                ],
                [
                    'name' => 'analyze',
                    'targets' => ['file', 'class', 'method'],
                    'cli_fallback' => 'php artisan agent-kit:refactor-analyze <target> --json',
                    'json' => true,
                ],
                [
                    'name' => 'find_callers',
                    'targets' => ['class', 'method'],
                    'cli_fallback' => 'php artisan agent-kit:refactor-callers <class> --method=<method> --json',
                    'json' => true,
                ],
                [
                    'name' => 'dependencies',
                    'targets' => ['class'],
                    'cli_fallback' => 'php artisan agent-kit:refactor-dependencies <class> --json',
                    'json' => true,
                ],
                [
                    'name' => 'impact',
                    'targets' => ['class', 'method'],
                    'cli_fallback' => 'php artisan agent-kit:refactor-impact <class> --method=<method> --json',
                    'json' => true,
                ],
            ],
        ]);
    }

    public function audit(string $projectRoot): CapabilityResult
    {
        $root = $this->projectRoot($projectRoot);
        $files = $this->scanner->scan($root);

        return new CapabilityResult('audit', $this->report->build($files, $root));
    }

    public function analyze(string $projectRoot, string $target): CapabilityResult
    {
        $root = $this->projectRoot($projectRoot);
        $requested = $this->target($target);
        $index = $this->indexer->build($root);
        [$file, $displayPath, $symbol] = $this->analysisTarget($root, $requested, $index);

        if ($requested->method !== null && $symbol === null) {
            throw new CapabilityException(
                'UNSUPPORTED_TARGET',
                'A method target requires a class declaration.',
            );
        }
        if ($symbol !== null && $requested->method !== null) {
            $this->requireMethod($index, $symbol->fqcn, $requested->method);
        }

        $metrics = $this->fileAnalyzer->analyze($file, $displayPath)->toArray();
        $data = [
            'target' => $symbol?->fqcn ?? $requested->value,
            'method' => $requested->method,
            'metrics' => $metrics,
            'upstream_dependencies' => [],
            'direct_callers' => [],
            'structural_dependencies' => [],
            'transitive_impact' => [],
            'risk' => 'UNKNOWN',
        ];

        if ($symbol !== null) {
            $callerResult = $this->callers->findCallers($index, $symbol->fqcn, $requested->method);
            $impact = $this->impactAnalyzer->analyze($index, $symbol->fqcn, $requested->method);
            $data['upstream_dependencies'] = $this->edges($index->findDependencies($symbol->fqcn));
            $data['direct_callers'] = $callerResult->directCallers;
            $data['structural_dependencies'] = $callerResult->structuralDependencies;
            $data['transitive_impact'] = $impact->transitive;
            $data['risk'] = $impact->risk;
        }

        return new CapabilityResult(
            'analyze',
            $data,
            $index->diagnostics(),
            $index->unresolvedReferences(),
        );
    }

    public function findCallers(string $projectRoot, string $target): CapabilityResult
    {
        $root = $this->projectRoot($projectRoot);
        $requested = $this->target($target);
        $index = $this->indexer->build($root);
        $this->requireClassAndMethod($index, $requested);
        $result = $this->callers->findCallers($index, $requested->value, $requested->method);
        $data = $result->toArray();
        unset($data['diagnostics'], $data['unresolved']);

        return new CapabilityResult(
            'find_callers',
            $data,
            $result->diagnostics,
            $result->unresolved,
        );
    }

    public function dependencies(string $projectRoot, string $target): CapabilityResult
    {
        $root = $this->projectRoot($projectRoot);
        $requested = $this->target($target);
        if ($requested->method !== null) {
            throw new CapabilityException(
                'UNSUPPORTED_TARGET',
                'Dependency analysis supports class targets only.',
            );
        }

        $index = $this->indexer->build($root);
        $this->requireClassAndMethod($index, $requested);

        return new CapabilityResult('dependencies', [
            'target' => $requested->value,
            'upstream_dependencies' => $this->edges($index->findDependencies($requested->value)),
            'downstream_dependents' => $this->edges($index->findReferencesTo($requested->value)),
            'transitive_dependents' => $index->graph()->transitiveDependents($requested->value),
        ], $index->diagnostics(), $index->unresolvedReferences());
    }

    public function impact(string $projectRoot, string $target): CapabilityResult
    {
        $root = $this->projectRoot($projectRoot);
        $requested = $this->target($target);
        $index = $this->indexer->build($root);
        $this->requireClassAndMethod($index, $requested);
        $result = $this->impactAnalyzer->analyze($index, $requested->value, $requested->method);
        $data = $result->toArray();
        unset($data['diagnostics']);

        return new CapabilityResult(
            'impact',
            $data,
            $result->diagnostics,
            $index->unresolvedReferences(),
        );
    }

    private function projectRoot(string $projectRoot): string
    {
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) {
            throw new CapabilityException(
                'PROJECT_ROOT_NOT_FOUND',
                "Project root not found: {$projectRoot}",
            );
        }

        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    private function target(string $target): RefactoringTarget
    {
        try {
            return RefactoringTarget::parse($target);
        } catch (InvalidArgumentException $exception) {
            throw new CapabilityException('INVALID_TARGET', $exception->getMessage());
        }
    }

    /** @return array{string, string, SymbolDefinition|null} */
    private function analysisTarget(
        string $root,
        RefactoringTarget $target,
        CodebaseIndex $index,
    ): array {
        $file = $this->resolvePhpFile($root, $target->value);
        if ($file !== null) {
            $relative = $this->relativePath($root, $file);
            $classes = $index->classesInFile($relative);

            return [$file, $relative, $classes[0] ?? null];
        }

        if ($this->looksLikeFile($target->value)) {
            throw new CapabilityException(
                'TARGET_NOT_FOUND',
                "PHP file not found: {$target->value}",
            );
        }

        $symbol = $this->requireClass($index, $target->value);
        $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $symbol->file);
        if (!is_file($file)) {
            throw new CapabilityException(
                'TARGET_NOT_FOUND',
                "PHP file not found for class: {$symbol->fqcn}",
            );
        }

        return [$file, str_replace('\\', '/', $symbol->file), $symbol];
    }

    private function resolvePhpFile(string $root, string $target): ?string
    {
        if (!$this->looksLikeFile($target)) {
            return null;
        }

        $candidate = $this->isAbsolutePath($target)
            ? $target
            : $root . DIRECTORY_SEPARATOR . $target;
        $file = realpath($candidate);

        return $file !== false && is_file($file) && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'php'
            ? $file
            : null;
    }

    private function looksLikeFile(string $target): bool
    {
        return strtolower(pathinfo($target, PATHINFO_EXTENSION)) === 'php';
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function relativePath(string $root, string $file): string
    {
        $prefix = $root . DIRECTORY_SEPARATOR;

        return str_starts_with($file, $prefix)
            ? str_replace('\\', '/', substr($file, strlen($prefix)))
            : str_replace('\\', '/', $file);
    }

    private function requireClassAndMethod(CodebaseIndex $index, RefactoringTarget $target): SymbolDefinition
    {
        $symbol = $this->requireClass($index, $target->value);
        if ($target->method !== null) {
            $this->requireMethod($index, $symbol->fqcn, $target->method);
        }

        return $symbol;
    }

    private function requireClass(CodebaseIndex $index, string $class): SymbolDefinition
    {
        $symbol = $index->findClass($class);
        if ($symbol === null) {
            throw new CapabilityException('TARGET_NOT_FOUND', "Class not found: {$class}");
        }

        return $symbol;
    }

    private function requireMethod(CodebaseIndex $index, string $class, string $method): void
    {
        if ($index->findMethod($class, $method) === null) {
            throw new CapabilityException(
                'TARGET_NOT_FOUND',
                "Method not found: {$class}::{$method}",
            );
        }
    }

    private function edges(array $edges): array
    {
        return array_map(static fn ($edge): array => $edge->toArray(), $edges);
    }
}
