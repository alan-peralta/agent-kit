<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring;

use Peralta\AgentKit\Refactoring\Analysis\Ast\PhpAstParser;
use Peralta\AgentKit\Refactoring\Analysis\CallerAnalyzer;
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
        $this->assertSame($result->toArray(), json_decode(json_encode($result->toArray()), true));
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
}
