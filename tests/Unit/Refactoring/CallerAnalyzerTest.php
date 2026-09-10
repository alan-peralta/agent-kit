<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring;

use Peralta\AgentKit\Refactoring\Analysis\Ast\PhpAstParser;
use Peralta\AgentKit\Refactoring\Analysis\CallerAnalyzer;
use Peralta\AgentKit\Refactoring\Analysis\DTOs\SymbolDefinition;
use Peralta\AgentKit\Refactoring\Analysis\Graph\Confidence;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyEdge;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyGraph;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyNode;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyType;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndex;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexer;
use Peralta\AgentKit\Refactoring\Support\PhpFileAnalyzer;
use Peralta\AgentKit\Refactoring\Support\ProjectScanner;
use PHPUnit\Framework\TestCase;

final class CallerAnalyzerTest extends TestCase
{
    public function test_it_separates_method_callers_from_structural_dependencies(): void
    {
        $result = (new CallerAnalyzer())->findCallers(
            $this->index(),
            'Fixtures\\Payments\\PaymentService',
            'charge',
        );

        $this->assertSame('Fixtures\\Payments\\PaymentService', $result->target);
        $this->assertSame('charge', $result->method);
        $this->assertNotEmpty($result->directCallers);
        $this->assertSame(['Fixtures\\Checkout\\CheckoutService'], array_values(array_unique(array_column($result->directCallers, 'source'))));
        $this->assertContains('constructor_injection', array_column($result->structuralDependencies, 'type'));
        $this->assertNotContains('status', array_column($result->directCallers, 'target_method'));
        $this->assertIsArray($result->transitiveDependents);
        $this->assertIsArray($result->unresolved);
        $this->assertArrayHasKey('transitive_dependents', $result->toArray());
        $this->assertArrayHasKey('unresolved', $result->toArray());
        $this->assertSame($result->toArray(), json_decode(json_encode($result->toArray()), true));
    }

    public function test_it_returns_only_non_direct_non_structural_transitive_dependents(): void
    {
        $graph = new DependencyGraph();
        foreach (['Target', 'Direct', 'Structural', 'Transitive'] as $name) {
            $graph->addNode(new DependencyNode($name, 'class', "{$name}.php", 1));
        }
        $graph->addEdge($this->edge('Direct', 'Target', DependencyType::METHOD_CALL));
        $graph->addEdge($this->edge('Structural', 'Target', DependencyType::PROPERTY_TYPE));
        $graph->addEdge($this->edge('Transitive', 'Direct', DependencyType::CONSTRUCTOR_INJECTION));
        $graph->addEdge($this->edge('Target', 'Transitive', DependencyType::METHOD_PARAMETER));

        $symbols = [];
        foreach (['Target', 'Direct', 'Structural', 'Transitive'] as $name) {
            $symbols[$name] = new SymbolDefinition($name, 'class', "{$name}.php", 1);
        }

        $result = (new CallerAnalyzer())->findCallers(new CodebaseIndex($symbols, $graph), 'Target');

        $this->assertSame(['Transitive'], array_column($result->transitiveDependents, 'fqcn'));
        $this->assertNotContains('Target', array_column($result->transitiveDependents, 'fqcn'));
    }

    public function test_it_normalizes_target_and_supports_unfiltered_calls(): void
    {
        $result = (new CallerAnalyzer())->findCallers(
            $this->index(),
            '\\Fixtures\\Payments\\PaymentService',
        );

        $this->assertNull($result->method);
        $this->assertContains('status', array_column($result->directCallers, 'target_method'));
    }

    public function test_it_rejects_a_missing_target(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing\\Service');

        (new CallerAnalyzer())->findCallers($this->index(), 'Missing\\Service');
    }

    public function test_event_dispatches_are_direct_dependents_of_the_event_class(): void
    {
        $result = (new CallerAnalyzer())->findCallers(
            $this->index(),
            'Fixtures\\Payments\\PaymentApproved',
        );

        $this->assertSame(['event'], array_values(array_unique(array_column($result->directCallers, 'type'))));
        $this->assertSame(['Fixtures\\Checkout\\CheckoutService'], array_values(array_unique(array_column($result->directCallers, 'source'))));
    }

    private function index()
    {
        $root = dirname(__DIR__, 2) . '/Fixtures/Refactoring/Ast';

        return (new CodebaseIndexer(
            new ProjectScanner(new PhpFileAnalyzer()),
            new PhpAstParser(),
        ))->build($root);
    }

    private function edge(string $source, string $target, DependencyType $type): DependencyEdge
    {
        return new DependencyEdge($source, 'run', $target, 'go', $type, Confidence::EXACT, "{$source}.php", 5);
    }
}
