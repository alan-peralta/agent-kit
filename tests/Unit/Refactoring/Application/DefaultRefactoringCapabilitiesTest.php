<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Application;

use Peralta\AgentKit\Refactoring\Analysis\Ast\AstParser;
use Peralta\AgentKit\Refactoring\Analysis\Ast\PhpAstParser;
use Peralta\AgentKit\Refactoring\Analysis\CallerAnalyzer;
use Peralta\AgentKit\Refactoring\Analysis\DTOs\ParsedFile;
use Peralta\AgentKit\Refactoring\Analysis\ImpactAnalyzer;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexer;
use Peralta\AgentKit\Refactoring\Application\CapabilityException;
use Peralta\AgentKit\Refactoring\Application\DefaultRefactoringCapabilities;
use Peralta\AgentKit\Refactoring\Support\PhpFileAnalyzer;
use Peralta\AgentKit\Refactoring\Support\ProjectScanner;
use Peralta\AgentKit\Refactoring\Support\RefactoringReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DefaultRefactoringCapabilitiesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3) . '/Fixtures/Refactoring/Ast';
    }

    public function test_it_describes_the_ordered_capability_contract(): void
    {
        $result = $this->service()->describeCapabilities();

        $this->assertSame('capability_discovery', $result->capability);
        $this->assertSame(
            ['audit', 'analyze', 'find_callers', 'dependencies', 'impact'],
            array_column($result->data['capabilities'], 'name'),
        );
        foreach ($result->data['capabilities'] as $descriptor) {
            $this->assertNotEmpty($descriptor['targets']);
            $this->assertStringStartsWith('php artisan agent-kit:', $descriptor['cli_fallback']);
            $this->assertStringContainsString('--json', $descriptor['cli_fallback']);
            $this->assertTrue($descriptor['json']);
        }
    }

    public function test_it_audits_a_real_project_without_an_ast_index(): void
    {
        $result = $this->service()->audit($this->root);

        $this->assertSame('audit', $result->capability);
        $this->assertSame(realpath($this->root), $result->data['project_root']);
        $this->assertSame(7, $result->data['summary']['php_files']);
        $this->assertSame(124, $result->data['summary']['lines']);
        $this->assertCount(7, $result->data['files']);
        $this->assertSame([], $result->diagnostics);
        $this->assertSame([], $result->unresolved);
    }

    public function test_it_analyzes_a_class_and_includes_relationships(): void
    {
        $result = $this->service()->analyze($this->root, 'Fixtures\\Checkout\\CheckoutService');

        $this->assertSame('analyze', $result->capability);
        $this->assertSame('Fixtures\\Checkout\\CheckoutService', $result->data['target']);
        $this->assertNull($result->data['method']);
        $this->assertSame([
            'path' => 'CheckoutService.php',
            'lines' => 36,
            'methods' => 2,
            'dependencies' => 6,
            'branches' => 0,
            'smells' => [],
        ], $result->data['metrics']);
        $this->assertNotEmpty($result->data['upstream_dependencies']);
        $this->assertArrayHasKey('direct_callers', $result->data);
        $this->assertArrayHasKey('structural_dependencies', $result->data);
        $this->assertArrayHasKey('transitive_impact', $result->data);
        $this->assertArrayHasKey('risk', $result->data);
        $this->assertNotEmpty($result->unresolved);
    }

    public function test_it_analyzes_a_project_relative_file(): void
    {
        $result = $this->service()->analyze($this->root, 'CheckoutService.php');

        $this->assertSame('Fixtures\\Checkout\\CheckoutService', $result->data['target']);
        $this->assertSame('CheckoutService.php', $result->data['metrics']['path']);
        $this->assertSame(36, $result->data['metrics']['lines']);
        $this->assertSame(2, $result->data['metrics']['methods']);
        $this->assertSame(6, $result->data['metrics']['dependencies']);
    }

    public function test_it_finds_callers_and_moves_partial_data_to_the_envelope(): void
    {
        $result = $this->service()->findCallers(
            $this->root,
            'Fixtures\\Payments\\PaymentService::charge',
        );

        $this->assertSame('find_callers', $result->capability);
        $this->assertSame('charge', $result->data['method']);
        $this->assertNotEmpty($result->data['direct_callers']);
        $this->assertSame('project', $result->data['unresolved_scope']);
        $this->assertArrayNotHasKey('diagnostics', $result->data);
        $this->assertArrayNotHasKey('unresolved', $result->data);
        $this->assertNotEmpty($result->unresolved);
        $this->assertSame([], $result->diagnostics);
        $this->assertTrue($result->incomplete());
    }

    public function test_it_reports_upstream_downstream_and_transitive_dependencies(): void
    {
        $result = $this->service()->dependencies($this->root, 'Fixtures\\Payments\\PaymentService');

        $this->assertSame('dependencies', $result->capability);
        $this->assertSame('Fixtures\\Payments\\PaymentService', $result->data['target']);
        $this->assertNotEmpty($result->data['upstream_dependencies']);
        $this->assertNotEmpty($result->data['downstream_dependents']);
        $this->assertNotEmpty($result->data['transitive_dependents']);
        $this->assertNotEmpty($result->unresolved);
    }

    public function test_it_reports_method_impact_and_envelope_diagnostics(): void
    {
        $result = $this->service()->impact(
            $this->root,
            'Fixtures\\Payments\\PaymentService::charge',
        );

        $this->assertSame('impact', $result->capability);
        $this->assertSame('charge', $result->data['method']);
        $this->assertGreaterThan(0, $result->data['direct_callers']);
        $this->assertArrayNotHasKey('diagnostics', $result->data);
        $this->assertSame([], $result->diagnostics);
        $this->assertNotEmpty($result->unresolved);
    }

    #[DataProvider('invalidTargetCases')]
    public function test_it_returns_stable_errors(string $operation, string $target, string $code): void
    {
        try {
            $this->service()->{$operation}($this->root, $target);
            $this->fail('Expected a capability exception.');
        } catch (CapabilityException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    public static function invalidTargetCases(): array
    {
        return [
            'malformed target' => ['analyze', 'Class::bad::method', 'INVALID_TARGET'],
            'missing class' => ['findCallers', 'Fixtures\\Missing\\Service', 'TARGET_NOT_FOUND'],
            'missing method' => ['impact', 'Fixtures\\Payments\\PaymentService::missing', 'TARGET_NOT_FOUND'],
            'method dependencies' => ['dependencies', 'Fixtures\\Payments\\PaymentService::charge', 'UNSUPPORTED_TARGET'],
        ];
    }

    public function test_it_rejects_a_missing_project_root_with_a_stable_error(): void
    {
        $this->expectException(CapabilityException::class);
        $this->expectExceptionMessage('Project root not found:');

        try {
            $this->service()->audit($this->root . '/missing');
        } catch (CapabilityException $exception) {
            $this->assertSame('PROJECT_ROOT_NOT_FOUND', $exception->errorCode);
            throw $exception;
        }
    }

    public function test_analyze_builds_the_ast_index_only_once(): void
    {
        $parser = new class(new PhpAstParser()) implements AstParser {
            public int $calls = 0;

            public function __construct(private readonly AstParser $inner) {}

            public function parse(string $file, ?string $displayPath = null): ParsedFile
            {
                $this->calls++;

                return $this->inner->parse($file, $displayPath);
            }
        };

        $this->service($parser)->analyze($this->root, 'Fixtures\\Payments\\PaymentService');

        $this->assertSame(7, $parser->calls);
    }

    private function service(?AstParser $parser = null): DefaultRefactoringCapabilities
    {
        $fileAnalyzer = new PhpFileAnalyzer();
        $scanner = new ProjectScanner($fileAnalyzer);

        return new DefaultRefactoringCapabilities(
            $scanner,
            $fileAnalyzer,
            new RefactoringReport(),
            new CodebaseIndexer($scanner, $parser ?? new PhpAstParser()),
            new CallerAnalyzer(),
            new ImpactAnalyzer(),
        );
    }
}
