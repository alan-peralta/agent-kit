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

        return new CallerResult(
            $target,
            $method,
            $direct,
            $structural,
            $index->diagnostics(),
        );
    }
}
