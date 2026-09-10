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
        $transitive = [];

        foreach (array_keys($firstHopDependents) as $root) {
            foreach ($index->graph()->transitiveDependents($root) as $dependent) {
                if (
                    $dependent['fqcn'] === $target
                    || isset($firstHopDependents[$dependent['fqcn']])
                    || in_array($target, $dependent['path'], true)
                ) {
                    continue;
                }

                $dependent['depth']++;
                $dependent['path'][] = $target;
                if (! isset($transitive[$dependent['fqcn']])
                    || $dependent['depth'] < $transitive[$dependent['fqcn']]['depth']) {
                    $transitive[$dependent['fqcn']] = $dependent;
                }
            }
        }

        $transitive = array_values($transitive);
        usort($transitive, fn (array $a, array $b) => [$a['depth'], $a['fqcn']] <=> [$b['depth'], $b['fqcn']]);

        return $transitive;
    }
}
