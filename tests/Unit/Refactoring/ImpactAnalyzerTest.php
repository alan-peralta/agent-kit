<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring;

use Peralta\AgentKit\Refactoring\Analysis\Graph\Confidence;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyEdge;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyGraph;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyNode;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyType;
use Peralta\AgentKit\Refactoring\Analysis\ImpactAnalyzer;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndex;
use Peralta\AgentKit\Refactoring\Analysis\DTOs\SymbolDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImpactAnalyzerTest extends TestCase
{
    public function test_it_exposes_the_configured_risk_policy_for_aggregated_dependents(): void
    {
        $analyzer = new ImpactAnalyzer([
            'low_max' => 1,
            'medium_max' => 3,
            'high_max' => 5,
        ]);

        $this->assertSame('LOW', $analyzer->riskForDependents(1));
        $this->assertSame('MEDIUM', $analyzer->riskForDependents(3));
        $this->assertSame('HIGH', $analyzer->riskForDependents(5));
        $this->assertSame('CRITICAL', $analyzer->riskForDependents(6));
    }

    public function test_it_counts_unique_direct_structural_and_transitive_dependents_in_a_cycle(): void
    {
        $graph = new DependencyGraph();
        foreach (['Target', 'Direct', 'Structural', 'Transitive'] as $name) {
            $graph->addNode(new DependencyNode($name, 'class', "{$name}.php", 1));
        }
        $graph->addEdge($this->edge('Direct', 'Target', DependencyType::METHOD_CALL, 'Direct.php'));
        $graph->addEdge($this->edge('Direct', 'Target', DependencyType::METHOD_CALL, 'Direct.php'));
        $graph->addEdge($this->edge('Structural', 'Target', DependencyType::PROPERTY_TYPE, 'Structural.php'));
        $graph->addEdge($this->edge('Transitive', 'Direct', DependencyType::CONSTRUCTOR_INJECTION, 'Transitive.php'));
        $graph->addEdge($this->edge('Target', 'Transitive', DependencyType::METHOD_PARAMETER, 'Target.php'));

        $result = (new ImpactAnalyzer([
            'low_max' => 0,
            'medium_max' => 1,
            'high_max' => 3,
        ]))->analyze($this->index($graph, ['Target', 'Direct', 'Structural', 'Transitive']), 'Target');

        $this->assertSame(1, $result->directCallers);
        $this->assertSame(1, $result->structuralDependencies);
        $this->assertSame(1, $result->transitiveDependents);
        $this->assertSame(3, $result->affectedFiles);
        $this->assertSame('HIGH', $result->risk);
        $this->assertSame(['Transitive'], array_column($result->transitive, 'fqcn'));
        $this->assertNotContains('Target', array_column($result->transitive, 'fqcn'));
    }

    public function test_it_scopes_direct_impact_to_a_method(): void
    {
        $graph = new DependencyGraph();
        $names = ['Target', 'ChargeCaller', 'ChargeParent', 'StatusCaller', 'StatusParent'];
        foreach ($names as $name) {
            $graph->addNode(new DependencyNode($name, 'class', "{$name}.php", 1));
        }
        $graph->addEdge(new DependencyEdge(
            'ChargeCaller',
            'run',
            'Target',
            'charge',
            DependencyType::METHOD_CALL,
            Confidence::EXACT,
            'ChargeCaller.php',
            5,
        ));
        $graph->addEdge(new DependencyEdge(
            'StatusCaller',
            'run',
            'Target',
            'status',
            DependencyType::METHOD_CALL,
            Confidence::EXACT,
            'StatusCaller.php',
            5,
        ));
        $graph->addEdge($this->edge('ChargeParent', 'ChargeCaller', DependencyType::CONSTRUCTOR_INJECTION, 'ChargeParent.php'));
        $graph->addEdge($this->edge('StatusParent', 'StatusCaller', DependencyType::CONSTRUCTOR_INJECTION, 'StatusParent.php'));

        $result = (new ImpactAnalyzer())->analyze(
            $this->index($graph, $names),
            'Target',
            'charge',
        );

        $this->assertSame('charge', $result->method);
        $this->assertSame(1, $result->directCallers);
        $this->assertSame(['ChargeCaller'], array_column($result->direct, 'source'));
        $this->assertSame(1, $result->transitiveDependents);
        $this->assertSame(['ChargeParent'], array_column($result->transitive, 'fqcn'));
        $this->assertNotContains('StatusCaller', array_column($result->transitive, 'fqcn'));
        $this->assertNotContains('StatusParent', array_column($result->transitive, 'fqcn'));
        $this->assertSame(2, $result->affectedFiles);
        $this->assertSame('LOW', $result->risk);
        $this->assertSame('charge', $result->toArray()['method']);
    }

    public function test_a_matching_self_call_does_not_inflate_method_impact(): void
    {
        $graph = new DependencyGraph();
        foreach (['Target', 'StatusCaller'] as $name) {
            $graph->addNode(new DependencyNode($name, 'class', "{$name}.php", 1));
        }
        $graph->addEdge(new DependencyEdge(
            'Target',
            'run',
            'Target',
            'charge',
            DependencyType::METHOD_CALL,
            Confidence::EXACT,
            'Target.php',
            5,
        ));
        $graph->addEdge(new DependencyEdge(
            'StatusCaller',
            'run',
            'Target',
            'status',
            DependencyType::METHOD_CALL,
            Confidence::EXACT,
            'StatusCaller.php',
            5,
        ));

        $result = (new ImpactAnalyzer([
            'low_max' => 0,
            'medium_max' => 1,
            'high_max' => 2,
        ]))->analyze($this->index($graph, ['Target', 'StatusCaller']), 'Target', 'charge');

        $this->assertSame(['Target'], array_column($result->direct, 'source'));
        $this->assertSame(0, $result->transitiveDependents);
        $this->assertNotContains('StatusCaller', array_column($result->transitive, 'fqcn'));
        $this->assertSame(1, $result->affectedFiles);
        $this->assertSame('MEDIUM', $result->risk);
    }

    #[DataProvider('riskCases')]
    public function test_it_applies_configured_risk_boundaries(int $dependents, string $expected): void
    {
        $graph = new DependencyGraph();
        $names = ['Target'];
        $graph->addNode(new DependencyNode('Target', 'class', 'Target.php', 1));
        for ($i = 1; $i <= $dependents; $i++) {
            $name = "D{$i}";
            $names[] = $name;
            $graph->addNode(new DependencyNode($name, 'class', "{$name}.php", 1));
            $graph->addEdge($this->edge($name, 'Target', DependencyType::METHOD_CALL, "{$name}.php"));
        }

        $result = (new ImpactAnalyzer([
            'low_max' => 0,
            'medium_max' => 1,
            'high_max' => 2,
        ]))->analyze($this->index($graph, $names), 'Target');

        $this->assertSame($expected, $result->risk);
    }

    public static function riskCases(): array
    {
        return [
            'low' => [0, 'LOW'],
            'medium' => [1, 'MEDIUM'],
            'high' => [2, 'HIGH'],
            'critical' => [3, 'CRITICAL'],
        ];
    }

    private function edge(string $source, string $target, DependencyType $type, string $file): DependencyEdge
    {
        return new DependencyEdge($source, 'run', $target, 'go', $type, Confidence::EXACT, $file, 5);
    }

    private function index(DependencyGraph $graph, array $names): CodebaseIndex
    {
        $symbols = [];
        foreach ($names as $name) {
            $symbols[$name] = new SymbolDefinition($name, 'class', "{$name}.php", 1);
        }

        return new CodebaseIndex($symbols, $graph);
    }
}
