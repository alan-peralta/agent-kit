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
        $this->assertSame([
            [
                'name' => 'audit',
                'targets' => ['project'],
                'cli_fallback' => 'php artisan agent-kit:refactor-audit --json',
                'json' => true,
            ],
            [
                'name' => 'analyze',
                'targets' => ['file', 'class', 'method'],
                'cli_fallback' => 'php artisan agent-kit:refactor-analyze <target> --json',
                'json' => true,
            ],
            [
                'name' => 'find_callers',
                'targets' => ['class', 'method'],
                'cli_fallback' => 'php artisan agent-kit:refactor-callers <class> --method=<method> --json',
                'json' => true,
            ],
            [
                'name' => 'dependencies',
                'targets' => ['class'],
                'cli_fallback' => 'php artisan agent-kit:refactor-dependencies <class> --json',
                'json' => true,
            ],
            [
                'name' => 'impact',
                'targets' => ['class', 'method'],
                'cli_fallback' => 'php artisan agent-kit:refactor-impact <class> --method=<method> --json',
                'json' => true,
            ],
        ], $result->data['capabilities']);
    }

    public function test_it_audits_a_real_project_without_an_ast_index(): void
    {
        $parser = $this->countingParser();
        $callsBeforeAudit = $parser->calls;

        $result = $this->service($parser)->audit($this->root);

        $this->assertSame('audit', $result->capability);
        $this->assertSame(realpath($this->root), $result->data['project_root']);
        $this->assertSame(7, $result->data['summary']['php_files']);
        $this->assertSame(124, $result->data['summary']['lines']);
        $this->assertCount(7, $result->data['files']);
        $this->assertSame([], $result->diagnostics);
        $this->assertSame([], $result->unresolved);
        // ProjectScanner is final, so its one-call behavior is covered by the audit output.
        // The counting parser directly proves that audit never asks CodebaseIndexer to build.
        $this->assertSame($callsBeforeAudit, $parser->calls);
    }

    public function test_it_analyzes_a_class_and_includes_relationships(): void
    {
        $result = $this->service()->analyze($this->root, 'Fixtures\\Checkout\\CheckoutService');

        $this->assertSame('analyze', $result->capability);
        $this->assertSame([
            'target',
            'method',
            'metrics',
            'upstream_dependencies',
            'direct_callers',
            'structural_dependencies',
            'transitive_impact',
            'risk',
        ], array_keys($result->data));
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

        $this->assertSame('CheckoutService.php', $result->data['target']);
        $this->assertSame('CheckoutService.php', $result->data['metrics']['path']);
        $this->assertSame(36, $result->data['metrics']['lines']);
        $this->assertSame(2, $result->data['metrics']['methods']);
        $this->assertSame(6, $result->data['metrics']['dependencies']);
        $this->assertNotEmpty($result->data['upstream_dependencies']);
    }

    public function test_it_analyzes_an_absolute_php_file_path(): void
    {
        $file = realpath($this->root . '/CheckoutService.php');

        $result = $this->service()->analyze($this->root, $file);

        $this->assertSame('CheckoutService.php', $result->data['target']);
        $this->assertSame('CheckoutService.php', $result->data['metrics']['path']);
        $this->assertSame(36, $result->data['metrics']['lines']);
    }

    public function test_it_analyzes_an_existing_class_method(): void
    {
        $result = $this->service()->analyze(
            $this->root,
            'Fixtures\\Payments\\PaymentService::charge',
        );

        $this->assertSame('Fixtures\\Payments\\PaymentService', $result->data['target']);
        $this->assertSame('charge', $result->data['method']);
        $this->assertNotEmpty($result->data['direct_callers']);
        $this->assertNotSame('UNKNOWN', $result->data['risk']);
    }

    public function test_it_canonicalizes_mixed_case_method_targets_for_every_analysis(): void
    {
        $lowerCallers = $this->service()->findCallers(
            $this->root,
            'Fixtures\\Payments\\PaymentService::charge',
        );
        $mixedCallers = $this->service()->findCallers(
            $this->root,
            'Fixtures\\Payments\\PaymentService::Charge',
        );
        $lowerImpact = $this->service()->impact(
            $this->root,
            'Fixtures\\Payments\\PaymentService::charge',
        );
        $mixedImpact = $this->service()->impact(
            $this->root,
            'Fixtures\\Payments\\PaymentService::Charge',
        );
        $lowerAnalyze = $this->service()->analyze(
            $this->root,
            'Fixtures\\Payments\\PaymentService::charge',
        );
        $mixedAnalyze = $this->service()->analyze(
            $this->root,
            'Fixtures\\Payments\\PaymentService::Charge',
        );

        $this->assertSame('charge', $mixedCallers->data['method']);
        $this->assertSame(
            count($lowerCallers->data['direct_callers']),
            count($mixedCallers->data['direct_callers']),
        );
        $this->assertSame('charge', $mixedImpact->data['method']);
        $this->assertSame($lowerImpact->data['direct_callers'], $mixedImpact->data['direct_callers']);
        $this->assertSame('charge', $mixedAnalyze->data['method']);
        $this->assertSame(
            count($lowerAnalyze->data['direct_callers']),
            count($mixedAnalyze->data['direct_callers']),
        );
    }

    public function test_it_aggregates_relationships_for_every_symbol_in_a_file(): void
    {
        $result = $this->service(
            impactAnalyzer: new ImpactAnalyzer([
                'low_max' => 0,
                'medium_max' => 1,
                'high_max' => 2,
            ]),
        )->analyze($this->root, 'StructuralTypes.php');

        $this->assertSame('StructuralTypes.php', $result->data['target']);
        $this->assertCount(8, $result->data['upstream_dependencies']);
        $this->assertCount(1, $result->data['direct_callers']);
        $this->assertCount(6, $result->data['structural_dependencies']);
        $this->assertSame([], $result->data['transitive_impact']);
        $this->assertContains(
            'Fixtures\\Payments\\ExtendedPaymentService',
            array_column($result->data['upstream_dependencies'], 'source'),
        );
        $this->assertContains(
            'Fixtures\\Payments\\ExtendedPaymentService',
            array_column($result->data['direct_callers'], 'source'),
        );
        $this->assertContains(
            'Fixtures\\Payments\\BasePaymentService',
            array_column($result->data['structural_dependencies'], 'target'),
        );
        foreach (['upstream_dependencies', 'direct_callers', 'structural_dependencies'] as $key) {
            $identities = array_map($this->edgeIdentity(...), $result->data[$key]);
            $sorted = $identities;
            sort($sorted, SORT_STRING);
            $this->assertSame($sorted, $identities);
            $this->assertCount(count($identities), array_unique($identities));
        }
        $this->assertSame('MEDIUM', $result->data['risk']);
    }

    public function test_it_rejects_a_method_on_a_multi_symbol_file_as_ambiguous(): void
    {
        try {
            $this->service()->analyze($this->root, 'StructuralTypes.php::compare');
            $this->fail('Expected an ambiguous target error.');
        } catch (CapabilityException $exception) {
            $this->assertSame('AMBIGUOUS_TARGET', $exception->errorCode);
            $this->assertSame(
                'File method target is ambiguous; use a fully qualified class name.',
                $exception->getMessage(),
            );
        }
    }

    public function test_it_rejects_absolute_relative_and_symlink_paths_outside_the_project(): void
    {
        $parent = sys_get_temp_dir() . '/agent-kit-containment-' . bin2hex(random_bytes(6));
        $root = $parent . '/project';
        $outside = $parent . '/Outside.php';
        $link = $root . '/Linked.php';

        try {
            $this->assertTrue(mkdir($root, 0777, true));
            $this->assertNotFalse(file_put_contents($outside, "<?php\nclass Outside {}\n"));
            $this->assertTrue(symlink($outside, $link));

            foreach ([$outside, '../Outside.php', 'Linked.php'] as $target) {
                try {
                    $this->service()->analyze($root, $target);
                    $this->fail("Expected outside-project rejection for {$target}.");
                } catch (CapabilityException $exception) {
                    $this->assertSame('TARGET_OUTSIDE_PROJECT', $exception->errorCode);
                    $this->assertSame(
                        'Target file must be inside the project root.',
                        $exception->getMessage(),
                    );
                }
            }
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            if (is_file($outside)) {
                unlink($outside);
            }
            if (is_dir($root)) {
                rmdir($root);
            }
            if (is_dir($parent)) {
                rmdir($parent);
            }
        }
    }

    public function test_it_analyzes_a_classless_php_file_with_stable_empty_relationships(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-classless-' . bin2hex(random_bytes(6));
        $file = $root . '/Standalone.php';

        try {
            $this->assertTrue(mkdir($root, 0777, true));
            $this->assertNotFalse(file_put_contents($file, "<?php\n\nfunction helper(): void {}\n"));

            $result = $this->service()->analyze($root, 'Standalone.php');

            $this->assertSame([
                'target' => 'Standalone.php',
                'method' => null,
                'metrics' => [
                    'path' => 'Standalone.php',
                    'lines' => 4,
                    'methods' => 1,
                    'dependencies' => 0,
                    'branches' => 0,
                    'smells' => [],
                ],
                'upstream_dependencies' => [],
                'direct_callers' => [],
                'structural_dependencies' => [],
                'transitive_impact' => [],
                'risk' => 'UNKNOWN',
            ], $result->data);

            try {
                $this->service()->analyze($root, 'Standalone.php::helper');
                $this->fail('Expected an ambiguous target error.');
            } catch (CapabilityException $exception) {
                $this->assertSame('AMBIGUOUS_TARGET', $exception->errorCode);
                $this->assertSame(
                    'File method target is ambiguous; use a fully qualified class name.',
                    $exception->getMessage(),
                );
            }
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
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
        $parser = $this->countingParser();

        $this->service($parser)->analyze($this->root, 'Fixtures\\Payments\\PaymentService');

        $this->assertSame(7, $parser->calls);
    }

    private function countingParser(): AstParser
    {
        return new class(new PhpAstParser()) implements AstParser {
            public int $calls = 0;

            public function __construct(private readonly AstParser $inner) {}

            public function parse(string $file, ?string $displayPath = null): ParsedFile
            {
                $this->calls++;

                return $this->inner->parse($file, $displayPath);
            }
        };
    }

    private function edgeIdentity(array $edge): string
    {
        return implode("\0", [
            $edge['source'],
            $edge['source_method'] ?? '',
            $edge['target'],
            $edge['target_method'] ?? '',
            $edge['type'],
            $edge['file'],
            (string) $edge['line'],
        ]);
    }

    private function service(
        ?AstParser $parser = null,
        ?ImpactAnalyzer $impactAnalyzer = null,
    ): DefaultRefactoringCapabilities
    {
        $fileAnalyzer = new PhpFileAnalyzer();
        $scanner = new ProjectScanner($fileAnalyzer);

        return new DefaultRefactoringCapabilities(
            $scanner,
            $fileAnalyzer,
            new RefactoringReport(),
            new CodebaseIndexer($scanner, $parser ?? new PhpAstParser()),
            new CallerAnalyzer(),
            $impactAnalyzer ?? new ImpactAnalyzer(),
        );
    }
}
