<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Index;

use Peralta\AgentKit\Refactoring\Analysis\DTOs\SymbolDefinition;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyGraph;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyType;

final readonly class CodebaseIndex
{
    private array $methodsByClass;
    private array $symbolsByFile;

    public function __construct(
        private array $symbols,
        private DependencyGraph $dependencyGraph,
        private array $parseDiagnostics = [],
        private array $unresolved = [],
    ) {
        $methods = [];
        $files = [];
        foreach ($symbols as $fqcn => $symbol) {
            foreach ($symbol->methods as $method) {
                $methods[$fqcn][strtolower($method['name'])] = $method;
            }
            $files[str_replace('\\', '/', $symbol->file)][] = $symbol;
        }
        $this->methodsByClass = $methods;
        $this->symbolsByFile = $files;
    }

    public function findClass(string $fqcn): ?SymbolDefinition
    {
        return $this->symbols[$this->normalize($fqcn)] ?? null;
    }

    public function findReferencesTo(string $fqcn): array
    {
        return $this->dependencyGraph->incoming($this->normalize($fqcn));
    }

    public function findMethod(string $fqcn, string $method): ?array
    {
        return $this->methodsByClass[$this->normalize($fqcn)][strtolower($method)] ?? null;
    }

    public function classesInFile(string $file): array
    {
        return $this->symbolsByFile[str_replace('\\', '/', $file)] ?? [];
    }

    public function findDependencies(string $fqcn): array
    {
        return $this->dependencyGraph->outgoing($this->normalize($fqcn));
    }

    public function findMethodCalls(string $fqcn, ?string $method = null): array
    {
        return array_values(array_filter(
            $this->findReferencesTo($fqcn),
            fn ($edge) => in_array($edge->type, [
                DependencyType::METHOD_CALL,
                DependencyType::STATIC_CALL,
                DependencyType::FACADE,
                DependencyType::EVENT,
            ], true)
                && ($method === null || $edge->targetMethod === $method),
        ));
    }

    public function graph(): DependencyGraph
    {
        return $this->dependencyGraph;
    }

    public function diagnostics(): array
    {
        return $this->parseDiagnostics;
    }

    public function unresolvedReferences(): array
    {
        return array_map(fn ($reference) => $reference->toArray(), $this->unresolved);
    }

    private function normalize(string $fqcn): string
    {
        return ltrim($fqcn, '\\');
    }
}
