<?php

namespace Peralta\AgentKit\Refactoring\Analysis;

use Peralta\AgentKit\Refactoring\Analysis\DTOs\CallerResult;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyType;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndex;

final class CallerAnalyzer
{
    private const STRUCTURAL_TYPES = [
        DependencyType::CONSTRUCTOR_INJECTION,
        DependencyType::METHOD_PARAMETER,
        DependencyType::RETURN_TYPE,
        DependencyType::PROPERTY_TYPE,
        DependencyType::EXTENDS,
        DependencyType::IMPLEMENTS,
        DependencyType::TRAIT,
        DependencyType::INSTANTIATION,
        DependencyType::CLASS_CONSTANT,
        DependencyType::ATTRIBUTE,
    ];

    public function findCallers(CodebaseIndex $index, string $class, ?string $method = null): CallerResult
    {
        $target = ltrim($class, '\\');
        if ($index->findClass($target) === null) {
            throw new \InvalidArgumentException("Classe não encontrada no índice: {$target}");
        }

        $direct = array_map(
            fn ($edge) => $edge->toArray(),
            $index->findMethodCalls($target, $method),
        );
        $structural = array_map(
            fn ($edge) => $edge->toArray(),
            array_values(array_filter(
                $index->findReferencesTo($target),
                fn ($edge) => in_array($edge->type, self::STRUCTURAL_TYPES, true),
            )),
        );
        $directDependents = array_fill_keys(array_column($direct, 'source'), true);
        foreach (array_column($structural, 'source') as $source) {
            $directDependents[$source] = true;
        }
        $transitive = $method === null
            ? array_values(array_filter(
                $index->graph()->transitiveDependents($target),
                fn (array $dependent) => !isset($directDependents[$dependent['fqcn']]),
            ))
            : $this->methodTransitiveDependents($index, $target, $directDependents);

        return new CallerResult(
            $target,
            $method,
            $direct,
            $structural,
            $transitive,
            $index->unresolvedReferences(),
            $index->diagnostics(),
        );
    }

    private function methodTransitiveDependents(
        CodebaseIndex $index,
        string $target,
        array $firstHopDependents,
    ): array
    {
        $roots = array_values(array_filter(
            array_unique(array_map(
                fn (string $root) => ltrim($root, '\\'),
                array_keys($firstHopDependents),
            )),
            fn (string $root) => $root !== $target,
        ));
        sort($roots, SORT_STRING);

        $visited = [$target => true];
        $queue = [];
        foreach ($roots as $root) {
            $visited[$root] = true;
            $queue[] = [$root, [$root, $target]];
        }

        $transitive = [];
        $position = 0;
        while (isset($queue[$position])) {
            [$current, $path] = $queue[$position++];
            foreach ($index->graph()->incoming($current) as $edge) {
                $source = ltrim($edge->source, '\\');
                if (isset($visited[$source])) {
                    continue;
                }

                $visited[$source] = true;
                $sourcePath = array_merge([$source], $path);
                $transitive[] = [
                    'fqcn' => $source,
                    'file' => $edge->file,
                    'depth' => count($sourcePath) - 1,
                    'path' => $sourcePath,
                ];
                $queue[] = [$source, $sourcePath];
            }
        }

        usort($transitive, fn (array $a, array $b) => [$a['depth'], $a['fqcn']] <=> [$b['depth'], $b['fqcn']]);

        return $transitive;
    }
}
