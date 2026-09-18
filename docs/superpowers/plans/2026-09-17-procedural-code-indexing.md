# Procedural Code Indexing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make procedural PHP (routes, config, bootstrap, helpers, anonymous-class migrations) visible to the refactoring index, give nested named functions their own scope, and stop one failing file from aborting the index.

**Architecture:** `StructureCollector` attributes every reference to a *declaring routine* (`source` = file path at script scope or the FQCN inside a named class) and lazily emits one `script` symbol per file with procedural code; `CodebaseIndexer` treats that symbol like any other node and wraps `parse()` in a `\Throwable` guard; `DefaultRefactoringCapabilities` prefers class-like symbols for `File.php::method` targets. `ClassName::class` becomes a `class_constant` edge so routes/config edges exist at all.

**Tech Stack:** PHP 8.2+, nikic/php-parser 5 (`NodeVisitorAbstract`), PHPUnit 11 (`vendor/bin/phpunit --no-coverage`), Laravel package `peralta/agent-kit`.

**Spec:** `docs/superpowers/specs/2026-09-17-procedural-code-indexing-design.md`

## Global Constraints

- Branch `codex/index-procedural-code`, stacked on `codex/fix-structure-collector-top-level-scope` (PR #5). Never touch PR #4/#5 branches.
- No change to: success/error envelopes, `DependencyType` cases, `Confidence` cases, CLI/JSON schemas, `describeCapabilities()` output, `RefactoringTarget` parsing, `ProjectScanner`.
- Script symbol: `fqcn` = root-relative path with `/`, `kind` = `'script'`, `file` = same path, `line` = `1`, `methods` = top-level functions in the class-method shape (`name`, `parameters`, `return_types`, `line`); `properties`/`constants`/`attributes` = `[]`. Created lazily (only when the file has a script-scope reference or a top-level function).
- Attribution: script scope → `source` = path, `sourceMethod` = `null`; top-level function → `sourceMethod` = function name; nested named functions and anonymous class bodies → the declaring routine, unchanged.
- Inside anonymous classes `$this`/`self`/`static` resolve to `null` (`unknown`); `parent::` resolves. No symbol for anonymous classes.
- `ClassName::class` → `class_constant`, `Confidence::EXACT`, `metadata ['constant' => 'class']`; `$class::class` stays `unknown`.
- Indexer diagnostic for a failing file: `new ParseDiagnostic($relative, 1, sprintf('Analysis failed: %s: %s', $failure::class, $failure->getMessage()))` — never include `$failure->getFile()`/`getLine()`.
- Local-scope frames on `localScopeStack` are positional lists; the ClassLike stash on `classStack` is a positional list restored by list destructuring in `leaveNode()` — keep push and pop orders identical.
- TDD per task: write the failing test, run it (expect failure), implement, run it (expect pass), run `vendor/bin/phpunit --no-coverage` once before committing (expect `OK`, 1 skipped, 10 pre-existing PHPUnit deprecations), then `git checkout -- .phpunit.cache/test-results` before `git status`.
- Commit messages end with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- Diagnostic/message strings in English; CHANGELOG in Português (pt-BR) with full diacritics.

---

### Task 1: Indexer resilience

**Files:**
- Modify: `src/Refactoring/Analysis/Index/CodebaseIndexer.php:33-44`
- Test: `tests/Unit/Refactoring/CodebaseIndexerTest.php`
- Test: `tests/Unit/Refactoring/Application/DefaultRefactoringCapabilitiesTest.php`

**Interfaces:**
- Consumes: `AstParser::parse(string $file, ?string $displayPath = null): ParsedFile`, `ParseDiagnostic(string $file, int $line, string $message)`.
- Produces: an index whose `diagnostics()` contains one `ParseDiagnostic` per file whose `parse()` threw; nothing else changes.

- [ ] **Step 1: Write the failing indexer test**

Append to `tests/Unit/Refactoring/CodebaseIndexerTest.php` (inside the class, after `test_it_parses_every_discovered_file_once`):

```php
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
```

- [ ] **Step 2: Write the failing capability test**

Append to `tests/Unit/Refactoring/Application/DefaultRefactoringCapabilitiesTest.php`, right before `private function countingParser()`:

```php
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
            $result->diagnostics,
        );
        $this->assertTrue($result->incomplete());
    }
```

- [ ] **Step 3: Run both tests to verify they fail**

Run: `vendor/bin/phpunit --no-coverage --filter 'test_a_failing_file_becomes_a_diagnostic_and_indexing_continues|test_an_analysis_failure_in_one_file_is_reported_as_an_envelope_diagnostic'`
Expected: 2 errors — the `TypeError`/`RuntimeException` escape `build()`.

- [ ] **Step 4: Guard the parse call**

In `src/Refactoring/Analysis/Index/CodebaseIndexer.php` replace

```php
            $relative = ProjectRoot::relative($root, $file);
            $parsed = $this->parser->parse($file, $relative);
            $diagnostics = array_merge($diagnostics, $parsed->diagnostics);
```

with

```php
            $relative = ProjectRoot::relative($root, $file);
            try {
                $parsed = $this->parser->parse($file, $relative);
            } catch (\Throwable $failure) {
                // One unreadable or unanalysable file must not abort the whole index; the
                // message deliberately omits the exception's own file/line (internal paths
                // would otherwise leave the process through the MCP HTTP transport).
                $diagnostics[] = new ParseDiagnostic(
                    $relative,
                    1,
                    sprintf('Analysis failed: %s: %s', $failure::class, $failure->getMessage()),
                );
                continue;
            }
            $diagnostics = array_merge($diagnostics, $parsed->diagnostics);
```

- [ ] **Step 5: Run the two tests, then the full suite**

Run: `vendor/bin/phpunit --no-coverage --filter 'test_a_failing_file_becomes_a_diagnostic_and_indexing_continues|test_an_analysis_failure_in_one_file_is_reported_as_an_envelope_diagnostic'` → 2 passing.
Run: `vendor/bin/phpunit --no-coverage` → `OK (469 tests …)`, 1 skipped, 10 pre-existing deprecations. Then `git checkout -- .phpunit.cache/test-results`.

- [ ] **Step 6: Commit**

```bash
git add src/Refactoring/Analysis/Index/CodebaseIndexer.php tests/Unit/Refactoring/CodebaseIndexerTest.php tests/Unit/Refactoring/Application/DefaultRefactoringCapabilitiesTest.php
git commit -m "fix: degrade per-file analysis failures to diagnostics

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: Record `ClassName::class` as a class_constant reference

**Files:**
- Modify: `src/Refactoring/Analysis/Ast/StructureCollector.php` (`collectClassConstant()`)
- Test: `tests/Unit/Refactoring/Ast/LaravelReferenceTest.php` (`test_dynamic_class_constant_references_preserve_every_known_fact`)

**Interfaces:**
- Produces: `Reference(type: CLASS_CONSTANT, confidence: EXACT, metadata: ['constant' => 'class'])` for every `Name::class` expression; dynamic `$expr::class` unchanged (`target: null`, `unknown`).

- [ ] **Step 1: Update the locked test to expect the fourth reference**

In `tests/Unit/Refactoring/Ast/LaravelReferenceTest.php`, inside `test_dynamic_class_constant_references_preserve_every_known_fact`, replace `$this->assertCount(3, $constants);` with `$this->assertCount(4, $constants);` and append after the last existing assertion of that test:

```php
        $this->assertSame('Demo\\KnownClass', $constants[3]->target);
        $this->assertSame(Confidence::EXACT, $constants[3]->confidence);
        $this->assertSame(['constant' => 'class'], $constants[3]->metadata);
        $this->assertSame(8, $constants[3]->line);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --no-coverage --filter test_dynamic_class_constant_references_preserve_every_known_fact`
Expected: FAIL — "actual size 3 matches expected size 4".

- [ ] **Step 3: Stop skipping `::class`**

In `collectClassConstant()` delete these three lines:

```php
        if ($node->class instanceof Node\Name && $constant !== null && strcasecmp($constant, 'class') === 0) {
            return;
        }
```

Nothing else changes: the existing `addReference(..., ['constant' => $constant])` call already produces `['constant' => 'class']`.

- [ ] **Step 4: Run the test, then the full suite**

Run: `vendor/bin/phpunit --no-coverage --filter test_dynamic_class_constant_references_preserve_every_known_fact` → passing.
Run: `vendor/bin/phpunit --no-coverage` → `OK`, no other test changes expectation (verified by prototype). Then `git checkout -- .phpunit.cache/test-results`.

- [ ] **Step 5: Commit**

```bash
git add src/Refactoring/Analysis/Ast/StructureCollector.php tests/Unit/Refactoring/Ast/LaravelReferenceTest.php
git commit -m "feat: record ClassName::class as a class_constant reference

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: Script scope, lazy script symbol and anonymous-class attribution

**Files:**
- Modify: `src/Refactoring/Analysis/Ast/StructureCollector.php` (fields, constructor, `symbols()`, `enterNode()`, `leaveNode()`, `enterClass()`, `enterMethod()`, `collectProperty()`, `collectConstants()`, `collectAttributes()`, `addReference()`)
- Test: `tests/Unit/Refactoring/Ast/PhpAstParserTest.php`

**Interfaces:**
- Consumes: `SymbolDefinition(string $fqcn, string $kind, string $file, int $line, array $methods = [], ...)`, `Reference(string $source, ?string $sourceMethod, ...)`.
- Produces: `StructureCollector::symbols()` returns class-like symbols followed by at most one `SymbolDefinition($file, 'script', $file, 1, $methods)`; every `Reference::$source` is either a class FQCN or the file path. Task 4 adds entries to `$this->script['methods']`; Task 5 relies on `kind === 'script'`.

- [ ] **Step 1: Add the test helpers and the failing tests**

In `tests/Unit/Refactoring/Ast/PhpAstParserTest.php` add `use Peralta\AgentKit\Refactoring\Analysis\DTOs\ParsedFile;` and `use Peralta\AgentKit\Refactoring\Analysis\Graph\Confidence;` to the imports, then add these private helpers next to `has()` / `hasFrom()`:

```php
    private function parseCode(string $displayPath, string $code): ParsedFile
    {
        $file = tempnam(sys_get_temp_dir(), 'ast-');
        file_put_contents($file, $code);
        set_error_handler(static function (int $severity, string $message, string $filename, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $filename, $line);
        });

        try {
            return (new PhpAstParser())->parse($file, $displayPath);
        } finally {
            restore_error_handler();
            unlink($file);
        }
    }

    private function hasFromMethod(array $references, string $source, ?string $sourceMethod, DependencyType $type, string $target): bool
    {
        foreach ($references as $reference) {
            if ($reference->source === $source
                && $reference->sourceMethod === $sourceMethod
                && $reference->type === $type
                && $reference->target === $target) {
                return true;
            }
        }

        return false;
    }
```

In `test_it_parses_code_outside_classes_without_scope_errors` replace

```php
        if ($expectedClass === null) {
            $this->assertSame([], $parsed->symbols);

            return;
        }
```

with

```php
        if ($expectedClass === null) {
            // Procedural files may now yield a script symbol, but never a class-like one.
            $this->assertSame([], array_filter($parsed->symbols, fn ($symbol) => $symbol->kind !== 'script'));

            return;
        }
```

Then add these tests after `test_it_parses_code_outside_classes_without_scope_errors` / its provider:

```php
    public function test_script_scope_references_create_a_lazy_script_symbol(): void
    {
        $parsed = $this->parseCode('routes/web.php', <<<'PHP'
<?php
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
Route::get('/users', [UserController::class, 'index']);
Route::middleware(['auth'])->group(function () {
    Route::post('/users', fn () => (new UserController())->store(app(\App\Services\UserMaker::class)));
});
PHP);

        $this->assertSame([], $parsed->diagnostics);
        $this->assertCount(1, $parsed->symbols);
        $script = $parsed->symbols[0];
        $this->assertSame('routes/web.php', $script->fqcn);
        $this->assertSame('script', $script->kind);
        $this->assertSame('routes/web.php', $script->file);
        $this->assertSame(1, $script->line);
        $this->assertSame([], $script->methods);
        $this->assertSame([], $script->properties);

        $this->assertTrue($this->hasFrom($parsed->references, 'routes/web.php', DependencyType::FACADE, 'Illuminate\\Support\\Facades\\Route'));
        $this->assertTrue($this->hasFrom($parsed->references, 'routes/web.php', DependencyType::CLASS_CONSTANT, 'App\\Http\\Controllers\\UserController'));
        $this->assertTrue($this->hasFrom($parsed->references, 'routes/web.php', DependencyType::INSTANTIATION, 'App\\Http\\Controllers\\UserController'));
        $this->assertTrue($this->hasFrom($parsed->references, 'routes/web.php', DependencyType::INSTANTIATION, 'App\\Services\\UserMaker'));
        foreach ($parsed->references as $reference) {
            $this->assertSame('routes/web.php', $reference->source);
            $this->assertNull($reference->sourceMethod);
        }
    }

    public function test_config_arrays_reference_the_classes_they_name(): void
    {
        $parsed = $this->parseCode('config/app.php', "<?php\nreturn ['providers' => [App\\Providers\\AppServiceProvider::class], 'debug' => env('APP_DEBUG') ? true : false];");

        $this->assertSame(['config/app.php'], array_map(fn ($symbol) => $symbol->fqcn, $parsed->symbols));
        $this->assertTrue($this->hasFrom($parsed->references, 'config/app.php', DependencyType::CLASS_CONSTANT, 'App\\Providers\\AppServiceProvider'));
    }

    public function test_files_without_script_scope_references_get_no_script_symbol(): void
    {
        $parsed = $this->parseCode('bootstrap/empty.php', "<?php\n\$mode = \$argc > 1 ? 'verbose' : 'quiet';\n\$app = function () use (\$mode) { return \$mode; };");

        $this->assertSame([], $parsed->symbols);
        $this->assertSame([], $parsed->references);
    }

    public function test_anonymous_class_bodies_are_attributed_to_the_declaring_script(): void
    {
        $script = 'database/migrations/2026_01_01_000000_create_users_table.php';
        $parsed = $this->parseCode($script, <<<'PHP'
<?php
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    private User $user;
    public function __construct(private readonly Blueprint $blueprint) {}
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) { $table->id(); });
        $this->user->save();
        $this->blueprint->timestamps();
        $this->down();
        self::seed();
        parent::up();
    }
    public function down(): void {}
    public static function seed(): void {}
};
PHP);

        $this->assertSame([], $parsed->diagnostics);
        $this->assertSame([$script], array_map(fn ($symbol) => $symbol->fqcn, $parsed->symbols));
        $this->assertSame('script', $parsed->symbols[0]->kind);
        $this->assertSame([], $parsed->symbols[0]->methods);
        $this->assertTrue($this->hasFrom($parsed->references, $script, DependencyType::EXTENDS, 'Illuminate\\Database\\Migrations\\Migration'));
        $this->assertTrue($this->hasFrom($parsed->references, $script, DependencyType::FACADE, 'Illuminate\\Support\\Facades\\Schema'));
        $this->assertTrue($this->hasFrom($parsed->references, $script, DependencyType::PROPERTY_TYPE, 'App\\Models\\User'));
        $this->assertTrue($this->hasFrom($parsed->references, $script, DependencyType::CONSTRUCTOR_INJECTION, 'Illuminate\\Database\\Schema\\Blueprint'));
        $this->assertTrue($this->hasFrom($parsed->references, $script, DependencyType::METHOD_CALL, 'App\\Models\\User'));
        $this->assertTrue($this->hasFrom($parsed->references, $script, DependencyType::METHOD_CALL, 'Illuminate\\Database\\Schema\\Blueprint'));
        $this->assertTrue($this->hasFrom($parsed->references, $script, DependencyType::STATIC_CALL, 'Illuminate\\Database\\Migrations\\Migration'));
        foreach ($parsed->references as $reference) {
            $this->assertSame($script, $reference->source);
            $this->assertNull($reference->sourceMethod);
        }
        // $this->down() and self::seed() have no name inside an anonymous class.
        $unnamed = array_values(array_filter($parsed->references, fn ($reference) => $reference->target === null));
        $this->assertCount(2, $unnamed);
        $this->assertSame([Confidence::UNKNOWN, Confidence::UNKNOWN], array_map(fn ($reference) => $reference->confidence, $unnamed));
        $this->assertSame([14, 15], array_map(fn ($reference) => $reference->line, $unnamed));
    }

    public function test_anonymous_classes_inside_methods_are_attributed_to_the_declaring_method(): void
    {
        $parsed = $this->parseCode('Host.php', <<<'PHP'
<?php
namespace Demo;
class Host {
    public function boot(): void {
        $listener = new class(new Service()) {
            public function __construct(private Service $service) {}
            public function handle(): void { $this->service->run(); $this->handle(); }
        };
        $this->helper();
    }
    private function helper(): void {}
}
PHP);

        $this->assertSame(['Demo\\Host'], array_map(fn ($symbol) => $symbol->fqcn, $parsed->symbols));
        $this->assertSame(['boot', 'helper'], array_column($parsed->symbols[0]->methods, 'name'));
        $this->assertSame([], $parsed->symbols[0]->properties);
        $this->assertTrue($this->hasFromMethod($parsed->references, 'Demo\\Host', 'boot', DependencyType::INSTANTIATION, 'Demo\\Service'));
        $this->assertTrue($this->hasFromMethod($parsed->references, 'Demo\\Host', 'boot', DependencyType::CONSTRUCTOR_INJECTION, 'Demo\\Service'));
        $this->assertTrue($this->hasFromMethod($parsed->references, 'Demo\\Host', 'boot', DependencyType::METHOD_CALL, 'Demo\\Service'));
        $this->assertTrue($this->hasFromMethod($parsed->references, 'Demo\\Host', 'boot', DependencyType::METHOD_CALL, 'Demo\\Host'));
        // $this->handle() inside the anonymous class is not a Host call; $this->helper() after it is.
        $hostCalls = array_filter($parsed->references, fn ($reference) => $reference->type === DependencyType::METHOD_CALL && $reference->target === 'Demo\\Host');
        $this->assertCount(1, $hostCalls);
        foreach ($parsed->references as $reference) {
            $this->assertSame('Demo\\Host', $reference->source);
            $this->assertSame('boot', $reference->sourceMethod);
        }
    }

    public function test_a_file_mixing_a_class_and_script_code_yields_both_symbols(): void
    {
        $parsed = $this->parseCode('app/Support/Clock.php', <<<'PHP'
<?php
namespace App\Support;
use App\Support\Contracts\Now;
class Clock implements Now { public function now(): \DateTimeImmutable { return new \DateTimeImmutable(); } }
Clock::register(new Clock());
PHP);

        $this->assertSame(['App\\Support\\Clock', 'app/Support/Clock.php'], array_map(fn ($symbol) => $symbol->fqcn, $parsed->symbols));
        $this->assertSame(['class', 'script'], array_map(fn ($symbol) => $symbol->kind, $parsed->symbols));
        $this->assertTrue($this->hasFrom($parsed->references, 'App\\Support\\Clock', DependencyType::IMPLEMENTS, 'App\\Support\\Contracts\\Now'));
        $this->assertTrue($this->hasFrom($parsed->references, 'app/Support/Clock.php', DependencyType::STATIC_CALL, 'App\\Support\\Clock'));
        $this->assertTrue($this->hasFrom($parsed->references, 'app/Support/Clock.php', DependencyType::INSTANTIATION, 'App\\Support\\Clock'));
    }
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/Unit/Refactoring/Ast/PhpAstParserTest.php`
Expected: the six new tests fail (no symbols / no references at script scope); `test_files_without_script_scope_references_get_no_script_symbol` may already pass.

- [ ] **Step 3: Add the attribution state**

In `StructureCollector` replace the field block and constructor with:

```php
    private array $symbols = [];
    private array $references = [];
    private ?string $namespace = null;
    private array $imports = [];
    private array $classStack = [];
    private ?string $currentClass = null;
    private ?string $currentParent = null;
    private ?string $currentMethod = null;
    private ?array $symbol = null;
    private array $propertyTypes = [];
    private array $localTypes = [];
    private array $localScopeStack = [];
    private array $conditionalScopes = [];
    private array $taintedLocals = [];
    private bool $allLocalsTainted = false;
    /** Attribution source: the file path at script scope, the FQCN inside a named class. */
    private string $source;
    /** True while no named class encloses the visitor (top level and anonymous-class bodies there). */
    private bool $scriptScope = true;
    /** Script symbol created on the first script-scope reference or top-level function. */
    private ?array $script = null;
    private readonly NameContext $nameContext;

    public function __construct(
        private readonly string $file,
        private readonly array $facadePrefixes = [],
    ) {
        $this->source = $file;
        $this->nameContext = new NameContext();
    }

    public function symbols(): array
    {
        if ($this->script === null) {
            return $this->symbols;
        }

        return [
            ...$this->symbols,
            new SymbolDefinition($this->file, 'script', $this->file, 1, $this->script['methods']),
        ];
    }
```

- [ ] **Step 4: Remove the scope guards**

In `enterNode()` delete

```php
        if ($this->currentClass === null) {
            return null;
        }

```

(the block right after the `ClassLike` branch). In `leaveNode()` delete the comment and guard

```php
        // enterNode() skips every node outside a named class (procedural files, top-level
        // functions, anonymous class bodies), so nothing was pushed for them and popping
        // here would underflow the scope stacks. Mirror that guard exactly.
        if ($this->currentClass === null) {
            return null;
        }

```

and change the `ClassLike` restore to include the two new fields, and the `ClassMethod` reset so anonymous-class methods keep the declaring routine:

```php
            [$this->currentClass, $this->currentParent, $this->currentMethod, $this->symbol, $this->propertyTypes, $this->localTypes, $this->localScopeStack, $this->conditionalScopes, $this->taintedLocals, $this->allLocalsTainted, $this->source, $this->scriptScope]
                = array_pop($this->classStack);
```

```php
        if ($node instanceof Node\Stmt\ClassMethod) {
            if ($this->symbol !== null) {
                $this->currentMethod = null;
            }
            $this->localTypes = [];
            $this->localScopeStack = [];
            $this->conditionalScopes = [];
            $this->taintedLocals = [];
            $this->allLocalsTainted = false;
        }
```

- [ ] **Step 5: Rewrite `enterClass()`**

```php
    private function enterClass(Node\Stmt\ClassLike $node): void
    {
        $this->classStack[] = [
            $this->currentClass,
            $this->currentParent,
            $this->currentMethod,
            $this->symbol,
            $this->propertyTypes,
            $this->localTypes,
            $this->localScopeStack,
            $this->conditionalScopes,
            $this->taintedLocals,
            $this->allLocalsTainted,
            $this->source,
            $this->scriptScope,
        ];

        $namespacedName = $node->namespacedName;
        $this->currentClass = $namespacedName instanceof Node\Name
            ? ltrim($namespacedName->toString(), '\\')
            : null;
        // An anonymous class keeps the declaring routine as its source: its body is
        // attributed to that routine and no symbol is emitted for it.
        if ($this->currentClass !== null) {
            $this->source = $this->currentClass;
            $this->scriptScope = false;
            $this->currentMethod = null;
        }
        $this->propertyTypes = [];
        $this->localTypes = [];
        $this->localScopeStack = [];
        $this->conditionalScopes = [];
        $this->taintedLocals = [];
        $this->allLocalsTainted = false;

        $parent = $node instanceof Node\Stmt\Class_ ? $node->extends : null;
        $this->currentParent = $parent instanceof Node\Name ? $this->resolvedName($parent) : null;
        $this->nameContext->set($this->currentClass, $this->currentParent);

        $this->symbol = $this->currentClass === null ? null : [
            'kind' => match (true) {
                $node instanceof Node\Stmt\Interface_ => 'interface',
                $node instanceof Node\Stmt\Trait_ => 'trait',
                $node instanceof Node\Stmt\Enum_ => 'enum',
                default => 'class',
            },
            'line' => $node->getStartLine(),
            'methods' => [],
            'properties' => [],
            'constants' => [],
            'attributes' => [],
        ];
        $this->primePropertyTypes($node);

        if ($this->currentParent !== null) {
            $this->addReference($this->currentParent, null, DependencyType::EXTENDS, Confidence::EXACT, $node);
        }

        if ($node instanceof Node\Stmt\Interface_) {
            foreach ($node->extends as $interface) {
                $this->addReference($this->resolvedName($interface), null, DependencyType::EXTENDS, Confidence::EXACT, $interface);
            }
        } elseif ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Enum_) {
            foreach ($node->implements as $interface) {
                $this->addReference($this->resolvedName($interface), null, DependencyType::IMPLEMENTS, Confidence::EXACT, $interface);
            }
        }

        $this->collectAttributes($node);
    }
```

- [ ] **Step 6: Guard the symbol writes**

`enterMethod()`: replace its first line `$this->currentMethod = $node->name->toString();` with

```php
        $name = $node->name->toString();
        if ($this->symbol !== null) {
            $this->currentMethod = $name;
        }
```

replace `$dependencyType = $this->currentMethod === '__construct'` with `$dependencyType = $name === '__construct'`, wrap the promoted-property push as

```php
                if ($this->symbol !== null) {
                    $this->symbol['properties'][] = [
                        'name' => $param->var->name,
                        'types' => $types,
                        'line' => $param->getStartLine(),
                    ];
                }
```

and wrap the method registration as

```php
        if ($this->symbol !== null) {
            $this->symbol['methods'][] = [
                'name' => $name,
                'parameters' => $parameters,
                'return_types' => $returnTypes,
                'line' => $node->getStartLine(),
            ];
        }
```

`collectProperty()`: wrap the `$this->symbol['properties'][] = [...]` push in `if ($this->symbol !== null) { ... }` (the `propertyTypes` update and the references stay unconditional).
`collectConstants()`: wrap the `$this->symbol['constants'][] = [...]` push in `if ($this->symbol !== null) { ... }`.
`collectAttributes()`: replace `$this->symbol['attributes'][] = $target;` with

```php
                    if ($this->symbol !== null) {
                        $this->symbol['attributes'][] = $target;
                    }
```

- [ ] **Step 7: Attribute references to the source**

Replace `addReference()` with:

```php
    private function addReference(
        ?string $target,
        ?string $targetMethod,
        DependencyType $type,
        Confidence $confidence,
        Node $node,
        array $metadata = [],
    ): void {
        if ($this->scriptScope) {
            $this->script ??= ['methods' => []];
        }
        $this->references[] = new Reference(
            $this->source,
            $this->currentMethod,
            $target,
            $targetMethod,
            $type,
            $target === null ? Confidence::UNKNOWN : $confidence,
            $this->file,
            $node->getStartLine(),
            $metadata,
        );
    }
```

- [ ] **Step 8: Run the parser tests, then the full suite**

Run: `vendor/bin/phpunit --no-coverage tests/Unit/Refactoring/Ast` → all passing (including PR #5's data-provider test and `LaravelReferenceTest`).
Run: `vendor/bin/phpunit --no-coverage` → `OK`. Then `git checkout -- .phpunit.cache/test-results`.

- [ ] **Step 9: Commit**

```bash
git add src/Refactoring/Analysis/Ast/StructureCollector.php tests/Unit/Refactoring/Ast/PhpAstParserTest.php
git commit -m "feat: index script-scope code and anonymous class bodies

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: Named functions — top-level routines and nested scopes

**Files:**
- Modify: `src/Refactoring/Analysis/Ast/StructureCollector.php` (`enterNode()`, `leaveNode()`, `enterLocalFunction()`, new `enterFunction()`, `writtenVariablesIn()`)
- Test: `tests/Unit/Refactoring/Ast/PhpAstParserTest.php`

**Interfaces:**
- Consumes: Task 3's `$this->script`, `$this->scriptScope`, `$this->source`, `addReference()`.
- Produces: `$this->script['methods'][]` entries `['name' => string, 'parameters' => list<array{name: ?string, types: list<string>, line: int}>, 'return_types' => list<string>, 'line' => int]` for top-level functions; local-scope frames become 6-element lists `[localTypes, conditionalScopes, taintedLocals, allLocalsTainted, byReference, currentMethod]`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Refactoring/Ast/PhpAstParserTest.php` after the Task 3 tests:

```php
    public function test_top_level_functions_are_registered_on_the_script_symbol(): void
    {
        $parsed = $this->parseCode('app/helpers.php', <<<'PHP'
<?php
use App\Models\User;
use App\Services\UserMaker;
if (!function_exists('make_user')) {
    function make_user(UserMaker $maker, string $name = 'x'): User
    {
        $user = $maker->make($name);
        $user->save();
        return $user;
    }
}
function plain(): void { new UserMaker(); }
PHP);

        $this->assertSame([], $parsed->diagnostics);
        $this->assertCount(1, $parsed->symbols);
        $script = $parsed->symbols[0];
        $this->assertSame('app/helpers.php', $script->fqcn);
        $this->assertSame('script', $script->kind);
        $this->assertSame(['make_user', 'plain'], array_column($script->methods, 'name'));
        $this->assertSame([5, 12], array_column($script->methods, 'line'));
        $this->assertSame(['App\\Models\\User'], $script->methods[0]['return_types']);
        $this->assertSame(['maker', 'name'], array_column($script->methods[0]['parameters'], 'name'));
        $this->assertSame(['App\\Services\\UserMaker'], $script->methods[0]['parameters'][0]['types']);
        $this->assertSame([], $script->methods[0]['parameters'][1]['types']);

        $this->assertTrue($this->hasFromMethod($parsed->references, 'app/helpers.php', 'make_user', DependencyType::METHOD_PARAMETER, 'App\\Services\\UserMaker'));
        $this->assertTrue($this->hasFromMethod($parsed->references, 'app/helpers.php', 'make_user', DependencyType::RETURN_TYPE, 'App\\Models\\User'));
        $this->assertTrue($this->hasFromMethod($parsed->references, 'app/helpers.php', 'make_user', DependencyType::METHOD_CALL, 'App\\Services\\UserMaker'));
        $this->assertTrue($this->hasFromMethod($parsed->references, 'app/helpers.php', 'plain', DependencyType::INSTANTIATION, 'App\\Services\\UserMaker'));
        $this->assertFalse($this->hasFromMethod($parsed->references, 'app/helpers.php', null, DependencyType::INSTANTIATION, 'App\\Services\\UserMaker'));
    }

    public function test_script_locals_survive_a_top_level_function_declaration(): void
    {
        $parsed = $this->parseCode('bootstrap/app.php', <<<'PHP'
<?php
use Illuminate\Foundation\Application;
$app = new Application(dirname(__DIR__));
function configure(Application $app): void { $app->useStoragePath('x'); }
$app->useEnvironmentPath('y');
PHP);

        $calls = array_values(array_filter($parsed->references, fn ($reference) => $reference->type === DependencyType::METHOD_CALL));
        $this->assertSame(
            [['configure', 'useStoragePath', 'Illuminate\\Foundation\\Application'], [null, 'useEnvironmentPath', 'Illuminate\\Foundation\\Application']],
            array_map(fn ($reference) => [$reference->sourceMethod, $reference->targetMethod, $reference->target], $calls),
        );
    }

    public function test_nested_named_functions_get_their_own_scope_without_leaking_types(): void
    {
        $parsed = $this->parseCode('Nested.php', <<<'PHP'
<?php
namespace Demo;
class Host {
    public function boot(Service $service): void {
        function helper(Other $service): void { $service->other(); $inner = new Extra(); $inner->extra(); }
        $service->run();
        $inner->missing();
    }
}
PHP);

        $this->assertSame(['Demo\\Host'], array_map(fn ($symbol) => $symbol->fqcn, $parsed->symbols));
        $this->assertSame(['boot'], array_column($parsed->symbols[0]->methods, 'name'));
        $this->assertTrue($this->hasFromMethod($parsed->references, 'Demo\\Host', 'boot', DependencyType::METHOD_CALL, 'Demo\\Other'));
        $this->assertTrue($this->hasFromMethod($parsed->references, 'Demo\\Host', 'boot', DependencyType::METHOD_CALL, 'Demo\\Extra'));
        $this->assertTrue($this->hasFromMethod($parsed->references, 'Demo\\Host', 'boot', DependencyType::METHOD_CALL, 'Demo\\Service'));
        // Nested function parameters are locals of that function, not structural dependencies of boot().
        $this->assertFalse($this->has($parsed->references, DependencyType::METHOD_PARAMETER, 'Demo\\Other'));
        $missing = array_values(array_filter($parsed->references, fn ($reference) => $reference->type === DependencyType::METHOD_CALL && $reference->target === null));
        $this->assertCount(1, $missing);
        $this->assertSame(7, $missing[0]->line);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage --filter 'test_top_level_functions_are_registered_on_the_script_symbol|test_script_locals_survive_a_top_level_function_declaration|test_nested_named_functions_get_their_own_scope_without_leaking_types'`
Expected: 3 failures (no `methods` on the script; `helper`'s `$service`/`$inner` leak into `boot`).

- [ ] **Step 3: Handle `Stmt\Function_` on both sides**

In `enterNode()` change the closure branch to

```php
        if ($node instanceof Node\Stmt\Function_) {
            $this->enterFunction($node);
        } elseif ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $this->enterLocalFunction($node);
        } elseif ($this->isUncertainControlFlow($node)) {
```

In `leaveNode()` replace the closure pop with

```php
        if ($node instanceof Node\Stmt\Function_
            || $node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction) {
            [$this->localTypes, $this->conditionalScopes, $this->taintedLocals, $this->allLocalsTainted, $byReference, $this->currentMethod]
                = array_pop($this->localScopeStack);
            $this->taintVariables($byReference);
        }
```

In `enterLocalFunction()` change the frame push to

```php
        $this->localScopeStack[] = [$outerTypes, $this->conditionalScopes, $outerTainted, $outerAllTainted, $byReference, $this->currentMethod];
```

Add after `enterLocalFunction()`:

```php
    private function enterFunction(Node\Stmt\Function_ $node): void
    {
        // Top level means: not inside any class (named or anonymous), routine or closure.
        // Only those functions become routines of the script symbol; a function declared
        // inside a method or closure just gets a fresh local scope, like a closure without
        // captured variables, and its body stays attributed to the declaring routine.
        $topLevel = $this->classStack === []
            && $this->currentMethod === null
            && $this->localScopeStack === [];

        $this->localScopeStack[] = [$this->localTypes, $this->conditionalScopes, $this->taintedLocals, $this->allLocalsTainted, [], $this->currentMethod];
        $this->conditionalScopes = [];
        $this->localTypes = [];
        $this->taintedLocals = [];
        $this->allLocalsTainted = false;
        if ($topLevel) {
            $this->currentMethod = $node->name->toString();
        }

        $parameters = [];
        foreach ($node->params as $param) {
            $types = $this->classTypes($param->type);
            $name = $param->var instanceof Node\Expr\Variable && is_string($param->var->name)
                ? $param->var->name
                : null;
            $parameters[] = [
                'name' => $name,
                'types' => $types,
                'line' => $param->getStartLine(),
            ];
            if ($name !== null && count($types) === 1) {
                $this->localTypes[$name] = $types[0];
            }
            if ($topLevel) {
                foreach ($types as $type) {
                    $this->addReference($type, null, DependencyType::METHOD_PARAMETER, Confidence::EXACT, $param);
                }
            }
        }

        if (!$topLevel) {
            return;
        }

        $returnTypes = $this->classTypes($node->returnType);
        foreach ($returnTypes as $type) {
            $this->addReference($type, null, DependencyType::RETURN_TYPE, Confidence::EXACT, $node);
        }
        $this->script ??= ['methods' => []];
        $this->script['methods'][] = [
            'name' => $this->currentMethod,
            'parameters' => $parameters,
            'return_types' => $returnTypes,
            'line' => $node->getStartLine(),
        ];
    }
```

In `writtenVariablesIn()` extend the early return so nested functions do not invalidate the enclosing routine's locals:

```php
            if (!$isRoot && ($node instanceof Node\Expr\Closure
                || $node instanceof Node\Expr\ArrowFunction
                || $node instanceof Node\Stmt\Function_
                || $node instanceof Node\Stmt\ClassLike)) {
                return;
            }
```

- [ ] **Step 4: Run the parser tests, then the full suite**

Run: `vendor/bin/phpunit --no-coverage tests/Unit/Refactoring/Ast` → all passing.
Run: `vendor/bin/phpunit --no-coverage` → `OK`. Then `git checkout -- .phpunit.cache/test-results`.

- [ ] **Step 5: Commit**

```bash
git add src/Refactoring/Analysis/Ast/StructureCollector.php tests/Unit/Refactoring/Ast/PhpAstParserTest.php
git commit -m "feat: index top-level functions and scope nested ones

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: Scripts as index nodes and capability targets

**Files:**
- Modify: `src/Refactoring/Application/DefaultRefactoringCapabilities.php` (`analysisTarget()`, lines 232-241)
- Test: `tests/Unit/Refactoring/CodebaseIndexerTest.php`
- Test: `tests/Unit/Refactoring/Application/DefaultRefactoringCapabilitiesTest.php`

**Interfaces:**
- Consumes: `CodebaseIndex::classesInFile()` (now returns script symbols too), `SymbolDefinition::$kind === 'script'`.
- Produces: `analyze File.php::method` prefers class-like symbols; everything else already works through `findClass()`.

- [ ] **Step 1: Write the failing indexer test**

Append to `tests/Unit/Refactoring/CodebaseIndexerTest.php`:

```php
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
```

- [ ] **Step 2: Write the failing capability tests**

In `tests/Unit/Refactoring/Application/DefaultRefactoringCapabilitiesTest.php` add these helpers before `private function countingParser()`:

```php
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
```

and these tests before the helpers:

```php
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
```

- [ ] **Step 3: Run the new tests to verify they fail**

Run: `vendor/bin/phpunit --no-coverage --filter 'test_scripts_become_graph_nodes_and_edge_sources|test_scripts_are_reported_as_dependents_and_accepted_as_targets|test_a_class_file_with_top_level_code_keeps_class_method_targets_unambiguous'`
Expected: `test_scripts_become_graph_nodes_and_edge_sources` and `test_scripts_are_reported_as_dependents_and_accepted_as_targets` may already pass (Tasks 3-4 did the work); `test_a_class_file_with_top_level_code_keeps_class_method_targets_unambiguous` fails with `AMBIGUOUS_TARGET`.

- [ ] **Step 4: Prefer class-like symbols for `File.php::method`**

In `DefaultRefactoringCapabilities::analysisTarget()` replace

```php
            $relative = $this->relativePath($root, $file);
            $classes = $index->classesInFile($relative);

            return [$file, $relative, $classes, true];
```

with

```php
            $relative = $this->relativePath($root, $file);
            $classes = $index->classesInFile($relative);
            if ($target->method !== null) {
                // A file that mixes a class with top-level helpers stays a class target; the
                // script symbol only answers File.php::function when the file has no class.
                $classLike = array_values(array_filter(
                    $classes,
                    static fn (SymbolDefinition $symbol): bool => $symbol->kind !== 'script',
                ));
                if ($classLike !== []) {
                    $classes = $classLike;
                }
            }

            return [$file, $relative, $classes, true];
```

- [ ] **Step 5: Run the three tests, then the full suite**

Run: `vendor/bin/phpunit --no-coverage --filter 'test_scripts_become_graph_nodes_and_edge_sources|test_scripts_are_reported_as_dependents_and_accepted_as_targets|test_a_class_file_with_top_level_code_keeps_class_method_targets_unambiguous'` → 3 passing.
Run: `vendor/bin/phpunit --no-coverage` → `OK`. Then `git checkout -- .phpunit.cache/test-results`.

- [ ] **Step 6: Commit**

```bash
git add src/Refactoring/Application/DefaultRefactoringCapabilities.php tests/Unit/Refactoring/CodebaseIndexerTest.php tests/Unit/Refactoring/Application/DefaultRefactoringCapabilitiesTest.php
git commit -m "feat: accept scripts as refactoring targets and dependents

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 6: Documentation and changelog

**Files:**
- Modify: `REFACTORING_AGENT.md` ("AST structural analysis" paragraph, new "### Procedural code and scripts" subsection before "### Dependency types", "## Performance and diagnostics", "## Static-analysis limits")
- Modify: `CHANGELOG.md` (`## [Não Lançado]` — `### Adicionado`, `### Corrigido`, `### Alterado`)

**Interfaces:** none (prose only). Names must match the code exactly: `script`, `class_constant`, `metadata.constant`, `Analysis failed:`.

- [ ] **Step 1: Update the index description**

In `REFACTORING_AGENT.md` replace the paragraph starting `The index extracts namespaces, imports and aliases, classes, interfaces,` with:

```markdown
The index extracts namespaces, imports and aliases, classes, interfaces,
traits, enums, scripts (files with code outside a named class), methods,
top-level functions, properties, constants, attributes, inheritance,
implemented interfaces, used traits, declared types, `ClassName::class`
expressions, instantiations, static calls, class constants, and object calls
whose receiver type can be inferred safely. Every relationship retains its
source (class or script), source method, file, and line.
```

- [ ] **Step 2: Add the procedural-code subsection**

Insert immediately before `### Dependency types`:

```markdown
### Procedural code and scripts

Code outside a named class — `routes/*.php`, `config/*.php`,
`bootstrap/app.php`, helper files and anonymous-class migrations — is indexed
too. A file with at least one reference at script scope or at least one
top-level function yields a `script` symbol identified by its root-relative
path (for example `routes/web.php`, with `kind: "script"` and `line: 1`).
The symbol is created only when needed, so files that only declare classes
keep exactly the symbols they declare.

References are attributed to the routine that declares the code:

| Code | `source` | `source_method` |
|---|---|---|
| statement at script scope, including closures and control flow there | script path | `null` |
| body of a top-level function (also inside `if (!function_exists(...))`) | script path | function name |
| named function nested in a method, function or closure | the declaring routine | unchanged |
| anonymous class body | the declaring routine | unchanged |

Top-level functions are listed in the script's `methods` with the same shape
as class methods, so `helpers.php::make_user` is a valid `refactor-analyze`
target. Scripts appear as dependents in `refactor-callers`,
`refactor-impact` and `refactor-analyze` results, and a root-relative script
path is accepted wherever a class name is accepted (`refactor-dependencies
routes/web.php` lists what a routes file depends on). `ClassName::class`
expressions are recorded as `class_constant` edges with
`metadata.constant = "class"`, which is how routes, config arrays, listeners
and Eloquent relations name their classes.

Inside an anonymous class `$this`, `self` and `static` have no name, so calls
through them stay `unknown`; `parent::` resolves to the declared parent, and
the anonymous class's typed properties still drive receiver inference. Calls
to user-defined functions are not tracked: scripts and functions only appear
as sources, never as call targets.
```

- [ ] **Step 3: Update diagnostics and limits**

In `## Performance and diagnostics` replace `A syntax error in one PHP file produces a diagnostic and indexing continues.` with `A syntax error, an unreadable file or an internal analysis failure in one PHP file produces a diagnostic (\`Analysis failed: …\` for the latter two) and indexing continues.`

In `## Static-analysis limits` append to the paragraph: ` It also does not track calls to user-defined functions and names nothing for \`$this\`, \`self\` or \`static\` inside anonymous classes.`

- [ ] **Step 4: Changelog**

In `CHANGELOG.md` under `## [Não Lançado]`:

`### Adicionado` — append:

```markdown
- Indexação de código procedural no Refactoring Agent: arquivos com código fora de classes (`routes/*.php`, `config/*.php`, `bootstrap/app.php`, helpers, migrations com classe anônima) geram um símbolo `script` identificado pelo caminho relativo à raiz; funções top-level são registradas como rotinas do script (`helpers.php::make_user` vira alvo de `refactor-analyze`) e caminhos de script são aceitos como alvo em `refactor-dependencies`, `refactor-callers` e `refactor-impact`.
```

`### Corrigido` — append after the `StructureCollector` entry:

```markdown
- `CodebaseIndexer` converte falhas de leitura ou de análise de um único arquivo em diagnóstico (`Analysis failed: …`) em vez de abortar o índice inteiro.
- Funções nomeadas declaradas dentro de métodos ganham escopo local próprio e deixam de sobrescrever os tipos locais do método que as declara.
```

`### Alterado` — append:

```markdown
- `ClassName::class` passa a gerar aresta `class_constant` (`metadata.constant = "class"`) e corpos de classes anônimas passam a contribuir referências atribuídas à rotina que os declara; como scripts agora contam como dependentes em `refactor-impact`/`refactor-callers`, o risco de classes referenciadas por rotas, config e migrations pode subir.
```

- [ ] **Step 5: Verify names and commit**

Run: `grep -n "kind: \"script\"\|class_constant\|Analysis failed" REFACTORING_AGENT.md CHANGELOG.md` → every term present. Run: `vendor/bin/phpunit --no-coverage tests/Feature` (a doc-consistency test may exist) → `OK`. Then `git checkout -- .phpunit.cache/test-results`.

```bash
git add REFACTORING_AGENT.md CHANGELOG.md
git commit -m "docs: describe procedural code indexing and analysis diagnostics

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 7: Verification (controller-run, not dispatched)

- [ ] `vendor/bin/phpunit --no-coverage` → `OK`, 1 skipped, 10 pre-existing deprecations; `git checkout -- .phpunit.cache/test-results`; `git status --porcelain` empty.
- [ ] `composer validate --strict` → valid; `for f in $(git diff --name-only origin/main -- '*.php'); do php -l "$f"; done` → no errors.
- [ ] Stress parse with warnings → exceptions over `vendor/laravel/framework/src`, the Testbench skeleton, the whole `vendor/` tree and `/Users/alanperalta/www/mega-mobi/backend` → 0 failures; digest of symbols + references for every file whose pre-change digest exists → differences only in files that contain `::class`, procedural code, anonymous classes or nested functions (script generated in the scratchpad; report counts).
- [ ] PR #4 merge-compat check: in a throwaway worktree, `git merge --no-commit` of `origin/codex/refactoring-mcp-server` onto this branch, `composer install`, full suite → report result (do not push that merge).
- [ ] Ledger complete; `.superpowers/sdd/<plan>` workspace deleted after the final review.

## Self-review notes

- Spec coverage: script symbols (T3), attribution (T3), functions (T4), anonymous classes (T3), capabilities preference (T5), `::class` amendment (T2), indexer resilience (T1), docs (T6), verification (T7).
- Type consistency: `$this->script['methods']` entries created in T4 use the same keys `canonicalMethodInSymbol()`/`findMethod()` read (`name`); local-scope frames are 6-element lists in both `enterLocalFunction()` and `enterFunction()` and popped once in `leaveNode()`; `classStack` entries are 12-element lists pushed in `enterClass()` and popped in `leaveNode()`.
- Ordering: T2 before T3 so T3's route test can assert `class_constant` edges; T3 before T4 (`$this->script`); T5 last because its tests exercise the whole pipeline.
