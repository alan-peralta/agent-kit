<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring;

use Peralta\AgentKit\Refactoring\Analysis\Ast\AstParser;
use Peralta\AgentKit\Refactoring\Analysis\Ast\PhpAstParser;
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
}
