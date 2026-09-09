<?php

namespace Peralta\AgentKit\Refactoring\Analysis;

use Peralta\AgentKit\Refactoring\Analysis\DTOs\ImpactResult;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndex;

final class ImpactAnalyzer
{
    public function __construct(private readonly array $thresholds = []) {}

    public function analyze(CodebaseIndex $index, string $class): ImpactResult
    {
        $callers = (new CallerAnalyzer())->findCallers($index, $class);
        $directSources = $this->uniqueValues($callers->directCallers, 'source');
        $structuralSources = $this->uniqueValues($callers->structuralDependencies, 'source');
        $directDependents = array_fill_keys(array_merge($directSources, $structuralSources), true);

        $transitive = array_values(array_filter(
            $index->graph()->transitiveDependents($callers->target),
            fn (array $dependent) => !isset($directDependents[$dependent['fqcn']]),
        ));

        $allDependents = $directDependents;
        foreach ($transitive as $dependent) {
            $allDependents[$dependent['fqcn']] = true;
        }

        $files = [];
        foreach (array_merge($callers->directCallers, $callers->structuralDependencies) as $edge) {
            $files[$edge['file']] = true;
        }
        foreach ($transitive as $dependent) {
            $files[$dependent['file']] = true;
        }

        return new ImpactResult(
            $callers->target,
            count($directSources),
            count($structuralSources),
            count($transitive),
            count($files),
            $this->risk(count($allDependents)),
            $callers->directCallers,
            $callers->structuralDependencies,
            $transitive,
            $callers->diagnostics,
        );
    }

    private function uniqueValues(array $rows, string $key): array
    {
        return array_values(array_unique(array_column($rows, $key)));
    }

    private function risk(int $dependents): string
    {
        if ($dependents <= (int) ($this->thresholds['low_max'] ?? 2)) {
            return 'LOW';
        }
        if ($dependents <= (int) ($this->thresholds['medium_max'] ?? 7)) {
            return 'MEDIUM';
        }
        if ($dependents <= (int) ($this->thresholds['high_max'] ?? 15)) {
            return 'HIGH';
        }

        return 'CRITICAL';
    }
}
