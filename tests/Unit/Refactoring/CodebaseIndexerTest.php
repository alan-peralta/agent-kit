<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring;

use Peralta\AgentKit\Exceptions\MissingDependencyException;
use Peralta\AgentKit\Refactoring\Analysis\Ast\AstParser;
use Peralta\AgentKit\Refactoring\Analysis\Ast\PhpAstParser;
use Peralta\AgentKit\Refactoring\Analysis\Ast\PhpParserRequirement;
use Peralta\AgentKit\Refactoring\Analysis\DTOs\ParsedFile;
use Peralta\AgentKit\Refactoring\Analysis\DTOs\SymbolDefinition;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyType;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexer;
use Peralta\AgentKit\Refactoring\Support\PhpFileAnalyzer;
use Peralta\AgentKit\Refactoring\Support\ProjectScanner;
use PHPUnit\Framework\TestCase;

final class CodebaseIndexerTest extends TestCase
{
    public function test_its_normalization_seam_preserves_a_filesystem_root_without_building(): void
    {
        $scanner = new ProjectScanner(new PhpFileAnalyzer());
        $indexer = new CodebaseIndexer($scanner, new PhpAstParser());
        $method = new \ReflectionMethod($indexer, 'normalizedRoot');

        $this->assertSame(realpath(DIRECTORY_SEPARATOR), $method->invoke($indexer, DIRECTORY_SEPARATOR));
    }

    public function test_it_parses_every_discovered_file_once(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-index-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        file_put_contents($root . '/A.php', '<?php class A {}');
        file_put_contents($root . '/B.php', '<?php class B {}');

        $parser = new class implements AstParser {
            public array $calls = [];

            public function parse(string $file, ?string $displayPath = null): ParsedFile
            {
                $this->calls[] = $file;
                $name = pathinfo($file, PATHINFO_FILENAME);

                return new ParsedFile($displayPath ?? $file, [
                    new SymbolDefinition("Fixtures\\{$name}", 'class', $displayPath ?? $file, 1),
                ]);
            }
        };

        $scanner = new ProjectScanner(new PhpFileAnalyzer());
        $index = (new CodebaseIndexer($scanner, $parser))->build($root);

        $this->assertCount(2, $parser->calls);
        $this->assertCount(2, array_unique($parser->calls));
        $this->assertNotNull($index->findClass('Fixtures\\A'));
        $this->assertNotNull($index->findClass('\\Fixtures\\B'));

        unlink($root . '/A.php');
        unlink($root . '/B.php');
        rmdir($root);
    }

    public function test_a_failing_file_becomes_a_diagnostic_and_indexing_continues(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-index-failure-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        file_put_contents($root . '/Broken.php', '<?php class Broken {}');
        file_put_contents($root . '/Fine.php', '<?php class Fine {}');

        $parser = new class implements AstParser {
            public function parse(string $file, ?string $displayPath = null): ParsedFile
            {
                if (str_ends_with($file, 'Broken.php')) {
                    throw new \TypeError('Cannot assign null to property StructureCollector::$localTypes of type array');
                }

                return new ParsedFile($displayPath ?? $file, [
                    new SymbolDefinition('Fine', 'class', $displayPath ?? $file, 1),
                ]);
            }
        };

        try {
            $index = (new CodebaseIndexer(new ProjectScanner(new PhpFileAnalyzer()), $parser))->build($root);
        } finally {
            unlink($root . '/Broken.php');
            unlink($root . '/Fine.php');
            rmdir($root);
        }

        $this->assertNotNull($index->findClass('Fine'));
        $this->assertSame([[
            'file' => 'Broken.php',
            'line' => 1,
            'message' => 'Analysis failed: TypeError: Cannot assign null to property StructureCollector::$localTypes of type array',
        ]], array_map(fn ($diagnostic) => $diagnostic->toArray(), $index->diagnostics()));
    }

    public function test_analysis_failure_diagnostics_keep_only_basenames_of_absolute_paths(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-index-redact-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        file_put_contents($root . '/Broken.php', '<?php class Broken {}');

        $parser = new class implements AstParser {
            public function parse(string $file, ?string $displayPath = null): ParsedFile
            {
                throw new \RuntimeException(
                    "Não foi possível ler {$file}, called in /opt/tool/src/StructureCollector.php on line 12 (C:\\tool\\Collector.php). Class App\\Services\\Foo stays.",
                );
            }
        };

        try {
            $index = (new CodebaseIndexer(new ProjectScanner(new PhpFileAnalyzer()), $parser))->build($root);
        } finally {
            unlink($root . '/Broken.php');
            rmdir($root);
        }

        $this->assertSame(
            'Analysis failed: RuntimeException: Não foi possível ler Broken.php, called in StructureCollector.php on line 12 (Collector.php). Class App\\Services\\Foo stays.',
            $index->diagnostics()[0]->message,
        );
    }

    public function test_it_indexes_real_declarations_and_reverse_references(): void
    {
        $root = dirname(__DIR__, 2) . '/Fixtures/Refactoring/Ast';
        $scanner = new ProjectScanner(new PhpFileAnalyzer());
        $index = (new CodebaseIndexer($scanner, new PhpAstParser()))->build($root);

        $this->assertSame('class', $index->findClass('Fixtures\\Payments\\PaymentService')->kind);
        $this->assertSame('interface', $index->findClass('Fixtures\\Payments\\PaymentGateway')->kind);
        $this->assertSame('trait', $index->findClass('Fixtures\\Payments\\LogsPayments')->kind);
        $this->assertSame('enum', $index->findClass('Fixtures\\Payments\\PaymentStatus')->kind);
        $this->assertSame('charge', $index->findMethod('Fixtures\\Payments\\PaymentService', 'charge')['name']);
        $this->assertContains(
            'Fixtures\\Payments\\PaymentService',
            array_map(fn ($symbol) => $symbol->fqcn, $index->classesInFile('PaymentService.php')),
        );
        $this->assertNotEmpty($index->findReferencesTo('Fixtures\\Payments\\PaymentService'));
        $this->assertNotEmpty($index->findDependencies('Fixtures\\Checkout\\CheckoutService'));
        $this->assertNotEmpty($index->findMethodCalls('Fixtures\\Payments\\PaymentService', 'charge'));
        $this->assertSame(
            [DependencyType::METHOD_CALL->value],
            array_values(array_unique(array_map(fn ($edge) => $edge->type->value, $index->findMethodCalls('Fixtures\\Payments\\PaymentService', 'charge')))),
        );
        $this->assertNotEmpty($index->unresolvedReferences());
        $this->assertContains('unknown', array_column($index->unresolvedReferences(), 'confidence'));
        $this->assertContains(null, array_column($index->unresolvedReferences(), 'target'), true);
    }

    public function test_class_and_method_lookups_are_case_insensitive_but_preserve_declared_spelling(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-index-case-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        file_put_contents($root . '/Service.php', <<<'PHP'
<?php
namespace Demo;
final class PaymentService { public function charge(): void {} }
final class Caller { public function run(PaymentService $service): void { $service->Charge(); } }
PHP);

        try {
            $index = (new CodebaseIndexer(
                new ProjectScanner(new PhpFileAnalyzer()),
                new PhpAstParser(),
            ))->build($root);

            $this->assertSame('Demo\\PaymentService', $index->findClass('demo\\paymentservice')?->fqcn);
            $this->assertSame('charge', $index->findMethod('DEMO\\PAYMENTSERVICE', 'CHARGE')['name']);
            $calls = $index->findMethodCalls('demo\\paymentservice', 'charge');
            $this->assertCount(1, $calls);
            $this->assertSame('Demo\\Caller', $calls[0]->source);
            $this->assertSame('Demo\\PaymentService', $calls[0]->target);
            $this->assertSame('charge', $calls[0]->targetMethod);
        } finally {
            unlink($root . '/Service.php');
            rmdir($root);
        }
    }

    public function test_it_retains_case_insensitive_duplicate_declarations_as_ambiguous(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-index-duplicate-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        file_put_contents($root . '/First.php', '<?php namespace Demo; class Service { function run(): void {} }');
        file_put_contents($root . '/Second.php', '<?php namespace demo; class service { function execute(): void {} }');

        try {
            $index = (new CodebaseIndexer(
                new ProjectScanner(new PhpFileAnalyzer()),
                new PhpAstParser(),
            ))->build($root);

            $this->assertTrue($index->isClassAmbiguous('DEMO\\SERVICE'));
            $this->assertSame(
                ['First.php', 'Second.php'],
                array_column($index->classDeclarations('demo\\service'), 'file'),
            );
            $this->assertSame('First.php', $index->findClass('Demo\\Service')?->file);
            $this->assertNull($index->findMethod('Demo\\Service', 'run'));
            $this->assertNull($index->graph()->node('Demo\\Service'));
        } finally {
            unlink($root . '/First.php');
            unlink($root . '/Second.php');
            rmdir($root);
        }
    }

    public function test_it_indexes_dynamic_container_resolution_as_an_unresolved_reference(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-index-dynamic-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        file_put_contents($root . '/Dynamic.php', <<<'PHP'
<?php
namespace Demo;
class Dynamic { function run(string $className): void { app($className); } }
PHP);

        try {
            $index = (new CodebaseIndexer(
                new ProjectScanner(new PhpFileAnalyzer()),
                new PhpAstParser(),
            ))->build($root);

            $this->assertSame([[
                'source' => 'Demo\\Dynamic',
                'source_method' => 'run',
                'target' => null,
                'target_method' => null,
                'type' => 'instantiation',
                'confidence' => 'unknown',
                'file' => 'Dynamic.php',
                'line' => 3,
                'metadata' => ['resolution' => 'app'],
            ]], $index->unresolvedReferences());
        } finally {
            unlink($root . '/Dynamic.php');
            rmdir($root);
        }
    }

    public function test_edges_omitted_for_ambiguous_declarations_are_retained_as_unresolved_evidence(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-index-ambiguous-edge-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        file_put_contents($root . '/Consumer.php', '<?php namespace Demo; class Consumer { function run(Service $service): void { $service->go(); } }');
        file_put_contents($root . '/First.php', '<?php namespace Demo; class Service { function go(): void {} }');
        file_put_contents($root . '/Second.php', '<?php namespace demo; class service { function go(): void {} }');

        try {
            $index = (new CodebaseIndexer(
                new ProjectScanner(new PhpFileAnalyzer()),
                new PhpAstParser(),
            ))->build($root);

            $this->assertSame([], $index->findReferencesTo('Demo\\Service'));
            $ambiguous = array_values(array_filter(
                $index->unresolvedReferences(),
                fn (array $reference) => ($reference['metadata']['reason'] ?? null) === 'ambiguous_target',
            ));
            $this->assertCount(2, $ambiguous);
            $this->assertSame(
                ['method_parameter', 'method_call'],
                array_column($ambiguous, 'type'),
            );
            foreach ($ambiguous as $reference) {
                $this->assertNull($reference['target']);
                $this->assertSame('unknown', $reference['confidence']);
                $this->assertSame('Demo\\Service', $reference['metadata']['original_target']);
                $this->assertArrayHasKey('original_metadata', $reference['metadata']);
            }
        } finally {
            unlink($root . '/Consumer.php');
            unlink($root . '/First.php');
            unlink($root . '/Second.php');
            rmdir($root);
        }
    }

    public function test_duplicate_declarations_without_edges_emit_deterministic_diagnostics(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-index-ambiguous-diagnostic-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        file_put_contents($root . '/First.php', '<?php namespace Demo; class Service {}');
        file_put_contents($root . '/Second.php', '<?php namespace demo; class service {}');

        try {
            $index = (new CodebaseIndexer(
                new ProjectScanner(new PhpFileAnalyzer()),
                new PhpAstParser(),
            ))->build($root);

            $diagnostics = array_map(fn ($diagnostic) => $diagnostic->toArray(), $index->diagnostics());
            $this->assertSame(['First.php', 'Second.php'], array_column($diagnostics, 'file'));
            $this->assertSame([1, 1], array_column($diagnostics, 'line'));
            foreach ($diagnostics as $diagnostic) {
                $this->assertStringContainsString('Ambiguous class declaration', $diagnostic['message']);
                $this->assertStringContainsString('Demo\\Service', $diagnostic['message']);
                $this->assertStringContainsString('First.php, Second.php', $diagnostic['message']);
            }
        } finally {
            unlink($root . '/First.php');
            unlink($root . '/Second.php');
            rmdir($root);
        }
    }

    public function test_scripts_become_graph_nodes_and_edge_sources(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-index-scripts-' . bin2hex(random_bytes(6));
        mkdir($root . '/app/Http/Controllers', 0777, true);
        mkdir($root . '/routes');
        $files = [
            '/app/Http/Controllers/UserController.php' => '<?php namespace App\Http\Controllers; class UserController { public function index(): void {} }',
            '/routes/web.php' => "<?php\nuse App\\Http\\Controllers\\UserController;\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('/users', [UserController::class, 'index']);",
            '/app/helpers.php' => "<?php\nuse App\\Http\\Controllers\\UserController;\nfunction user_controller(): UserController { return new UserController(); }",
        ];
        foreach ($files as $path => $code) {
            file_put_contents($root . $path, $code);
        }

        try {
            $index = (new CodebaseIndexer(new ProjectScanner(new PhpFileAnalyzer()), new PhpAstParser()))->build($root);
        } finally {
            foreach (array_keys($files) as $path) {
                unlink($root . $path);
            }
            foreach (['/routes', '/app/Http/Controllers', '/app/Http', '/app', ''] as $directory) {
                rmdir($root . $directory);
            }
        }

        $this->assertSame([], $index->diagnostics());
        $this->assertSame('script', $index->findClass('routes/web.php')->kind);
        $this->assertSame('script', $index->graph()->node('routes/web.php')->kind);
        $this->assertSame(1, $index->graph()->node('app/helpers.php')->line);
        $this->assertSame('user_controller', $index->findMethod('app/helpers.php', 'user_controller')['name']);
        $this->assertSame(['app/helpers.php'], array_map(fn ($symbol) => $symbol->fqcn, $index->classesInFile('app/helpers.php')));
        $this->assertSame(
            ['app/helpers.php', 'routes/web.php'],
            array_values(array_unique(array_map(fn ($edge) => $edge->source, $index->findReferencesTo('App\\Http\\Controllers\\UserController')))),
        );
        $this->assertSame([], $index->findReferencesTo('routes/web.php'));
        $this->assertNotEmpty($index->findDependencies('routes/web.php'));
    }

    public function test_a_missing_dependency_aborts_the_index_instead_of_becoming_a_diagnostic(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-index-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        file_put_contents($root . '/A.php', '<?php class A {}');

        $parser = new class implements AstParser {
            public function parse(string $file, ?string $displayPath = null): ParsedFile
            {
                throw MissingDependencyException::forFeature(PhpParserRequirement::FEATURE, PhpParserRequirement::PACKAGE);
            }
        };

        try {
            (new CodebaseIndexer(new ProjectScanner(new PhpFileAnalyzer()), $parser))->build($root);
            $this->fail('Expected the missing dependency to abort the index.');
        } catch (MissingDependencyException $exception) {
            $this->assertSame('nikic/php-parser', $exception->package);
        } finally {
            unlink($root . '/A.php');
            rmdir($root);
        }
    }
}
