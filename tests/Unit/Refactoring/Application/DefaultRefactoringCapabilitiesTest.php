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
                'mcp_tool' => 'refactoring_audit',
                'cli_fallback' => 'php artisan agent-kit:refactor-audit --json',
                'json' => true,
            ],
            [
                'name' => 'analyze',
                'targets' => ['file', 'class', 'method'],
                'mcp_tool' => 'refactoring_analyze',
                'cli_fallback' => 'php artisan agent-kit:refactor-analyze <target> --json',
                'json' => true,
            ],
            [
                'name' => 'find_callers',
                'targets' => ['class', 'method'],
                'mcp_tool' => 'refactoring_callers',
                'cli_fallback' => 'php artisan agent-kit:refactor-callers <class> --method=<method> --json',
                'json' => true,
            ],
            [
                'name' => 'dependencies',
                'targets' => ['class'],
                'mcp_tool' => 'refactoring_dependencies',
                'cli_fallback' => 'php artisan agent-kit:refactor-dependencies <class> --json',
                'json' => true,
            ],
            [
                'name' => 'impact',
                'targets' => ['class', 'method'],
                'mcp_tool' => 'refactoring_impact',
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

    public function test_class_targets_are_case_insensitive_and_all_outputs_use_declared_spelling(): void
    {
        $class = 'fixtures\\payments\\paymentservice';

        $analyze = $this->service()->analyze($this->root, $class . '::CHARGE');
        $callers = $this->service()->findCallers($this->root, $class . '::CHARGE');
        $dependencies = $this->service()->dependencies($this->root, $class);
        $impact = $this->service()->impact($this->root, $class . '::CHARGE');

        $this->assertSame('Fixtures\\Payments\\PaymentService', $analyze->data['target']);
        $this->assertSame('charge', $analyze->data['method']);
        $this->assertSame('Fixtures\\Payments\\PaymentService', $callers->data['target']);
        $this->assertSame('charge', $callers->data['method']);
        $this->assertSame('Fixtures\\Payments\\PaymentService', $dependencies->data['target']);
        $this->assertSame('Fixtures\\Payments\\PaymentService', $impact->data['target']);
        $this->assertSame('charge', $impact->data['method']);
    }

    public function test_mixed_case_call_sites_are_canonical_in_callers_dependencies_and_impact(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-call-case-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        file_put_contents($root . '/Services.php', <<<'PHP'
<?php
namespace Demo;
final class PaymentService { public function charge(): void {} }
final class Caller {
    public function __construct(private PaymentService $service) {}
    public function run(): void { $this->service->Charge(); }
}
PHP);

        try {
            $callers = $this->service()->findCallers($root, 'demo\\paymentservice::CHARGE');
            $dependencies = $this->service()->dependencies($root, 'DEMO\\PAYMENTSERVICE');
            $impact = $this->service()->impact($root, 'demo\\paymentservice::charge');

            $this->assertSame('Demo\\PaymentService', $callers->data['target']);
            $this->assertSame('charge', $callers->data['method']);
            $this->assertSame('Demo\\PaymentService', $callers->data['direct_callers'][0]['target']);
            $this->assertSame('charge', $callers->data['direct_callers'][0]['target_method']);
            $this->assertSame('Demo\\PaymentService', $dependencies->data['target']);
            $this->assertContains('Demo\\Caller', array_column($dependencies->data['downstream_dependents'], 'source'));
            $this->assertSame('Demo\\PaymentService', $impact->data['target']);
            $this->assertSame('charge', $impact->data['method']);
            $this->assertSame('Demo\\PaymentService', $impact->data['direct'][0]['target']);
        } finally {
            unlink($root . '/Services.php');
            rmdir($root);
        }
    }

    public function test_same_class_method_calls_are_included_without_self_transitive_cycles(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-this-call-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        file_put_contents($root . '/Service.php', <<<'PHP'
<?php
namespace Demo;
final class Service {
    public function run(): void { $this->charge(); }
    private function charge(): void {}
}
PHP);

        try {
            $callers = $this->service()->findCallers($root, 'demo\\service::CHARGE');
            $impact = $this->service()->impact($root, 'DEMO\\SERVICE::charge');

            $this->assertSame('Demo\\Service', $callers->data['target']);
            $this->assertSame('run', $callers->data['direct_callers'][0]['source_method']);
            $this->assertSame([], $callers->data['transitive_dependents']);
            $this->assertSame(1, $impact->data['direct_callers']);
            $this->assertSame([], $impact->data['transitive']);
        } finally {
            unlink($root . '/Service.php');
            rmdir($root);
        }
    }

    public function test_fqcn_operations_reject_case_insensitive_duplicate_declarations_but_file_analysis_works(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-ambiguous-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        file_put_contents($root . '/First.php', '<?php namespace Demo; class Service { public function run(): void {} }');
        file_put_contents($root . '/Second.php', '<?php namespace demo; class service { public function run(): void {} }');

        try {
            foreach ([
                'analyze' => 'DEMO\\SERVICE::run',
                'findCallers' => 'DEMO\\SERVICE::run',
                'dependencies' => 'DEMO\\SERVICE',
                'impact' => 'DEMO\\SERVICE::run',
            ] as $operation => $target) {
                try {
                    $this->service()->{$operation}($root, $target);
                    $this->fail("Expected {$operation} to reject an ambiguous class.");
                } catch (CapabilityException $exception) {
                    $this->assertSame('AMBIGUOUS_TARGET', $exception->errorCode);
                }
            }

            $file = $this->service()->analyze($root, 'First.php');
            $this->assertSame('First.php', $file->data['target']);
            $this->assertSame('First.php', $file->data['metrics']['path']);
            $method = $this->service()->analyze($root, 'First.php::RUN');
            $this->assertSame('run', $method->data['method']);
        } finally {
            unlink($root . '/First.php');
            unlink($root . '/Second.php');
            rmdir($root);
        }
    }

    public function test_external_php_symlinks_are_not_indexed_by_any_capability(): void
    {
        $parent = sys_get_temp_dir() . '/agent-kit-external-' . bin2hex(random_bytes(6));
        $root = $parent . '/project';
        $outside = $parent . '/External.php';
        mkdir($root, 0777, true);
        file_put_contents($root . '/Internal.php', '<?php namespace Demo; class Internal {}');
        file_put_contents($outside, '<?php namespace Secret; class External {}');

        try {
            if (!function_exists('symlink') || !@symlink($outside, $root . '/Linked.php')) {
                $this->markTestSkipped('Symbolic links are not available in this environment.');
            }

            $audit = $this->service()->audit($root);
            $this->assertSame(1, $audit->data['summary']['php_files']);
            foreach (['analyze', 'findCallers', 'dependencies', 'impact'] as $operation) {
                try {
                    $this->service()->{$operation}($root, 'Secret\\External');
                    $this->fail("Expected {$operation} not to resolve an external class.");
                } catch (CapabilityException $exception) {
                    $this->assertSame('TARGET_NOT_FOUND', $exception->errorCode);
                }
            }
        } finally {
            if (is_link($root . '/Linked.php')) {
                unlink($root . '/Linked.php');
            }
            unlink($root . '/Internal.php');
            unlink($outside);
            rmdir($root);
            rmdir($parent);
        }
    }

    public function test_dynamic_laravel_references_make_capability_results_incomplete(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-dynamic-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        file_put_contents($root . '/DynamicService.php', <<<'PHP'
<?php
namespace Demo;
final class DynamicService {
    public function resolve(string $className): void { app($className); }
}
PHP);

        try {
            $result = $this->service()->analyze($root, 'Demo\\DynamicService');

            $this->assertTrue($result->incomplete());
            $this->assertCount(1, $result->unresolved);
            $this->assertNull($result->unresolved[0]['target']);
            $this->assertSame('unknown', $result->unresolved[0]['confidence']);
            $this->assertSame(['resolution' => 'app'], $result->unresolved[0]['metadata']);
        } finally {
            unlink($root . '/DynamicService.php');
            rmdir($root);
        }
    }

    public function test_duplicate_declaration_file_analysis_reports_omitted_edges_as_incomplete(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-ambiguous-incomplete-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        file_put_contents($root . '/Consumer.php', '<?php namespace Demo; class Consumer { function run(Service $service): void { $service->go(); } }');
        file_put_contents($root . '/First.php', '<?php namespace Demo; class Service { function go(): void {} }');
        file_put_contents($root . '/Second.php', '<?php namespace demo; class service { function go(): void {} }');

        try {
            $result = $this->service()->analyze($root, 'First.php');

            $this->assertTrue($result->incomplete());
            $this->assertNotEmpty($result->unresolved);
            $this->assertContains('ambiguous_target', array_column(array_column($result->unresolved, 'metadata'), 'reason'));
            $this->assertSame([], $result->data['direct_callers']);
            foreach (['findCallers', 'dependencies', 'impact'] as $operation) {
                $consumer = $this->service()->{$operation}($root, 'Demo\\Consumer');
                $this->assertTrue($consumer->incomplete(), $operation);
                $this->assertContains(
                    'ambiguous_target',
                    array_column(array_column($consumer->unresolved, 'metadata'), 'reason'),
                    $operation,
                );
            }
        } finally {
            unlink($root . '/Consumer.php');
            unlink($root . '/First.php');
            unlink($root . '/Second.php');
            rmdir($root);
        }
    }

    public function test_duplicate_declarations_without_relationships_still_make_each_file_incomplete(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-ambiguous-empty-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        file_put_contents($root . '/First.php', '<?php namespace Demo; class Service {}');
        file_put_contents($root . '/Second.php', '<?php namespace demo; class service {}');

        try {
            foreach (['First.php', 'Second.php'] as $file) {
                $result = $this->service()->analyze($root, $file);
                $this->assertTrue($result->incomplete(), $file);
                $this->assertCount(2, $result->diagnostics, $file);
                $this->assertSame(['First.php', 'Second.php'], array_column($result->diagnostics, 'file'));
                $this->assertSame([], $result->data['direct_callers']);
                $this->assertSame('LOW', $result->data['risk']);
            }
        } finally {
            unlink($root . '/First.php');
            unlink($root . '/Second.php');
            rmdir($root);
        }
    }

    public function test_edge_deduplication_keeps_same_line_events_with_distinct_metadata(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-edge-metadata-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        file_put_contents($root . '/Publisher.php', '<?php namespace Demo; class Publisher { function run(): void { event(new Message()); dispatch(new Message()); } }');
        file_put_contents($root . '/Message.php', '<?php namespace Demo; class Message {}');

        try {
            $result = $this->service()->analyze($root, 'Demo\\Publisher');
            $events = array_values(array_filter(
                $result->data['upstream_dependencies'],
                fn (array $edge) => $edge['type'] === 'event',
            ));

            $this->assertCount(2, $events);
            $this->assertSame(
                [['dispatch_kind' => 'event'], ['dispatch_kind' => 'job']],
                array_column($events, 'metadata'),
            );
        } finally {
            unlink($root . '/Publisher.php');
            unlink($root . '/Message.php');
            rmdir($root);
        }
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

    public function test_it_always_rejects_absolute_and_relative_paths_outside_the_project(): void
    {
        $parent = sys_get_temp_dir() . '/agent-kit-containment-' . bin2hex(random_bytes(6));
        $root = $parent . '/project';
        $outside = $parent . '/Outside.php';

        try {
            $this->assertTrue(mkdir($root, 0777, true));
            $this->assertNotFalse(file_put_contents($outside, "<?php\nclass Outside {}\n"));

            foreach ([$outside, '../Outside.php'] as $target) {
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

    public function test_it_rejects_a_symlink_that_escapes_the_project(): void
    {
        $parent = sys_get_temp_dir() . '/agent-kit-symlink-' . bin2hex(random_bytes(6));
        $root = $parent . '/project';
        $outside = $parent . '/Outside.php';
        $link = $root . '/Linked.php';

        try {
            $this->assertTrue(mkdir($root, 0777, true));
            $this->assertNotFalse(file_put_contents($outside, "<?php\nclass Outside {}\n"));
            if (!function_exists('symlink') || !@symlink($outside, $link)) {
                $this->markTestSkipped('Symbolic links are not available in this environment.');
            }
            $this->assertTrue(is_link($link));

            try {
                $this->service()->analyze($root, 'Linked.php');
                $this->fail('Expected outside-project rejection for a symbolic link.');
            } catch (CapabilityException $exception) {
                $this->assertSame('TARGET_OUTSIDE_PROJECT', $exception->errorCode);
                $this->assertSame(
                    'Target file must be inside the project root.',
                    $exception->getMessage(),
                );
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
                'risk' => 'LOW',
            ], $result->data);

            $function = $this->service()->analyze($root, 'Standalone.php::helper');
            $this->assertSame('Standalone.php', $function->data['target']);
            $this->assertSame('helper', $function->data['method']);
            $this->assertSame([], $function->data['upstream_dependencies']);
            $this->assertSame('UNKNOWN', $function->data['risk']);
            $this->assertSame([[
                'file' => 'Standalone.php',
                'line' => 3,
                'message' => 'Calls to user-defined functions are not indexed; caller and impact results for Standalone.php::helper are incomplete.',
            ]], $function->toArray()['diagnostics']);
            $this->assertTrue($function->incomplete());
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    public function test_a_method_target_on_a_file_without_routines_is_unsupported(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-no-routines-' . bin2hex(random_bytes(6));
        $file = $root . '/Config.php';

        try {
            $this->assertTrue(mkdir($root, 0777, true));
            $this->assertNotFalse(file_put_contents($file, "<?php\n\nreturn ['debug' => false];\n"));

            try {
                $this->service()->analyze($root, 'Config.php::helper');
                $this->fail('Expected an unsupported target error.');
            } catch (CapabilityException $exception) {
                $this->assertSame('UNSUPPORTED_TARGET', $exception->errorCode);
                $this->assertSame(
                    'A method target requires a class or top-level function declaration.',
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

    public function test_file_risk_uses_the_union_of_unique_dependents_across_symbols(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-union-risk-' . bin2hex(random_bytes(6));
        $symbols = $root . '/Symbols.php';
        $callers = $root . '/Callers.php';

        try {
            $this->assertTrue(mkdir($root, 0777, true));
            $this->assertNotFalse(file_put_contents($symbols, <<<'PHP'
<?php
namespace RiskFixture;
class Alpha { public static function run(): void {} }
class Beta { public static function run(): void {} }
class Gamma { public static function run(): void {} }
PHP));
            $this->assertNotFalse(file_put_contents($callers, <<<'PHP'
<?php
namespace RiskFixture;
class AlphaCaller { public function call(): void { Alpha::run(); } }
class BetaCaller { public function call(): void { Beta::run(); } }
class GammaCaller { public function call(): void { Gamma::run(); } }
PHP));

            $service = $this->service();
            $this->assertSame('LOW', $service->analyze($root, 'RiskFixture\\Alpha')->data['risk']);
            $this->assertSame('LOW', $service->analyze($root, 'RiskFixture\\Beta')->data['risk']);
            $this->assertSame('LOW', $service->analyze($root, 'RiskFixture\\Gamma')->data['risk']);

            $fileResult = $service->analyze($root, 'Symbols.php');

            $this->assertCount(3, $fileResult->data['direct_callers']);
            $this->assertSame('MEDIUM', $fileResult->data['risk']);
        } finally {
            if (is_file($callers)) {
                unlink($callers);
            }
            if (is_file($symbols)) {
                unlink($symbols);
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    public function test_it_preserves_a_filesystem_root_during_normalization(): void
    {
        $method = new \ReflectionMethod(DefaultRefactoringCapabilities::class, 'projectRoot');

        $this->assertSame(
            realpath(DIRECTORY_SEPARATOR),
            $method->invoke($this->service(), DIRECTORY_SEPARATOR),
        );
    }

    public function test_filesystem_root_containment_accepts_a_direct_child(): void
    {
        $root = (string) realpath(DIRECTORY_SEPARATOR);
        $child = $root . (str_ends_with($root, DIRECTORY_SEPARATOR) ? '' : DIRECTORY_SEPARATOR)
            . 'tmp' . DIRECTORY_SEPARATOR . 'RootChild.php';
        $method = new \ReflectionMethod(DefaultRefactoringCapabilities::class, 'ensureInsideProject');

        $method->invoke($this->service(), $root, $child);

        $this->addToAssertionCount(1);
    }

    public function test_filesystem_root_relativization_uses_one_separator(): void
    {
        $root = (string) realpath(DIRECTORY_SEPARATOR);
        $child = $root . (str_ends_with($root, DIRECTORY_SEPARATOR) ? '' : DIRECTORY_SEPARATOR)
            . 'tmp' . DIRECTORY_SEPARATOR . 'RootChild.php';
        $method = new \ReflectionMethod(DefaultRefactoringCapabilities::class, 'relativePath');

        $this->assertSame('tmp/RootChild.php', $method->invoke($this->service(), $root, $child));
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

    public function test_it_indexes_a_laravel_like_project_with_procedural_files(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-procedural-' . bin2hex(random_bytes(6));
        mkdir($root . '/app/Services', 0777, true);
        mkdir($root . '/routes');
        mkdir($root . '/config');
        mkdir($root . '/bootstrap');
        mkdir($root . '/database/migrations', 0777, true);
        $files = [
            '/app/Services/PaymentService.php' => '<?php namespace App\Services; class PaymentService { public function charge(): void {} }',
            '/app/Services/Checkout.php' => '<?php namespace App\Services; class Checkout { public function __construct(private PaymentService $payments) {} public function run(): void { $this->payments->charge(); } }',
            '/routes/web.php' => "<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('/', function () { return 'home'; });\nRoute::middleware(['auth'])->group(function () { Route::get('/pay', fn () => 'pay'); });",
            '/config/app.php' => "<?php\nreturn ['debug' => env('APP_DEBUG', false) ? true : false, 'url' => \$_ENV['APP_URL'] ?? 'http://localhost'];",
            '/bootstrap/app.php' => "<?php\n\$app = new Illuminate\\Foundation\\Application(dirname(__DIR__));\nif (file_exists(__DIR__ . '/cache')) { \$app->useStoragePath(__DIR__); }\nreturn \$app;",
            '/database/migrations/2026_01_01_000000_create_payments_table.php' => "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nreturn new class extends Migration { public function up(): void { if (true) { \$table = fn () => 'payments'; } } };",
        ];
        foreach ($files as $path => $code) {
            file_put_contents($root . $path, $code);
        }
        set_error_handler(static function (int $severity, string $message, string $filename, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $filename, $line);
        });

        try {
            $impact = $this->service()->impact($root, 'App\\Services\\PaymentService::charge');
            $callers = $this->service()->findCallers($root, 'App\\Services\\PaymentService::charge');
        } finally {
            restore_error_handler();
            foreach (array_keys($files) as $path) {
                unlink($root . $path);
            }
            foreach (['/database/migrations', '/database', '/bootstrap', '/config', '/routes', '/app/Services', '/app', ''] as $directory) {
                rmdir($root . $directory);
            }
        }

        $this->assertSame('App\\Services\\PaymentService', $impact->data['target']);
        $this->assertSame(1, $impact->data['direct_callers']);
        $this->assertSame(['App\\Services\\Checkout'], array_column($callers->data['direct_callers'], 'source'));
        $this->assertSame([], $impact->diagnostics);
    }

    public function test_an_analysis_failure_in_one_file_is_reported_as_an_envelope_diagnostic(): void
    {
        $parser = new class(new PhpAstParser()) implements AstParser {
            public function __construct(private readonly AstParser $inner) {}

            public function parse(string $file, ?string $displayPath = null): ParsedFile
            {
                if (str_ends_with($file, 'LogsPayments.php')) {
                    throw new \RuntimeException('boom');
                }

                return $this->inner->parse($file, $displayPath);
            }
        };

        $result = $this->service($parser)->impact($this->root, 'Fixtures\\Payments\\PaymentService::charge');

        $this->assertSame('Fixtures\\Payments\\PaymentService', $result->data['target']);
        $this->assertSame(
            [['file' => 'LogsPayments.php', 'line' => 1, 'message' => 'Analysis failed: RuntimeException: boom']],
            $result->toArray()['diagnostics'],
        );
        $this->assertTrue($result->incomplete());
    }

    public function test_scripts_are_reported_as_dependents_and_accepted_as_targets(): void
    {
        $this->withProject([
            'app/Http/Controllers/UserController.php' => '<?php namespace App\Http\Controllers; use App\Services\UserMaker; class UserController { public function __construct(private UserMaker $maker) {} public function index(): void { $this->maker->make(); } }',
            'app/Services/UserMaker.php' => '<?php namespace App\Services; class UserMaker { public function make(): void {} }',
            'routes/web.php' => "<?php\nuse App\\Http\\Controllers\\UserController;\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('/users', [UserController::class, 'index']);\nRoute::get('/make', function () { \$maker = app(\\App\\Services\\UserMaker::class); return \$maker->make(); });",
            'app/helpers.php' => "<?php\nuse App\\Services\\UserMaker;\nif (!function_exists('make_user')) {\n    function make_user(UserMaker \$maker): void { \$maker->make(); }\n}",
        ], function (string $root): void {
            $callers = $this->service()->findCallers($root, 'App\\Services\\UserMaker::make');
            $this->assertSame(
                [['App\\Http\\Controllers\\UserController', 'index'], ['app/helpers.php', 'make_user'], ['routes/web.php', null]],
                array_map(fn (array $edge) => [$edge['source'], $edge['source_method']], $callers->data['direct_callers']),
            );
            $this->assertSame([], $callers->diagnostics);

            $impact = $this->service()->impact($root, 'App\\Services\\UserMaker');
            $this->assertSame(3, $impact->data['direct_callers']);
            $this->assertSame(3, $impact->data['affected_files']);

            $dependencies = $this->service()->dependencies($root, 'routes/web.php');
            $this->assertSame('routes/web.php', $dependencies->data['target']);
            $this->assertContains('App\\Http\\Controllers\\UserController', array_column($dependencies->data['upstream_dependencies'], 'target'));
            $this->assertSame([], $dependencies->data['downstream_dependents']);
            $this->assertSame([], $dependencies->data['transitive_dependents']);

            $routes = $this->service()->analyze($root, 'routes/web.php');
            $this->assertSame('routes/web.php', $routes->data['target']);
            $this->assertNull($routes->data['method']);
            $this->assertContains('App\\Services\\UserMaker', array_column($routes->data['upstream_dependencies'], 'target'));

            $function = $this->service()->analyze($root, 'app/helpers.php::make_user');
            $this->assertSame('app/helpers.php', $function->data['target']);
            $this->assertSame('make_user', $function->data['method']);
            $this->assertSame('UNKNOWN', $function->data['risk']);
            $this->assertTrue($function->incomplete());
            $this->assertStringContainsString('app/helpers.php::make_user are incomplete', $function->toArray()['diagnostics'][0]['message']);

            $functionCallers = $this->service()->findCallers($root, 'app/helpers.php::make_user');
            $this->assertSame([], $functionCallers->data['direct_callers']);
            $this->assertTrue($functionCallers->incomplete());
            $this->assertSame(4, $functionCallers->toArray()['diagnostics'][0]['line']);

            $functionImpact = $this->service()->impact($root, 'app/helpers.php::make_user');
            $this->assertSame('UNKNOWN', $functionImpact->data['risk']);
            $this->assertTrue($functionImpact->incomplete());
        });
    }

    public function test_a_class_file_with_top_level_code_keeps_class_method_targets_unambiguous(): void
    {
        $this->withProject([
            'app/Support/Clock.php' => "<?php\nnamespace App\\Support;\nclass Clock { public function now(): int { return time(); } }\nClock::class;",
        ], function (string $root): void {
            $result = $this->service()->analyze($root, 'app/Support/Clock.php::now');

            $this->assertSame('app/Support/Clock.php', $result->data['target']);
            $this->assertSame('now', $result->data['method']);

            try {
                $this->service()->analyze($root, 'app/Support/Clock.php::missing');
                $this->fail('Expected TARGET_NOT_FOUND.');
            } catch (CapabilityException $exception) {
                $this->assertSame('TARGET_NOT_FOUND', $exception->errorCode);
                $this->assertSame('Method not found: App\\Support\\Clock::missing', $exception->getMessage());
            }
        });
    }

    public function test_a_file_with_two_classes_and_script_code_stays_ambiguous_for_method_targets(): void
    {
        $this->withProject([
            'app/Pair.php' => "<?php\nnamespace App;\nclass First { public function go(): void {} }\nclass Second { public function go(): void {} }\nFirst::class;",
        ], function (string $root): void {
            try {
                $this->service()->analyze($root, 'app/Pair.php::go');
                $this->fail('Expected an ambiguous target error.');
            } catch (CapabilityException $exception) {
                $this->assertSame('AMBIGUOUS_TARGET', $exception->errorCode);
            }
        });
    }

    /** @param array<string, string> $files root-relative path => contents */
    private function withProject(array $files, callable $test): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-project-' . bin2hex(random_bytes(6));
        foreach ($files as $path => $code) {
            $directory = dirname($root . '/' . $path);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($root . '/' . $path, $code);
        }

        try {
            $test($root);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
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
