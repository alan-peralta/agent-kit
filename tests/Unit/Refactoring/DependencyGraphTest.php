<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring;

use Peralta\AgentKit\Refactoring\Analysis\Graph\Confidence;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyEdge;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyGraph;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyNode;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyType;
use PHPUnit\Framework\TestCase;

final class DependencyGraphTest extends TestCase
{
    public function test_it_preserves_typed_parallel_edges_and_traverses_cycles_once(): void
    {
        $graph = new DependencyGraph();
        $graph->addNode(new DependencyNode('A', 'class', 'A.php', 2));
        $graph->addNode(new DependencyNode('B', 'class', 'B.php', 2));
        $graph->addNode(new DependencyNode('C', 'class', 'C.php', 2));
        $graph->addEdge(new DependencyEdge('A', null, 'B', null, DependencyType::PROPERTY_TYPE, Confidence::EXACT, 'A.php', 5));
        $graph->addEdge(new DependencyEdge('A', 'run', 'B', 'go', DependencyType::METHOD_CALL, Confidence::INFERRED, 'A.php', 9));
        $graph->addEdge(new DependencyEdge('B', null, 'C', null, DependencyType::EXTENDS, Confidence::EXACT, 'B.php', 2));
        $graph->addEdge(new DependencyEdge('C', null, 'A', null, DependencyType::METHOD_PARAMETER, Confidence::EXACT, 'C.php', 7));

        $this->assertCount(2, $graph->outgoing('A'));
        $this->assertSame(['A', 'A'], array_map(fn ($edge) => $edge->source, $graph->incoming('B')));
        $dependents = $graph->transitiveDependents('C');
        $this->assertSame(['B', 'A'], array_column($dependents, 'fqcn'));
        $this->assertNotContains('C', array_column($dependents, 'fqcn'));
        $this->assertSame(['B', 'C'], $dependents[0]['path']);
        $this->assertSame(['A', 'B', 'C'], $dependents[1]['path']);
    }

    public function test_node_and_edge_identity_is_case_insensitive_without_lowercasing_output(): void
    {
        $graph = new DependencyGraph();
        $graph->addNode(new DependencyNode('Demo\\Target', 'class', 'Target.php', 2));
        $graph->addNode(new DependencyNode('Demo\\Caller', 'class', 'Caller.php', 2));
        $graph->addEdge(new DependencyEdge('Demo\\Caller', 'run', 'demo\\target', 'Charge', DependencyType::METHOD_CALL, Confidence::EXACT, 'Caller.php', 5));
        $graph->addEdge(new DependencyEdge('DEMO\\TARGET', 'notify', 'demo\\caller', 'run', DependencyType::METHOD_CALL, Confidence::EXACT, 'Target.php', 6));

        $this->assertSame('Demo\\Target', $graph->node('DEMO\\TARGET')?->fqcn);
        $this->assertSame('Demo\\Caller', $graph->incoming('Demo\\Target')[0]->source);
        $this->assertSame('Demo\\Target', $graph->incoming('Demo\\Target')[0]->target);
        $dependents = $graph->transitiveDependents('demo\\target');
        $this->assertSame(['Demo\\Caller'], array_column($dependents, 'fqcn'));
        $this->assertSame(['Demo\\Caller', 'Demo\\Target'], $dependents[0]['path']);
    }
}
