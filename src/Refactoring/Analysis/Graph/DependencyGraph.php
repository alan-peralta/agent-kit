<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Graph;

final class DependencyGraph
{
    private array $nodes = [];
    private array $outgoing = [];
    private array $incoming = [];

    public function addNode(DependencyNode $node): void
    {
        $this->nodes[$this->normalize($node->fqcn)] = $node;
    }

    public function addEdge(DependencyEdge $edge): void
    {
        $this->outgoing[$this->normalize($edge->source)][] = $edge;
        $this->incoming[$this->normalize($edge->target)][] = $edge;
    }

    public function node(string $fqcn): ?DependencyNode
    {
        return $this->nodes[$this->normalize($fqcn)] ?? null;
    }

    public function nodes(): array
    {
        $nodes = array_values($this->nodes);
        usort($nodes, fn (DependencyNode $a, DependencyNode $b) => $a->fqcn <=> $b->fqcn);

        return $nodes;
    }

    public function outgoing(string $fqcn): array
    {
        return $this->sortedEdges($this->outgoing[$this->normalize($fqcn)] ?? []);
    }

    public function incoming(string $fqcn): array
    {
        return $this->sortedEdges($this->incoming[$this->normalize($fqcn)] ?? []);
    }

    public function transitiveDependents(string $fqcn): array
    {
        $target = $this->normalize($fqcn);
        $visited = [$target => true];
        $queue = [[$target, [$target]]];
        $result = [];

        while ($queue !== []) {
            [$current, $path] = array_shift($queue);
            foreach ($this->incoming($current) as $edge) {
                $source = $this->normalize($edge->source);
                if (isset($visited[$source])) {
                    continue;
                }

                $visited[$source] = true;
                $sourcePath = array_merge([$source], $path);
                $result[] = [
                    'fqcn' => $source,
                    'file' => $edge->file,
                    'depth' => count($sourcePath) - 1,
                    'path' => $sourcePath,
                ];
                $queue[] = [$source, $sourcePath];
            }
        }

        return $result;
    }

    private function sortedEdges(array $edges): array
    {
        usort($edges, function (DependencyEdge $a, DependencyEdge $b) {
            return [
                $a->source,
                $a->sourceMethod ?? '',
                $a->target,
                $a->targetMethod ?? '',
                $a->file,
                $a->line,
                $a->type->value,
            ] <=> [
                $b->source,
                $b->sourceMethod ?? '',
                $b->target,
                $b->targetMethod ?? '',
                $b->file,
                $b->line,
                $b->type->value,
            ];
        });

        return $edges;
    }

    private function normalize(string $fqcn): string
    {
        return ltrim($fqcn, '\\');
    }
}
