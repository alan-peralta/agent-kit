# Refactoring AST and Dependency Graph Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add read-only AST-backed PHP structure indexing, typed dependency graphs, caller lookup, impact analysis, and human/JSON Artisan commands while preserving the existing Refactoring Agent audit.

**Architecture:** Keep `PhpFileAnalyzer` as the existing metrics engine and extract reusable file discovery from `ProjectScanner`. Build Agent Kit-owned structural DTOs from PHP Parser nodes, then construct one immutable in-memory `CodebaseIndex` and a typed reverse-traversable `DependencyGraph` per command execution. Caller and impact services consume only the index; Artisan commands contain validation and presentation only.

**Tech Stack:** PHP 8.2+, Laravel/Illuminate 10-12, nikic/php-parser 5.x, PHPUnit 10/11, Orchestra Testbench.

---

## File Map

### Existing files to modify

- `composer.json` and `composer.lock` — make PHP Parser a direct runtime dependency.
- `src/Refactoring/Support/ProjectScanner.php` — expose reusable, deterministic PHP file discovery while preserving `scan()`.
- `src/AgentKitServiceProvider.php` — register analysis services and CLI commands.
- `config/agent-kit.php` — add impact thresholds and conservative Facade configuration.
- `README.md` — list new end-user commands.
- `REFACTORING_AGENT.md` — document architecture, semantics, JSON, confidence, and limitations.

### New production files

- `src/Refactoring/Analysis/Ast/AstParser.php` — parsing boundary.
- `src/Refactoring/Analysis/Ast/PhpAstParser.php` — PHP Parser setup and error conversion.
- `src/Refactoring/Analysis/Ast/StructureCollector.php` — declarations, references, local types, and Laravel pattern extraction.
- `src/Refactoring/Analysis/Ast/NameContext.php` — contextual `self`, `static`, and `parent` normalization.
- `src/Refactoring/Analysis/DTOs/ParsedFile.php` — one parsed file with symbols, references, diagnostics.
- `src/Refactoring/Analysis/DTOs/SymbolDefinition.php` — class-like declaration and member metadata.
- `src/Refactoring/Analysis/DTOs/Reference.php` — normalized source-to-target relationship.
- `src/Refactoring/Analysis/DTOs/ParseDiagnostic.php` — non-fatal parser error.
- `src/Refactoring/Analysis/DTOs/CallerResult.php` — direct and structural caller response.
- `src/Refactoring/Analysis/DTOs/ImpactResult.php` — impact response.
- `src/Refactoring/Analysis/Graph/DependencyType.php` — typed relationship enum.
- `src/Refactoring/Analysis/Graph/Confidence.php` — confidence enum.
- `src/Refactoring/Analysis/Graph/DependencyNode.php` — class-like graph node.
- `src/Refactoring/Analysis/Graph/DependencyEdge.php` — relationship with source context.
- `src/Refactoring/Analysis/Graph/DependencyGraph.php` — adjacency maps and cycle-safe traversal.
- `src/Refactoring/Analysis/Index/CodebaseIndex.php` — immutable lookup API.
- `src/Refactoring/Analysis/Index/CodebaseIndexer.php` — single-scan, single-parse index builder.
- `src/Refactoring/Analysis/CallerAnalyzer.php` — caller query service.
- `src/Refactoring/Analysis/ImpactAnalyzer.php` — deterministic impact query service.
- `src/Refactoring/Commands/RefactorCallersCommand.php` — caller CLI adapter.
- `src/Refactoring/Commands/RefactorDependenciesCommand.php` — dependency CLI adapter.
- `src/Refactoring/Commands/RefactorImpactCommand.php` — impact CLI adapter.

### New tests and fixtures

- `tests/Fixtures/Refactoring/Ast/*.php` — payment-flow fixture project.
- `tests/Unit/Refactoring/Ast/PhpAstParserTest.php`
- `tests/Unit/Refactoring/Ast/LaravelReferenceTest.php`
- `tests/Unit/Refactoring/DependencyGraphTest.php`
- `tests/Unit/Refactoring/CodebaseIndexerTest.php`
- `tests/Unit/Refactoring/CallerAnalyzerTest.php`
- `tests/Unit/Refactoring/ImpactAnalyzerTest.php`
- `tests/Feature/Refactoring/RefactoringCommandsTest.php`

## Task 1: Runtime dependency and reusable file discovery

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Modify: `src/Refactoring/Support/ProjectScanner.php`
- Modify: `tests/Unit/Refactoring/ProjectScannerTest.php`

- [ ] **Step 1: Write the failing file-discovery tests**

Add these methods to `ProjectScannerTest`:

```php
public function test_it_discovers_normalized_php_paths_without_analyzing_them(): void
{
    $root = $this->fixtureRoot();
    mkdir($root . '/app', 0777, true);
    mkdir($root . '/vendor/pkg', 0777, true);
    file_put_contents($root . '/app/B.php', '<?php class B {}');
    file_put_contents($root . '/app/A.php', '<?php class A {}');
    file_put_contents($root . '/app/readme.txt', 'ignored');
    file_put_contents($root . '/vendor/pkg/V.php', '<?php class V {}');

    $scanner = new ProjectScanner(new PhpFileAnalyzer(), ['vendor']);

    $this->assertSame([
        $root . '/app/A.php',
        $root . '/app/B.php',
    ], $scanner->phpFiles($root));

    $this->removeFixtureRoot($root);
}

public function test_scan_remains_backward_compatible_after_discovery_is_extracted(): void
{
    $root = $this->fixtureRoot();
    mkdir($root . '/app', 0777, true);
    file_put_contents($root . '/app/A.php', '<?php class A {}');

    $files = (new ProjectScanner(new PhpFileAnalyzer(), []))->scan($root);

    $this->assertCount(1, $files);
    $this->assertSame('app/A.php', str_replace('\\', '/', $files[0]->path));
    $this->removeFixtureRoot($root);
}

private function fixtureRoot(): string
{
    return sys_get_temp_dir() . '/agent-kit-scan-' . bin2hex(random_bytes(6));
}

private function removeFixtureRoot(string $root): void
{
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
```

- [ ] **Step 2: Run the discovery test and verify RED**

Run:

```bash
vendor/bin/phpunit tests/Unit/Refactoring/ProjectScannerTest.php
```

Expected: failure because `ProjectScanner::phpFiles()` does not exist.

- [ ] **Step 3: Extract deterministic discovery without changing `scan()` output**

Replace `ProjectScanner::scan()`'s iterator setup with a public discovery method:

```php
public function phpFiles(string $root): array
{
    $root = rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR);
    if (!is_dir($root)) {
        throw new \InvalidArgumentException("Diretório não encontrado: {$root}");
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        if (!$this->isExcluded($path, $root)) {
            $files[] = $path;
        }
    }

    sort($files, SORT_STRING);

    return $files;
}

public function scan(string $root): array
{
    $normalizedRoot = rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR);
    $files = array_map(function (string $path) use ($normalizedRoot) {
        $relative = ltrim(str_replace($normalizedRoot, '', $path), DIRECTORY_SEPARATOR);
        return $this->analyzer->analyze($path, $relative);
    }, $this->phpFiles($root));

    usort($files, fn ($a, $b) => count($b->smells) <=> count($a->smells) ?: $b->lines <=> $a->lines);

    return $files;
}
```

- [ ] **Step 4: Make PHP Parser a direct dependency**

Run:

```bash
composer require nikic/php-parser:^5.8 --no-interaction
```

Expected: `composer.json` lists `nikic/php-parser` under `require`, the lock file remains on a compatible 5.x release, and Composer completes successfully.

- [ ] **Step 5: Verify GREEN and compatibility**

Run:

```bash
vendor/bin/phpunit tests/Unit/Refactoring/ProjectScannerTest.php tests/Unit/Refactoring/PhpFileAnalyzerTest.php
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock src/Refactoring/Support/ProjectScanner.php tests/Unit/Refactoring/ProjectScannerTest.php
git commit -m "refactor: expose reusable PHP file discovery"
```

## Task 2: AST value model and declaration parsing

**Files:**
- Create: `src/Refactoring/Analysis/Ast/AstParser.php`
- Create: `src/Refactoring/Analysis/Ast/PhpAstParser.php`
- Create: `src/Refactoring/Analysis/Ast/StructureCollector.php`
- Create: `src/Refactoring/Analysis/Ast/NameContext.php`
- Create: `src/Refactoring/Analysis/DTOs/ParsedFile.php`
- Create: `src/Refactoring/Analysis/DTOs/SymbolDefinition.php`
- Create: `src/Refactoring/Analysis/DTOs/Reference.php`
- Create: `src/Refactoring/Analysis/DTOs/ParseDiagnostic.php`
- Create: `src/Refactoring/Analysis/Graph/DependencyType.php`
- Create: `src/Refactoring/Analysis/Graph/Confidence.php`
- Create: `tests/Fixtures/Refactoring/Ast/PaymentGateway.php`
- Create: `tests/Fixtures/Refactoring/Ast/LogsPayments.php`
- Create: `tests/Fixtures/Refactoring/Ast/PaymentService.php`
- Create: `tests/Unit/Refactoring/Ast/PhpAstParserTest.php`

- [ ] **Step 1: Add declaration fixtures**

Create the three fixture files:

```php
<?php
// tests/Fixtures/Refactoring/Ast/PaymentGateway.php
namespace Fixtures\Payments;

interface PaymentGateway
{
    public function charge(int $amount): Receipt;
}
```

```php
<?php
// tests/Fixtures/Refactoring/Ast/LogsPayments.php
namespace Fixtures\Payments;

trait LogsPayments
{
    public function logPayment(): void {}
}
```

```php
<?php
// tests/Fixtures/Refactoring/Ast/PaymentService.php
namespace Fixtures\Payments;

use Attribute;
use Fixtures\Contracts\Auditor as PaymentAuditor;

#[Attribute]
final class PaymentService implements PaymentGateway
{
    use LogsPayments;

    public const CURRENCY = 'BRL';

    public function __construct(
        private PaymentAuditor $auditor,
        protected ?Receipt $lastReceipt = null,
    ) {}

    public function charge(int $amount, PaymentGateway&PaymentAuditor $gateway): Receipt|Failure
    {
        return new Receipt();
    }
}
```

- [ ] **Step 2: Write the failing parser contract test**

Create `PhpAstParserTest` with:

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Ast;

use Peralta\AgentKit\Refactoring\Analysis\Ast\PhpAstParser;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyType;
use PHPUnit\Framework\TestCase;

final class PhpAstParserTest extends TestCase
{
    public function test_it_extracts_normalized_declarations_members_and_types(): void
    {
        $file = dirname(__DIR__, 3) . '/Fixtures/Refactoring/Ast/PaymentService.php';
        $parsed = (new PhpAstParser())->parse($file, 'PaymentService.php');

        $this->assertSame([], $parsed->diagnostics);
        $symbol = $parsed->symbols[0];
        $this->assertSame('Fixtures\\Payments\\PaymentService', $symbol->fqcn);
        $this->assertSame('class', $symbol->kind);
        $this->assertSame(['__construct', 'charge'], array_values(array_unique(array_column($symbol->methods, 'name'))));
        $this->assertContains('auditor', array_column($symbol->properties, 'name'));
        $this->assertContains('CURRENCY', array_column($symbol->constants, 'name'));
        $this->assertContains('Attribute', $symbol->attributes);

        $edges = $parsed->references;
        $this->assertTrue($this->has($edges, DependencyType::IMPLEMENTS, 'Fixtures\\Payments\\PaymentGateway'));
        $this->assertTrue($this->has($edges, DependencyType::TRAIT, 'Fixtures\\Payments\\LogsPayments'));
        $this->assertTrue($this->has($edges, DependencyType::CONSTRUCTOR_INJECTION, 'Fixtures\\Contracts\\Auditor'));
        $this->assertTrue($this->has($edges, DependencyType::RETURN_TYPE, 'Fixtures\\Payments\\Receipt'));
        $this->assertTrue($this->has($edges, DependencyType::INSTANTIATION, 'Fixtures\\Payments\\Receipt'));
        $this->assertGreaterThan(0, $edges[0]->line);
    }

    public function test_it_returns_a_diagnostic_for_invalid_php(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'invalid-php-');
        file_put_contents($file, '<?php class Broken {');
        $parsed = (new PhpAstParser())->parse($file, 'Broken.php');
        unlink($file);

        $this->assertSame([], $parsed->symbols);
        $this->assertCount(1, $parsed->diagnostics);
        $this->assertSame('Broken.php', $parsed->diagnostics[0]->file);
        $this->assertGreaterThan(0, $parsed->diagnostics[0]->line);
    }

    private function has(array $references, DependencyType $type, string $target): bool
    {
        foreach ($references as $reference) {
            if ($reference->type === $type && $reference->target === $target) {
                return true;
            }
        }
        return false;
    }
}
```

- [ ] **Step 3: Run the parser tests and verify RED**

Run:

```bash
vendor/bin/phpunit tests/Unit/Refactoring/Ast/PhpAstParserTest.php
```

Expected: failure because the parser and DTO classes do not exist.

- [ ] **Step 4: Add enums and immutable DTOs**

Implement backed enums with these exact values:

```php
enum DependencyType: string
{
    case CONSTRUCTOR_INJECTION = 'constructor_injection';
    case METHOD_PARAMETER = 'method_parameter';
    case RETURN_TYPE = 'return_type';
    case PROPERTY_TYPE = 'property_type';
    case EXTENDS = 'extends';
    case IMPLEMENTS = 'implements';
    case TRAIT = 'trait';
    case INSTANTIATION = 'instantiation';
    case STATIC_CALL = 'static_call';
    case METHOD_CALL = 'method_call';
    case CLASS_CONSTANT = 'class_constant';
    case ATTRIBUTE = 'attribute';
    case FACADE = 'facade';
    case EVENT = 'event';
}

enum Confidence: string
{
    case EXACT = 'exact';
    case INFERRED = 'inferred';
    case UNKNOWN = 'unknown';
}
```

Implement the DTO constructors and `toArray()` methods with these shapes:

```php
final readonly class Reference
{
    public function __construct(
        public string $source,
        public ?string $sourceMethod,
        public ?string $target,
        public ?string $targetMethod,
        public DependencyType $type,
        public Confidence $confidence,
        public string $file,
        public int $line,
        public array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'source_method' => $this->sourceMethod,
            'target' => $this->target,
            'target_method' => $this->targetMethod,
            'type' => $this->type->value,
            'confidence' => $this->confidence->value,
            'file' => $this->file,
            'line' => $this->line,
            'metadata' => $this->metadata,
        ];
    }
}

final readonly class SymbolDefinition
{
    public function __construct(
        public string $fqcn,
        public string $kind,
        public string $file,
        public int $line,
        public array $methods = [],
        public array $properties = [],
        public array $constants = [],
        public array $attributes = [],
    ) {}
}

final readonly class ParseDiagnostic
{
    public function __construct(
        public string $file,
        public int $line,
        public string $message,
    ) {}

    public function toArray(): array
    {
        return ['file' => $this->file, 'line' => $this->line, 'message' => $this->message];
    }
}

final readonly class ParsedFile
{
    public function __construct(
        public string $file,
        public array $symbols = [],
        public array $references = [],
        public array $diagnostics = [],
    ) {}
}
```

- [ ] **Step 5: Implement the parser boundary and declaration collector**

Define the boundary:

```php
interface AstParser
{
    public function parse(string $file, ?string $displayPath = null): ParsedFile;
}
```

In `PhpAstParser`, use `ParserFactory::createForNewestSupportedVersion()`, then
traverse with `NameResolver` followed by `StructureCollector`. Catch
`PhpParser\Error` and return a `ParsedFile` containing one `ParseDiagnostic`
whose line is `max(1, $error->getStartLine())`. Throw `RuntimeException` only
when the file cannot be read.

`StructureCollector` must maintain the current class FQCN and method name using
`enterNode()`/`leaveNode()`. Read declaration FQCNs from `namespacedName`, and
normalize type nodes recursively:

```php
private function classTypes(Node $type): array
{
    if ($type instanceof Node\NullableType) {
        return $this->classTypes($type->type);
    }
    if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
        return array_values(array_unique(array_merge(...array_map(
            fn (Node $member) => $this->classTypes($member),
            $type->types,
        ))));
    }
    if ($type instanceof Node\Name) {
        $name = $type->toString();
        return in_array(strtolower($name), ['self', 'static', 'parent'], true)
            ? [$this->nameContext->resolve($name)]
            : [$name];
    }
    return [];
}
```

Emit one `Reference` for each class type and declaration relationship. Constructor
parameters use `CONSTRUCTOR_INJECTION`; all other parameters use
`METHOD_PARAMETER`. Promoted properties additionally emit `PROPERTY_TYPE`.
Attribute names use `ATTRIBUTE`, object creation uses `INSTANTIATION`, and every
reference uses the node's start line and the current source context.

- [ ] **Step 6: Run parser tests and verify GREEN**

Run:

```bash
vendor/bin/phpunit tests/Unit/Refactoring/Ast/PhpAstParserTest.php
```

Expected: all parser tests pass with no warnings.

- [ ] **Step 7: Commit**

```bash
git add src/Refactoring/Analysis tests/Fixtures/Refactoring/Ast tests/Unit/Refactoring/Ast/PhpAstParserTest.php
git commit -m "feat: parse PHP structure with AST"
```

## Task 3: Calls, local type inference, and Laravel awareness

**Files:**
- Modify: `src/Refactoring/Analysis/Ast/StructureCollector.php`
- Modify: `src/Refactoring/Analysis/Ast/NameContext.php`
- Create: `tests/Fixtures/Refactoring/Ast/CheckoutService.php`
- Create: `tests/Fixtures/Refactoring/Ast/PaymentApproved.php`
- Create: `tests/Fixtures/Refactoring/Ast/ProcessPayment.php`
- Create: `tests/Unit/Refactoring/Ast/LaravelReferenceTest.php`

- [ ] **Step 1: Add the call and Laravel fixture**

Create `CheckoutService.php`:

```php
<?php
namespace Fixtures\Checkout;

use Fixtures\Payments\PaymentApproved;
use Fixtures\Payments\PaymentService as Payments;
use Fixtures\Payments\ProcessPayment;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

final class CheckoutService
{
    public function __construct(private Payments $payments) {}

    public function checkout(Payments $service): void
    {
        $this->payments->charge(100);
        $service->charge(200);
        $local = new Payments();
        $local->charge(300);
        Payments::status();
        Payments::CURRENCY;
        app(Payments::class)->charge(400);
        resolve(Payments::class)->charge(500);
        app()->make(Payments::class)->charge(600);
        event(new PaymentApproved());
        Event::dispatch(new PaymentApproved());
        dispatch(new ProcessPayment());
        ProcessPayment::dispatch();
        Bus::dispatch(new ProcessPayment());
        Log::info('paid');
        $unknown->charge(700);
    }
}
```

Create minimal `PaymentApproved` and `ProcessPayment` classes in the
`Fixtures\Payments` namespace.

- [ ] **Step 2: Write failing reference assertions**

Create `LaravelReferenceTest` and assert:

```php
$parsed = (new PhpAstParser())->parse($fixture, 'CheckoutService.php');
$calls = array_values(array_filter(
    $parsed->references,
    fn ($reference) => $reference->type === DependencyType::METHOD_CALL,
));

$this->assertCount(6, array_filter(
    $calls,
    fn ($call) => $call->target === 'Fixtures\\Payments\\PaymentService'
        && $call->targetMethod === 'charge'
        && $call->confidence === Confidence::INFERRED,
));
$this->assertTrue($this->hasReference($parsed, DependencyType::STATIC_CALL, 'Fixtures\\Payments\\PaymentService', 'status'));
$this->assertTrue($this->hasReference($parsed, DependencyType::CLASS_CONSTANT, 'Fixtures\\Payments\\PaymentService', null));
$this->assertTrue($this->hasReference($parsed, DependencyType::EVENT, 'Fixtures\\Payments\\PaymentApproved', null));
$this->assertTrue($this->hasReference($parsed, DependencyType::EVENT, 'Fixtures\\Payments\\ProcessPayment', null));
$this->assertTrue($this->hasReference($parsed, DependencyType::FACADE, 'Illuminate\\Support\\Facades\\Log', 'info'));
$this->assertContains(null, array_map(
    fn ($call) => $call->target,
    array_filter($calls, fn ($call) => $call->confidence === Confidence::UNKNOWN),
));
```

Add a second test proving dynamic `event($name)`, `app($name)`, and
`$unknown->charge()` never produce a non-null invented target.

- [ ] **Step 3: Run the reference tests and verify RED**

Run:

```bash
vendor/bin/phpunit tests/Unit/Refactoring/Ast/LaravelReferenceTest.php
```

Expected: failures because calls and Laravel constructs are not collected.

- [ ] **Step 4: Implement conservative receiver inference**

Within each method, reset a local variable-type map. Populate it from typed
parameters and direct assignments whose right side is `new` or one of the
supported container helpers. Track promoted and declared property types by
property name.

Resolve method-call receivers in this order:

```php
private function receiverType(Node\Expr $receiver): array
{
    if ($receiver instanceof Node\Expr\PropertyFetch
        && $receiver->var instanceof Node\Expr\Variable
        && $receiver->var->name === 'this'
        && $receiver->name instanceof Node\Identifier) {
        return [$this->propertyTypes[$receiver->name->toString()] ?? null, Confidence::INFERRED];
    }

    if ($receiver instanceof Node\Expr\Variable && is_string($receiver->name)) {
        return [$this->localTypes[$receiver->name] ?? null, Confidence::INFERRED];
    }

    $containerType = $this->containerClassArgument($receiver);
    return $containerType !== null
        ? [$containerType, Confidence::INFERRED]
        : [null, Confidence::UNKNOWN];
}
```

Emit unknown method-call references only as diagnostics in `ParsedFile`'s
reference list with `target=null`; later graph construction must skip them.

- [ ] **Step 5: Implement static calls and Laravel patterns**

For `StaticCall`, emit `STATIC_CALL` unless the resolved class starts with a
configured Facade namespace, in which case emit `FACADE`. Recognize these event
and job forms by node shape and explicit `ClassName::class` or `new ClassName`:

```text
event(new EventClass)
Event::dispatch(new EventClass)
dispatch(new JobClass)
JobClass::dispatch()
Bus::dispatch(new JobClass)
```

Use `EVENT` for the dispatched event/job target and attach
`['dispatch_kind' => 'event'|'job']` metadata. Avoid double-counting the Facade
call itself when it has been converted into an event/job edge.

- [ ] **Step 6: Verify references and parser regressions**

Run:

```bash
vendor/bin/phpunit tests/Unit/Refactoring/Ast
```

Expected: all AST tests pass.

- [ ] **Step 7: Commit**

```bash
git add src/Refactoring/Analysis/Ast tests/Fixtures/Refactoring/Ast tests/Unit/Refactoring/Ast
git commit -m "feat: infer PHP and Laravel references"
```

## Task 4: Dependency graph and codebase index

**Files:**
- Create: `src/Refactoring/Analysis/Graph/DependencyNode.php`
- Create: `src/Refactoring/Analysis/Graph/DependencyEdge.php`
- Create: `src/Refactoring/Analysis/Graph/DependencyGraph.php`
- Create: `src/Refactoring/Analysis/Index/CodebaseIndex.php`
- Create: `src/Refactoring/Analysis/Index/CodebaseIndexer.php`
- Create: `tests/Unit/Refactoring/DependencyGraphTest.php`
- Create: `tests/Unit/Refactoring/CodebaseIndexerTest.php`

- [ ] **Step 1: Write failing graph tests**

Cover typed parallel edges and a cycle:

```php
$graph = new DependencyGraph();
$graph->addNode(new DependencyNode('A', 'class', 'A.php', 2));
$graph->addNode(new DependencyNode('B', 'class', 'B.php', 2));
$graph->addNode(new DependencyNode('C', 'class', 'C.php', 2));
$graph->addEdge(new DependencyEdge('A', null, 'B', null, DependencyType::PROPERTY_TYPE, Confidence::EXACT, 'A.php', 5));
$graph->addEdge(new DependencyEdge('A', 'run', 'B', 'go', DependencyType::METHOD_CALL, Confidence::INFERRED, 'A.php', 9));
$graph->addEdge(new DependencyEdge('B', null, 'C', null, DependencyType::EXTENDS, Confidence::EXACT, 'B.php', 2));
$graph->addEdge(new DependencyEdge('C', null, 'A', null, DependencyType::METHOD_PARAMETER, Confidence::EXACT, 'C.php', 7));

$this->assertCount(2, $graph->outgoing('A'));
$this->assertSame(['A', 'A'], array_column($graph->incoming('B'), 'source'));
$this->assertSame(['B', 'A'], array_column($graph->transitiveDependents('C'), 'fqcn'));
```

The deliberate duplicate incoming source demonstrates that distinct edge
reasons are preserved. Also assert that `C` is absent from its own transitive
dependents.

- [ ] **Step 2: Write a failing single-parse indexer test**

Use an `AstParser` spy:

```php
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

$index = (new CodebaseIndexer($scanner, $parser))->build($root);

$this->assertCount(count($scanner->phpFiles($root)), $parser->calls);
$this->assertCount(count(array_unique($parser->calls)), $parser->calls);
$this->assertNotNull($index->findClass('Fixtures\\A'));
```

- [ ] **Step 3: Run graph/index tests and verify RED**

Run:

```bash
vendor/bin/phpunit tests/Unit/Refactoring/DependencyGraphTest.php tests/Unit/Refactoring/CodebaseIndexerTest.php
```

Expected: failures because graph and index classes do not exist.

- [ ] **Step 4: Implement graph value objects and adjacency maps**

Use immutable nodes and edges:

```php
final readonly class DependencyNode
{
    public function __construct(
        public string $fqcn,
        public string $kind,
        public string $file,
        public int $line,
    ) {}
}

final readonly class DependencyEdge
{
    public function __construct(
        public string $source,
        public ?string $sourceMethod,
        public string $target,
        public ?string $targetMethod,
        public DependencyType $type,
        public Confidence $confidence,
        public string $file,
        public int $line,
        public array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'source_method' => $this->sourceMethod,
            'target' => $this->target,
            'target_method' => $this->targetMethod,
            'type' => $this->type->value,
            'confidence' => $this->confidence->value,
            'file' => $this->file,
            'line' => $this->line,
            'metadata' => $this->metadata,
        ];
    }
}
```

`DependencyGraph` stores `$nodes`, `$outgoing`, and `$incoming` keyed by FQCN.
Sort returned edges by source, source method, target, target method, file, line,
and type value. Implement reverse breadth-first traversal with queue entries
containing FQCN and path; mark the target visited before traversal and never
return it.

- [ ] **Step 5: Implement index construction and lookups**

For every parsed file, add each symbol as a graph node and add only references
with a non-null target as graph edges. Expose:

```php
public function findClass(string $fqcn): ?SymbolDefinition;
public function findReferencesTo(string $fqcn): array;
public function findDependencies(string $fqcn): array;
public function findMethodCalls(string $fqcn, ?string $method = null): array;
public function graph(): DependencyGraph;
public function diagnostics(): array;
```

Strip a leading slash from lookup FQCNs. `findMethodCalls()` filters incoming
edges to `METHOD_CALL` and `STATIC_CALL`, applying exact method equality when
the method argument is non-null.

- [ ] **Step 6: Verify graph/index GREEN**

Run:

```bash
vendor/bin/phpunit tests/Unit/Refactoring/DependencyGraphTest.php tests/Unit/Refactoring/CodebaseIndexerTest.php
```

Expected: all tests pass and cyclic traversal terminates.

- [ ] **Step 7: Commit**

```bash
git add src/Refactoring/Analysis/Graph src/Refactoring/Analysis/Index tests/Unit/Refactoring/DependencyGraphTest.php tests/Unit/Refactoring/CodebaseIndexerTest.php
git commit -m "feat: build typed dependency graph index"
```

## Task 5: Caller analysis

**Files:**
- Create: `src/Refactoring/Analysis/DTOs/CallerResult.php`
- Create: `src/Refactoring/Analysis/CallerAnalyzer.php`
- Create: `tests/Unit/Refactoring/CallerAnalyzerTest.php`

- [ ] **Step 1: Write failing caller tests**

Build the fixture index and assert the public behavior:

```php
$result = (new CallerAnalyzer())->findCallers(
    $index,
    'Fixtures\\Payments\\PaymentService',
    'charge',
);

$this->assertSame('Fixtures\\Payments\\PaymentService', $result->target);
$this->assertSame('charge', $result->method);
$this->assertNotEmpty($result->directCallers);
$this->assertContains('Fixtures\\Checkout\\CheckoutService', array_column($result->directCallers, 'source'));
$this->assertContains(DependencyType::CONSTRUCTOR_INJECTION->value, array_column($result->structuralDependencies, 'type'));
$this->assertNotContains('status', array_column($result->directCallers, 'target_method'));
$this->assertSame($result->toArray(), json_decode(json_encode($result->toArray()), true));
```

Add these explicit cases for normalization, no filter, and missing targets:

```php
$all = (new CallerAnalyzer())->findCallers($index, '\\Fixtures\\Payments\\PaymentService');
$this->assertNull($all->method);
$this->assertContains('status', array_column($all->directCallers, 'target_method'));

$this->expectException(\InvalidArgumentException::class);
$this->expectExceptionMessage('Missing\\Service');
(new CallerAnalyzer())->findCallers($index, 'Missing\\Service');
```

- [ ] **Step 2: Run caller tests and verify RED**

Run:

```bash
vendor/bin/phpunit tests/Unit/Refactoring/CallerAnalyzerTest.php
```

Expected: failure because `CallerAnalyzer` and `CallerResult` do not exist.

- [ ] **Step 3: Implement caller result and service**

Use this result shape:

```php
final readonly class CallerResult
{
    public function __construct(
        public string $target,
        public ?string $method,
        public array $directCallers,
        public array $structuralDependencies,
        public array $diagnostics,
    ) {}

    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'method' => $this->method,
            'direct_callers' => $this->directCallers,
            'structural_dependencies' => $this->structuralDependencies,
            'diagnostics' => array_map(fn ($diagnostic) => $diagnostic->toArray(), $this->diagnostics),
        ];
    }
}
```

`CallerAnalyzer` validates the class through `findClass()`, obtains direct calls
through `findMethodCalls()`, and filters remaining incoming references into the
structural set. Convert edges using their `toArray()` contract and return stable
ordering inherited from the graph.

- [ ] **Step 4: Verify caller GREEN**

Run:

```bash
vendor/bin/phpunit tests/Unit/Refactoring/CallerAnalyzerTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/Refactoring/Analysis/CallerAnalyzer.php src/Refactoring/Analysis/DTOs/CallerResult.php tests/Unit/Refactoring/CallerAnalyzerTest.php
git commit -m "feat: find direct and structural callers"
```

## Task 6: Cycle-safe impact analysis and risk

**Files:**
- Create: `src/Refactoring/Analysis/DTOs/ImpactResult.php`
- Create: `src/Refactoring/Analysis/ImpactAnalyzer.php`
- Modify: `config/agent-kit.php`
- Create: `tests/Unit/Refactoring/ImpactAnalyzerTest.php`

- [ ] **Step 1: Write failing impact tests**

Construct explicit A -> B -> C -> A graphs and assert:

```php
$result = (new ImpactAnalyzer([
    'low_max' => 0,
    'medium_max' => 1,
    'high_max' => 2,
]))->analyze($index, 'C');

$this->assertSame('C', $result->target);
$this->assertSame(1, $result->directCallers);
$this->assertSame(0, $result->structuralDependencies);
$this->assertSame(1, $result->transitiveDependents);
$this->assertSame(2, $result->affectedFiles);
$this->assertSame('HIGH', $result->risk);
$this->assertNotContains('C', array_column($result->transitive, 'fqcn'));
```

Add table-driven cases for 0, 1, 2, and 3 unique dependents producing `LOW`,
`MEDIUM`, `HIGH`, and `CRITICAL` under those injected boundaries. Add a case
where one source has both call and property edges: it appears in both displayed
category counts but only once in the risk union.

- [ ] **Step 2: Run impact tests and verify RED**

Run:

```bash
vendor/bin/phpunit tests/Unit/Refactoring/ImpactAnalyzerTest.php
```

Expected: failure because impact classes do not exist.

- [ ] **Step 3: Implement deterministic impact result**

Use:

```php
final readonly class ImpactResult
{
    public function __construct(
        public string $target,
        public int $directCallers,
        public int $structuralDependencies,
        public int $transitiveDependents,
        public int $affectedFiles,
        public string $risk,
        public array $direct,
        public array $structural,
        public array $transitive,
        public array $diagnostics,
    ) {}

    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'direct_callers' => $this->directCallers,
            'structural_dependencies' => $this->structuralDependencies,
            'transitive_dependents' => $this->transitiveDependents,
            'affected_files' => $this->affectedFiles,
            'risk' => $this->risk,
            'direct' => $this->direct,
            'structural' => $this->structural,
            'transitive' => $this->transitive,
            'diagnostics' => array_map(fn ($diagnostic) => $diagnostic->toArray(), $this->diagnostics),
        ];
    }
}
```

Compute direct-call and structural maps keyed by source FQCN, union them for
direct dependents, remove that union and the target from reverse traversal to
obtain transitive-only dependents, and union source file paths for affected
files. Classify the size of the unique FQCN union with inclusive maximums.

- [ ] **Step 4: Add configuration defaults**

Under `agent-kit.refactoring`, add:

```php
'impact_thresholds' => [
    'low_max' => 2,
    'medium_max' => 7,
    'high_max' => 15,
],
'facades' => [
    'Illuminate\\Support\\Facades\\',
],
```

- [ ] **Step 5: Verify impact GREEN**

Run:

```bash
vendor/bin/phpunit tests/Unit/Refactoring/ImpactAnalyzerTest.php tests/Unit/Refactoring/DependencyGraphTest.php
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/Refactoring/Analysis/DTOs/ImpactResult.php src/Refactoring/Analysis/ImpactAnalyzer.php config/agent-kit.php tests/Unit/Refactoring/ImpactAnalyzerTest.php
git commit -m "feat: analyze transitive refactoring impact"
```

## Task 7: Thin human and JSON CLI adapters

**Files:**
- Create: `src/Refactoring/Commands/RefactorCallersCommand.php`
- Create: `src/Refactoring/Commands/RefactorDependenciesCommand.php`
- Create: `src/Refactoring/Commands/RefactorImpactCommand.php`
- Modify: `src/AgentKitServiceProvider.php`
- Create: `tests/Feature/Refactoring/RefactoringCommandsTest.php`

- [ ] **Step 1: Write failing command-registration and JSON tests**

Create a Testbench feature test that invokes each command against the fixture
root:

```php
public function test_refactor_callers_emits_machine_readable_json(): void
{
    $root = dirname(__DIR__, 2) . '/Fixtures/Refactoring/Ast';

    $this->artisan('agent-kit:refactor-callers', [
        'class' => 'Fixtures\\Payments\\PaymentService',
        '--method' => 'charge',
        '--path' => $root,
        '--json' => true,
    ])->assertSuccessful();

    $output = $this->app['console']->output();
    $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('Fixtures\\Payments\\PaymentService', $decoded['target']);
    $this->assertSame('charge', $decoded['method']);
    $this->assertArrayHasKey('direct_callers', $decoded);
}
```

Add corresponding impact and dependencies JSON tests, human-output assertions
for section headings, invalid-root failure, missing-target failure, and one
temporary malformed PHP file that yields successful JSON with a non-empty
`diagnostics` array.

- [ ] **Step 2: Run command tests and verify RED**

Run:

```bash
vendor/bin/phpunit tests/Feature/Refactoring/RefactoringCommandsTest.php
```

Expected: failures because the commands are not registered.

- [ ] **Step 3: Implement command signatures and orchestration**

Use these signatures:

```php
protected $signature = 'agent-kit:refactor-callers
    {class : Fully qualified target class}
    {--method= : Optional target method}
    {--path= : Project root; defaults to the Laravel base path}
    {--json : Emit JSON only}';

protected $signature = 'agent-kit:refactor-dependencies
    {class : Fully qualified target class}
    {--path= : Project root; defaults to the Laravel base path}
    {--json : Emit JSON only}';

protected $signature = 'agent-kit:refactor-impact
    {class : Fully qualified target class}
    {--path= : Project root; defaults to the Laravel base path}
    {--json : Emit JSON only}';
```

Each `handle()` must:

1. normalize and validate the root;
2. invoke `CodebaseIndexer::build()` once;
3. invoke one query service/index method;
4. catch `InvalidArgumentException`, render its message, and return failure;
5. emit `json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)` when requested;
6. otherwise render target, counts, typed rows, file/line, confidence, paths,
   and a diagnostic warning.

Do not call the filesystem scanner or graph traversal directly from presentation
helpers.

- [ ] **Step 4: Register services and commands**

Bind `AstParser` to `PhpAstParser`, construct `CodebaseIndexer` from the existing
`ProjectScanner`, inject configured Facade prefixes into the parser/collector,
and inject impact thresholds into `ImpactAnalyzer`. Add the three command
classes to the existing `$this->commands([...])` array.

- [ ] **Step 5: Verify command GREEN and existing CLI compatibility**

Run:

```bash
vendor/bin/phpunit tests/Feature/Refactoring/RefactoringCommandsTest.php tests/Unit/Refactoring
```

Expected: all tests pass; existing audit/analyze tests remain green.

- [ ] **Step 6: Commit**

```bash
git add src/Refactoring/Commands src/AgentKitServiceProvider.php tests/Feature/Refactoring/RefactoringCommandsTest.php
git commit -m "feat: expose refactoring graph CLI commands"
```

## Task 8: Documentation and full verification

**Files:**
- Modify: `README.md`
- Modify: `REFACTORING_AGENT.md`

- [ ] **Step 1: Update the README command overview**

Add executable examples for callers, dependencies, impact, method filtering,
project roots, and `--json`. State that all analysis commands are read-only and
that `/refactor:*` agent commands are not part of this iteration.

- [ ] **Step 2: Expand the Refactoring Agent guide**

Document:

- AST/index/graph data flow;
- direct versus structural callers;
- edge types and confidence meanings;
- unique-dependent risk rules and default thresholds;
- Laravel patterns that are recognized;
- syntax diagnostics and continued indexing;
- exclusions and one-parse-per-index behavior;
- JSON field names from `CallerResult` and `ImpactResult`;
- static-analysis limitations;
- persistent cache, MCP adapters, and `/refactor:*` commands as future work.

- [ ] **Step 3: Run documentation and dependency checks**

Run:

```bash
composer validate --strict
git diff --check
```

Expected: Composer validation succeeds and Git reports no whitespace errors.

- [ ] **Step 4: Run focused refactoring tests**

Run:

```bash
vendor/bin/phpunit tests/Unit/Refactoring tests/Feature/Refactoring
```

Expected: all focused tests pass without warnings or errors.

- [ ] **Step 5: Run the complete test suite**

Run:

```bash
vendor/bin/phpunit
```

Expected: the complete Agent Kit test suite passes without failures or errors.

- [ ] **Step 6: Inspect the final diff and prove read-only behavior**

Run:

```bash
git status --short
git diff --stat
git diff -- src/Refactoring config/agent-kit.php composer.json README.md REFACTORING_AGENT.md
```

Confirm that query commands contain no `file_put_contents`, `unlink`, `rename`,
or source-writing operation, and that unrelated pre-existing working-tree
changes were not overwritten.

- [ ] **Step 7: Commit documentation**

```bash
git add README.md REFACTORING_AGENT.md
git commit -m "docs: document AST refactoring analysis"
```

## Deferred Scope

The following agent-facing commands were discussed and intentionally deferred
for a separate design after this implementation:

```text
/refactor:audit
/refactor:analyze <path>
/refactor:plan <path>
/refactor:architecture
/refactor:smells
```

Their future implementation should adapt the application services defined here
rather than duplicate parsing, indexing, graph, caller, or impact logic.
