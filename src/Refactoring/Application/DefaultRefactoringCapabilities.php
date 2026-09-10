<?php

namespace Peralta\AgentKit\Refactoring\Application;

use InvalidArgumentException;
use Peralta\AgentKit\Refactoring\Analysis\CallerAnalyzer;
use Peralta\AgentKit\Refactoring\Analysis\DTOs\SymbolDefinition;
use Peralta\AgentKit\Refactoring\Analysis\ImpactAnalyzer;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndex;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexer;
use Peralta\AgentKit\Refactoring\Support\PhpFileAnalyzer;
use Peralta\AgentKit\Refactoring\Support\ProjectRoot;
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
        [$file, $displayPath, $symbols, $isFileTarget] = $this->analysisTarget($root, $requested, $index);

        if ($isFileTarget && $requested->method !== null && $symbols === []) {
            throw new CapabilityException(
                'UNSUPPORTED_TARGET',
                'A method target requires a class declaration.',
            );
        }
        if ($isFileTarget && $requested->method !== null && count($symbols) > 1) {
            throw new CapabilityException(
                'AMBIGUOUS_TARGET',
                'File method target is ambiguous; use a fully qualified class name.',
            );
        }

        $method = $requested->method === null
            ? null
            : $this->canonicalMethod($index, $symbols[0]->fqcn, $requested->method);

        $metrics = $this->fileAnalyzer->analyze($file, $displayPath)->toArray();
        $data = [
            'target' => $isFileTarget ? $displayPath : $symbols[0]->fqcn,
            'method' => $method,
            'metrics' => $metrics,
            'upstream_dependencies' => [],
            'direct_callers' => [],
            'structural_dependencies' => [],
            'transitive_impact' => [],
            'risk' => 'UNKNOWN',
        ];

        foreach ($symbols as $symbol) {
            $impact = $this->impactAnalyzer->analyze($index, $symbol->fqcn, $method);
            $data['upstream_dependencies'] = array_merge(
                $data['upstream_dependencies'],
                $this->edges($index->findDependencies($symbol->fqcn)),
            );
            $data['direct_callers'] = array_merge($data['direct_callers'], $impact->direct);
            $data['structural_dependencies'] = array_merge(
                $data['structural_dependencies'],
                $impact->structural,
            );
            $data['transitive_impact'] = array_merge($data['transitive_impact'], $impact->transitive);
        }

        if ($symbols !== []) {
            $data['upstream_dependencies'] = $this->uniqueEdges($data['upstream_dependencies']);
            $data['direct_callers'] = $this->uniqueEdges($data['direct_callers']);
            $data['structural_dependencies'] = $this->uniqueEdges($data['structural_dependencies']);
            $data['transitive_impact'] = $this->uniqueTransitive($data['transitive_impact']);
            $dependents = array_unique(array_merge(
                array_column($data['direct_callers'], 'source'),
                array_column($data['structural_dependencies'], 'source'),
                array_column($data['transitive_impact'], 'fqcn'),
            ));
            $data['risk'] = $this->impactAnalyzer->riskForDependents(count($dependents));
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
        $symbol = $this->requireClass($index, $requested->value);
        $method = $this->canonicalMethod($index, $symbol->fqcn, $requested->method);
        $result = $this->callers->findCallers($index, $symbol->fqcn, $method);
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
        $this->requireClass($index, $requested->value);

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
        $symbol = $this->requireClass($index, $requested->value);
        $method = $this->canonicalMethod($index, $symbol->fqcn, $requested->method);
        $result = $this->impactAnalyzer->analyze($index, $symbol->fqcn, $method);
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

        return ProjectRoot::normalize($root);
    }

    private function target(string $target): RefactoringTarget
    {
        try {
            return RefactoringTarget::parse($target);
        } catch (InvalidArgumentException $exception) {
            throw new CapabilityException('INVALID_TARGET', $exception->getMessage());
        }
    }

    /** @return array{string, string, list<SymbolDefinition>, bool} */
    private function analysisTarget(
        string $root,
        RefactoringTarget $target,
        CodebaseIndex $index,
    ): array {
        $file = $this->resolvePhpFile($root, $target->value);
        if ($file !== null) {
            $relative = $this->relativePath($root, $file);
            $classes = $index->classesInFile($relative);

            return [$file, $relative, $classes, true];
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

        $this->ensureInsideProject($root, (string) realpath($file));

        return [$file, str_replace('\\', '/', $symbol->file), [$symbol], false];
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

        if ($file !== false && is_file($file)) {
            $this->ensureInsideProject($root, $file);
        }

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
        return ProjectRoot::relative($root, $file);
    }

    private function ensureInsideProject(string $root, string $path): void
    {
        if (!ProjectRoot::contains($root, $path)) {
            throw new CapabilityException(
                'TARGET_OUTSIDE_PROJECT',
                'Target file must be inside the project root.',
            );
        }
    }

    private function requireClass(CodebaseIndex $index, string $class): SymbolDefinition
    {
        $symbol = $index->findClass($class);
        if ($symbol === null) {
            throw new CapabilityException('TARGET_NOT_FOUND', "Class not found: {$class}");
        }

        return $symbol;
    }

    private function canonicalMethod(CodebaseIndex $index, string $class, ?string $method): ?string
    {
        if ($method === null) {
            return null;
        }

        $definition = $index->findMethod($class, $method);
        if ($definition === null) {
            throw new CapabilityException(
                'TARGET_NOT_FOUND',
                "Method not found: {$class}::{$method}",
            );
        }

        return $definition['name'];
    }

    private function edges(array $edges): array
    {
        return array_map(static fn ($edge): array => $edge->toArray(), $edges);
    }

    private function uniqueEdges(array $edges): array
    {
        return $this->uniqueRows($edges, static fn (array $edge): string => implode("\0", [
            $edge['source'],
            $edge['source_method'] ?? '',
            $edge['target'],
            $edge['target_method'] ?? '',
            $edge['type'],
            $edge['file'],
            (string) $edge['line'],
        ]));
    }

    private function uniqueTransitive(array $dependents): array
    {
        return $this->uniqueRows($dependents, static fn (array $dependent): string => implode("\0", [
            $dependent['fqcn'],
            implode('>', $dependent['path']),
        ]));
    }

    private function uniqueRows(array $rows, callable $identity): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $unique[$identity($row)] = $row;
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }
}
