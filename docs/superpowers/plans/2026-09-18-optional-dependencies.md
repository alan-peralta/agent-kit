# Optional MCP and AST Dependencies Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `mcp/sdk` and `nikic/php-parser` optional (`require-dev` + `suggest`), drop `ext-fileinfo`, and make every feature that needs them fail with a clear install hint.

**Architecture:** A new `MissingDependencyException` carries the package name and an install hint. `PhpAstParser` creates its parser lazily and checks `PhpParserRequirement` first; `CodebaseIndexer` lets that exception through and `DefaultRefactoringCapabilities` turns it into `CapabilityException('DEPENDENCY_MISSING')`, which the CLI and MCP adapters already render. `McpServeCommand` checks for the SDK before starting. A process-isolated feature test hides the optional packages from Composer's autoloader to prove the agent core, `artisan list`, the audit and the error paths work without them.

**Tech Stack:** PHP ^8.2, Laravel/Illuminate 10–12, PHPUnit 11 (`vendor/bin/phpunit --no-coverage`), Orchestra Testbench 10, Composer 2.9.

**Spec:** `docs/superpowers/specs/2026-09-18-optional-dependencies-design.md`

## Global Constraints

- Work only in the worktree `/Users/alanperalta/www/agent-kit/.worktrees/optional-dependencies` (branch `feat/optional-dependencies`); run every command from there.
- PHP `^8.2` and Laravel 10, 11 and 12 support stay unchanged.
- Install hint format, verbatim: `{feature} requires {package}. Install it with: composer require --dev {package}`.
- AST feature name, verbatim: `The AST analysis (analyze, callers, dependencies, impact)`.
- Older php-parser message, verbatim: `The AST analysis (analyze, callers, dependencies, impact) requires nikic/php-parser 5.x, but an older major version is installed. Upgrade it with: composer require --dev "nikic/php-parser:^5.0"`.
- MCP message, verbatim: `The MCP server requires mcp/sdk. Install it with: composer require --dev mcp/sdk`.
- New capability error code, verbatim: `DEPENDENCY_MISSING`.
- `mcp/sdk` conflict rule, verbatim: `"mcp/sdk": "<0.8.1 || >=0.9"`. No conflict rule for `nikic/php-parser`.
- Code, code comments, `MCP_SERVER.md`, `REFACTORING_AGENT.md` and commit messages are in English. `README.md`, `SETUP.md`, `ARCHITECTURE.md` and `CHANGELOG.md` are in Brazilian Portuguese with full diacritics.
- Match the surrounding code style: 4-space indentation, `final` classes, constructor property promotion, comments only where the reason is not obvious.
- Running PHPUnit rewrites the tracked file `.phpunit.cache/test-results`. Before every commit run `git checkout -- .phpunit.cache/test-results` and never commit it.
- Every commit message ends with the trailer line `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>` after a blank line.

---

### Task 1: Missing dependency exception, php-parser requirement and lazy parser

**Files:**
- Create: `src/Exceptions/MissingDependencyException.php`
- Create: `src/Refactoring/Analysis/Ast/PhpParserRequirement.php`
- Modify: `src/Refactoring/Analysis/Ast/PhpAstParser.php`
- Test: `tests/Unit/Exceptions/MissingDependencyExceptionTest.php` (create)
- Test: `tests/Unit/Refactoring/Ast/PhpParserRequirementTest.php` (create)
- Test: `tests/Unit/Refactoring/Ast/PhpAstParserTest.php` (add one test)

**Interfaces:**
- Produces:
  - `final class Peralta\AgentKit\Exceptions\MissingDependencyException extends \RuntimeException` with `__construct(public readonly string $package, string $message)` and `public static function forFeature(string $feature, string $package): self`.
  - `final class Peralta\AgentKit\Refactoring\Analysis\Ast\PhpParserRequirement` with `public const PACKAGE = 'nikic/php-parser'`, `public const FEATURE = 'The AST analysis (analyze, callers, dependencies, impact)'` and `public static function assertSatisfied(string $factory = ParserFactory::class): void` (throws `MissingDependencyException`).
  - `PhpAstParser::parse()` throws `MissingDependencyException` when php-parser 5.x is not available; its constructor no longer touches php-parser.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Exceptions/MissingDependencyExceptionTest.php`:

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Exceptions;

use Peralta\AgentKit\Exceptions\MissingDependencyException;
use PHPUnit\Framework\TestCase;

final class MissingDependencyExceptionTest extends TestCase
{
    public function test_for_feature_names_the_package_and_the_dev_install_command(): void
    {
        $exception = MissingDependencyException::forFeature('The MCP server', 'mcp/sdk');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertSame('mcp/sdk', $exception->package);
        $this->assertSame(
            'The MCP server requires mcp/sdk. Install it with: composer require --dev mcp/sdk',
            $exception->getMessage(),
        );
    }
}
```

Create `tests/Unit/Refactoring/Ast/PhpParserRequirementTest.php`:

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Ast;

use Peralta\AgentKit\Exceptions\MissingDependencyException;
use Peralta\AgentKit\Refactoring\Analysis\Ast\PhpParserRequirement;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class PhpParserRequirementTest extends TestCase
{
    public function test_the_installed_php_parser_5_satisfies_it(): void
    {
        PhpParserRequirement::assertSatisfied();
        PhpParserRequirement::assertSatisfied(ParserFactory::class);

        $this->addToAssertionCount(1);
    }

    public function test_a_missing_parser_names_the_package_to_install(): void
    {
        try {
            PhpParserRequirement::assertSatisfied('Peralta\\AgentKit\\Tests\\Missing\\ParserFactory');
            $this->fail('Expected a missing dependency.');
        } catch (MissingDependencyException $exception) {
            $this->assertSame('nikic/php-parser', $exception->package);
            $this->assertSame(
                'The AST analysis (analyze, callers, dependencies, impact) requires nikic/php-parser. Install it with: composer require --dev nikic/php-parser',
                $exception->getMessage(),
            );
        }
    }

    public function test_an_older_major_version_asks_for_an_upgrade(): void
    {
        // php-parser 4.x has a ParserFactory, but not the 5.x factory method the parser uses.
        try {
            PhpParserRequirement::assertSatisfied(\stdClass::class);
            $this->fail('Expected a missing dependency.');
        } catch (MissingDependencyException $exception) {
            $this->assertSame('nikic/php-parser', $exception->package);
            $this->assertSame(
                'The AST analysis (analyze, callers, dependencies, impact) requires nikic/php-parser 5.x, but an older major version is installed. Upgrade it with: composer require --dev "nikic/php-parser:^5.0"',
                $exception->getMessage(),
            );
        }
    }
}
```

Add this test method to `tests/Unit/Refactoring/Ast/PhpAstParserTest.php` (inside the class, after the first test):

```php
    public function test_construction_leaves_the_parser_to_the_first_parse(): void
    {
        // Resolving the refactoring services builds a PhpAstParser, and the audit must work
        // without nikic/php-parser installed, so construction may not touch the package.
        $parser = new PhpAstParser();
        $property = new \ReflectionProperty(PhpAstParser::class, 'parser');

        $this->assertNull($property->getValue($parser));

        $parser->parse(dirname(__DIR__, 3) . '/Fixtures/Refactoring/Ast/PaymentService.php', 'PaymentService.php');

        $this->assertNotNull($property->getValue($parser));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --no-coverage --filter 'MissingDependencyExceptionTest|PhpParserRequirementTest|test_construction_leaves_the_parser_to_the_first_parse'`
Expected: FAIL/ERROR. The two new test classes fail with `Class "Peralta\AgentKit\Exceptions\MissingDependencyException" not found` (or `PhpParserRequirement` not found), and the laziness test fails on `assertNull` because the constructor builds the parser.

- [ ] **Step 3: Implement**

Create `src/Exceptions/MissingDependencyException.php`:

```php
<?php

namespace Peralta\AgentKit\Exceptions;

use RuntimeException;

/**
 * A feature needs a Composer package that Agent Kit only suggests. The MCP server and the
 * Refactoring Agent's AST index are development tools, so the hint installs with --dev.
 */
final class MissingDependencyException extends RuntimeException
{
    public function __construct(
        public readonly string $package,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forFeature(string $feature, string $package): self
    {
        return new self($package, "{$feature} requires {$package}. Install it with: composer require --dev {$package}");
    }
}
```

Create `src/Refactoring/Analysis/Ast/PhpParserRequirement.php`:

```php
<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Ast;

use Peralta\AgentKit\Exceptions\MissingDependencyException;
use PhpParser\ParserFactory;

/**
 * nikic/php-parser is only suggested, so it may be missing, or be an older major version pulled in
 * by another tool. The factory class is a parameter only so tests can stand in for both cases.
 */
final class PhpParserRequirement
{
    public const PACKAGE = 'nikic/php-parser';

    public const FEATURE = 'The AST analysis (analyze, callers, dependencies, impact)';

    public static function assertSatisfied(string $factory = ParserFactory::class): void
    {
        if (!class_exists($factory)) {
            throw MissingDependencyException::forFeature(self::FEATURE, self::PACKAGE);
        }

        if (!method_exists($factory, 'createForNewestSupportedVersion')) {
            throw new MissingDependencyException(
                self::PACKAGE,
                self::FEATURE . ' requires nikic/php-parser 5.x, but an older major version is installed. '
                . 'Upgrade it with: composer require --dev "nikic/php-parser:^5.0"',
            );
        }
    }
}
```

In `src/Refactoring/Analysis/Ast/PhpAstParser.php`, replace the property, constructor and the start of `parse()`:

```php
final class PhpAstParser implements AstParser
{
    // Created on the first parse: resolving the refactoring services (the audit included) must
    // not need nikic/php-parser, which Agent Kit only suggests.
    private ?Parser $parser = null;

    public function __construct(private readonly array $facadePrefixes = ['Illuminate\\Support\\Facades\\']) {}

    public function parse(string $file, ?string $displayPath = null): ParsedFile
    {
        $parser = $this->parser();

        $code = file_get_contents($file);
        if ($code === false) {
            throw new \RuntimeException("Não foi possível ler {$file}.");
        }

        $path = $displayPath ?? $file;

        try {
            $nodes = $parser->parse($code) ?? [];
        } catch (Error $error) {
```

(the rest of `parse()` is unchanged), and add this private method at the end of the class:

```php
    private function parser(): Parser
    {
        if ($this->parser === null) {
            PhpParserRequirement::assertSatisfied();
            $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        }

        return $this->parser;
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --no-coverage --filter 'MissingDependencyExceptionTest|PhpParserRequirementTest|PhpAstParserTest'`
Expected: PASS.

Run: `vendor/bin/phpunit --no-coverage`
Expected: OK (only the 2 pre-existing skips of the `database` group, PHPUnit deprecations are pre-existing).

- [ ] **Step 5: Commit**

```bash
git checkout -- .phpunit.cache/test-results
git add src/Exceptions/MissingDependencyException.php src/Refactoring/Analysis/Ast/PhpParserRequirement.php src/Refactoring/Analysis/Ast/PhpAstParser.php tests/Unit/Exceptions/MissingDependencyExceptionTest.php tests/Unit/Refactoring/Ast/PhpParserRequirementTest.php tests/Unit/Refactoring/Ast/PhpAstParserTest.php
git commit -m "feat: create the AST parser lazily and check for nikic/php-parser 5.x

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Report a missing parser as `DEPENDENCY_MISSING`

**Files:**
- Modify: `src/Refactoring/Analysis/Index/CodebaseIndexer.php` (the per-file `try` in `build()`)
- Modify: `src/Refactoring/Application/DefaultRefactoringCapabilities.php` (four `$this->indexer->build($root)` calls, new private `index()`)
- Test: `tests/Unit/Refactoring/CodebaseIndexerTest.php`
- Test: `tests/Unit/Refactoring/Application/DefaultRefactoringCapabilitiesTest.php`
- Test: `tests/Feature/Refactoring/RefactoringCommandsTest.php`

**Interfaces:**
- Consumes: `MissingDependencyException` (`$package`, `forFeature()`), `PhpParserRequirement::FEATURE`, `PhpParserRequirement::PACKAGE` from Task 1.
- Produces: `CodebaseIndexer::build()` rethrows `MissingDependencyException`; `DefaultRefactoringCapabilities::analyze()`, `findCallers()`, `dependencies()` and `impact()` throw `CapabilityException` with `errorCode === 'DEPENDENCY_MISSING'` and the dependency's message.

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/Refactoring/CodebaseIndexerTest.php`, add the imports

```php
use Peralta\AgentKit\Exceptions\MissingDependencyException;
use Peralta\AgentKit\Refactoring\Analysis\Ast\PhpParserRequirement;
```

and this test method:

```php
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
```

In `tests/Unit/Refactoring/Application/DefaultRefactoringCapabilitiesTest.php`, add the imports

```php
use Peralta\AgentKit\Exceptions\MissingDependencyException;
use Peralta\AgentKit\Refactoring\Analysis\Ast\PhpParserRequirement;
```

these test methods (next to `test_it_returns_stable_errors`):

```php
    #[DataProvider('astOperations')]
    public function test_ast_operations_report_a_missing_parser_as_dependency_missing(string $operation): void
    {
        try {
            $this->service($this->missingParser())->{$operation}($this->root, 'Fixtures\\Payments\\PaymentService');
            $this->fail('Expected a capability exception.');
        } catch (CapabilityException $exception) {
            $this->assertSame('DEPENDENCY_MISSING', $exception->errorCode);
            $this->assertSame(
                'The AST analysis (analyze, callers, dependencies, impact) requires nikic/php-parser. Install it with: composer require --dev nikic/php-parser',
                $exception->getMessage(),
            );
        }
    }

    public static function astOperations(): array
    {
        return [
            'analyze' => ['analyze'],
            'find callers' => ['findCallers'],
            'dependencies' => ['dependencies'],
            'impact' => ['impact'],
        ];
    }

    public function test_audit_and_discovery_work_without_the_parser(): void
    {
        $service = $this->service($this->missingParser());

        $this->assertSame('capability_discovery', $service->describeCapabilities()->capability);
        $this->assertSame('audit', $service->audit($this->root)->capability);
    }
```

and this private helper next to `countingParser()`:

```php
    private function missingParser(): AstParser
    {
        return new class implements AstParser {
            public function parse(string $file, ?string $displayPath = null): ParsedFile
            {
                throw MissingDependencyException::forFeature(PhpParserRequirement::FEATURE, PhpParserRequirement::PACKAGE);
            }
        };
    }
```

In `tests/Feature/Refactoring/RefactoringCommandsTest.php`, add the imports

```php
use Peralta\AgentKit\Exceptions\MissingDependencyException;
use Peralta\AgentKit\Refactoring\Analysis\Ast\PhpParserRequirement;
```

and this test method after `test_json_failure_uses_the_exact_stable_error_envelope`:

```php
    public function test_json_reports_a_missing_parser_with_the_dependency_missing_envelope(): void
    {
        $this->app->bind(AstParser::class, fn () => new class implements AstParser {
            public function parse(string $file, ?string $displayPath = null): ParsedFile
            {
                throw MissingDependencyException::forFeature(PhpParserRequirement::FEATURE, PhpParserRequirement::PACKAGE);
            }
        });

        $status = Artisan::call('agent-kit:refactor-analyze', [
            'file' => 'Fixtures\\Payments\\PaymentService',
            '--path' => $this->fixtureRoot(),
            '--json' => true,
        ]);

        self::assertSame(1, $status);
        self::assertSame([
            'schema_version' => '1.0',
            'error' => [
                'code' => 'DEPENDENCY_MISSING',
                'message' => 'The AST analysis (analyze, callers, dependencies, impact) requires nikic/php-parser. Install it with: composer require --dev nikic/php-parser',
            ],
        ], json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --no-coverage --filter 'test_a_missing_dependency_aborts_the_index|test_ast_operations_report_a_missing_parser|test_audit_and_discovery_work_without_the_parser|test_json_reports_a_missing_parser'`
Expected: FAIL. The indexer test fails with "Expected the missing dependency to abort the index." (the exception became a diagnostic); the capability and CLI tests get `TARGET_NOT_FOUND` instead of `DEPENDENCY_MISSING`. `test_audit_and_discovery_work_without_the_parser` already passes; it guards the behaviour.

- [ ] **Step 3: Implement**

In `src/Refactoring/Analysis/Index/CodebaseIndexer.php`, add `use Peralta\AgentKit\Exceptions\MissingDependencyException;` and a first `catch` clause in the per-file `try` of `build()`:

```php
            try {
                $parsed = $this->parser->parse($file, $relative);
            } catch (MissingDependencyException $missing) {
                // A missing package breaks every file the same way: report it once, not per file.
                throw $missing;
            } catch (\Throwable $failure) {
```

(the `\Throwable` branch is unchanged).

In `src/Refactoring/Application/DefaultRefactoringCapabilities.php`, add `use Peralta\AgentKit\Exceptions\MissingDependencyException;`, replace each of the four `$index = $this->indexer->build($root);` lines (in `analyze`, `findCallers`, `dependencies`, `impact`) with `$index = $this->index($root);`, and add this private method right before `projectRoot()`:

```php
    private function index(string $root): CodebaseIndex
    {
        try {
            return $this->indexer->build($root);
        } catch (MissingDependencyException $exception) {
            throw new CapabilityException('DEPENDENCY_MISSING', $exception->getMessage());
        }
    }
```

`CodebaseIndex` is already imported in that file.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --no-coverage --filter 'CodebaseIndexerTest|DefaultRefactoringCapabilitiesTest|RefactoringCommandsTest'`
Expected: PASS.

Run: `grep -n 'indexer->build' src/Refactoring/Application/DefaultRefactoringCapabilities.php`
Expected: exactly one match, inside `index()`.

Run: `vendor/bin/phpunit --no-coverage`
Expected: OK (2 pre-existing skips).

- [ ] **Step 5: Commit**

```bash
git checkout -- .phpunit.cache/test-results
git add src/Refactoring/Analysis/Index/CodebaseIndexer.php src/Refactoring/Application/DefaultRefactoringCapabilities.php tests/Unit/Refactoring/CodebaseIndexerTest.php tests/Unit/Refactoring/Application/DefaultRefactoringCapabilitiesTest.php tests/Feature/Refactoring/RefactoringCommandsTest.php
git commit -m "feat: report a missing nikic/php-parser as DEPENDENCY_MISSING

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: MCP SDK check and a suite without the optional packages

**Files:**
- Modify: `src/Refactoring/Mcp/Commands/McpServeCommand.php`
- Test: `tests/Feature/WithoutOptionalPackagesTest.php` (create)

**Interfaces:**
- Consumes: `MissingDependencyException::forFeature()` (Task 1); the `DEPENDENCY_MISSING` behaviour of the AST commands (Task 2).
- Produces: `php artisan agent-kit:mcp` exits `1` with `The MCP server requires mcp/sdk. Install it with: composer require --dev mcp/sdk` when `Mcp\Server` cannot be loaded, for every transport.

**How the test works:** the optional packages cannot be uninstalled in this repository (PHPUnit itself depends on nikic/php-parser), so every test of the class runs in its own PHP process (`#[RunTestsInSeparateProcesses]`). Before the Testbench application boots, `setUp()` unregisters Composer's `ClassLoader` and registers a wrapper that delegates to it except for the namespaces of the optional packages. For those classes `class_exists()` then answers `false`, as if the packages were not installed. The test first asserts that none of those classes is loaded yet; a class loaded earlier could not be hidden and the test would pass by accident. The hidden namespaces are every package that `mcp/sdk` and `nikic/php-parser` bring and a production install no longer gets (checked against `composer.lock`), plus `React\` for the optional `react/http`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/WithoutOptionalPackagesTest.php`:

```php
<?php

namespace Peralta\AgentKit\Tests\Feature;

use Composer\Autoload\ClassLoader;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Peralta\AgentKit\Agent;
use Peralta\AgentKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * An application that installs Agent Kit for its agent core gets none of the suggested packages
 * (issue #11). They cannot be uninstalled here, since PHPUnit itself needs nikic/php-parser, so
 * each test runs in a fresh process whose Composer autoloader refuses their namespaces before
 * the application boots: class_exists() then answers false exactly as if they were missing.
 */
#[RunTestsInSeparateProcesses]
final class WithoutOptionalPackagesTest extends TestCase
{
    /** mcp/sdk and what it pulls in, nikic/php-parser, and the optional react/http. */
    private const HIDDEN_NAMESPACES = [
        'Mcp\\',
        'Opis\\',
        'Http\\Discovery\\',
        'Psr\\Http\\Server\\',
        'phpDocumentor\\',
        'PHPStan\\PhpDocParser\\',
        'Webmozart\\Assert\\',
        'Doctrine\\Deprecations\\',
        'PhpParser\\',
        'React\\',
    ];

    private const AST_MESSAGE = 'The AST analysis (analyze, callers, dependencies, impact) requires nikic/php-parser. Install it with: composer require --dev nikic/php-parser';

    protected function setUp(): void
    {
        self::hideOptionalPackages();

        parent::setUp();
    }

    public function test_an_agent_sends_through_a_real_provider(): void
    {
        $this->app['config']->set('agent-kit.providers.deepseek', [
            'driver' => 'deepseek',
            'api_key' => 'test-key',
            'base_url' => 'http://api.test',
            'model' => 'deepseek-chat',
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, [], json_encode([
                    'choices' => [['message' => ['role' => 'assistant', 'content' => '["b","a"]'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2],
                ])),
            ])),
        ]);

        $response = $this->app->make(Agent::class)
            ->provider('deepseek')
            ->system('Reorder the ids.')
            ->options(['response_format' => ['type' => 'json_object'], 'timeout' => 5])
            ->send('["a","b"]');

        self::assertSame('["b","a"]', $response->text());
    }

    public function test_artisan_lists_every_agent_kit_command(): void
    {
        self::assertSame(0, Artisan::call('list'));
        $output = Artisan::output();

        foreach ([
            'agent-kit:mcp',
            'agent-kit:refactor-capabilities',
            'agent-kit:refactor-audit',
            'agent-kit:refactor-analyze',
            'agent-kit:refactor-callers',
            'agent-kit:refactor-dependencies',
            'agent-kit:refactor-impact',
        ] as $command) {
            self::assertStringContainsString($command, $output);
        }
    }

    public function test_capability_discovery_and_the_audit_need_no_parser(): void
    {
        self::assertSame(0, Artisan::call('agent-kit:refactor-capabilities', ['--json' => true]));
        self::assertSame('capability_discovery', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['capability']);

        self::assertSame(0, Artisan::call('agent-kit:refactor-audit', ['path' => $this->fixtureRoot(), '--json' => true]));
        self::assertSame('audit', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['capability']);
    }

    /** @param array<string, string> $arguments */
    #[DataProvider('astCommands')]
    public function test_ast_commands_name_the_package_to_install(string $command, array $arguments): void
    {
        $status = Artisan::call($command, [...$arguments, '--path' => $this->fixtureRoot(), '--json' => true]);

        self::assertSame(1, $status);
        self::assertSame([
            'schema_version' => '1.0',
            'error' => ['code' => 'DEPENDENCY_MISSING', 'message' => self::AST_MESSAGE],
        ], json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
    }

    public static function astCommands(): array
    {
        $target = 'Fixtures\\Payments\\PaymentService';

        return [
            'analyze' => ['agent-kit:refactor-analyze', ['file' => $target]],
            'callers' => ['agent-kit:refactor-callers', ['class' => $target]],
            'dependencies' => ['agent-kit:refactor-dependencies', ['class' => $target]],
            'impact' => ['agent-kit:refactor-impact', ['class' => $target]],
        ];
    }

    #[DataProvider('transports')]
    public function test_the_mcp_server_names_the_package_to_install(string $transport): void
    {
        $status = Artisan::call('agent-kit:mcp', ['--transport' => $transport, '--path' => $this->fixtureRoot()]);

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'The MCP server requires mcp/sdk. Install it with: composer require --dev mcp/sdk',
            Artisan::output(),
        );
    }

    public static function transports(): array
    {
        return ['stdio' => ['stdio'], 'http' => ['http']];
    }

    private function fixtureRoot(): string
    {
        return dirname(__DIR__) . '/Fixtures/Refactoring/Ast';
    }

    private static function hideOptionalPackages(): void
    {
        $loaded = array_values(array_filter(
            [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()],
            static fn (string $class): bool => self::hidden($class),
        ));
        self::assertSame([], $loaded, 'Optional packages were loaded before they could be hidden.');

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->unregister();
            spl_autoload_register(static function (string $class) use ($loader): void {
                if (!self::hidden($class)) {
                    $loader->loadClass($class);
                }
            }, true, true);
        }

        self::assertFalse(class_exists(\Mcp\Server::class));
        self::assertFalse(class_exists(\PhpParser\ParserFactory::class));
    }

    private static function hidden(string $class): bool
    {
        foreach (self::HIDDEN_NAMESPACES as $namespace) {
            if (str_starts_with(ltrim($class, '\\'), $namespace)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --no-coverage tests/Feature/WithoutOptionalPackagesTest.php`
Expected: the agent, `list`, discovery/audit and AST-command tests PASS (Tasks 1–2 already made them work); both `test_the_mcp_server_names_the_package_to_install` cases FAIL, with an `Error: Class "Mcp\..." not found` or a different message in the output.

If the agent, `list` or audit test fails instead because Laravel or Testbench itself needs one of the hidden namespaces (the trace shows a vendor class outside `peralta/agent-kit` asking for it), remove only that namespace from `HIDDEN_NAMESPACES` and add a comment naming the package that needs it. Never remove `Mcp\`, `PhpParser\` or `React\`. If the precondition assertion "Optional packages were loaded before they could be hidden." fails, stop and report: the test cannot prove anything in that case.

- [ ] **Step 3: Implement**

In `src/Refactoring/Mcp/Commands/McpServeCommand.php`, add the imports

```php
use Mcp\Server;
use Peralta\AgentKit\Exceptions\MissingDependencyException;
```

(`Server::class` is resolved at compile time and does not autoload anything), make the SDK check the first statement of the `try` block in `handle()`, and catch the new exception with the existing one:

```php
        try {
            // mcp/sdk is only suggested: say what to install instead of failing deep inside the SDK.
            if (!class_exists(Server::class)) {
                throw MissingDependencyException::forFeature('The MCP server', 'mcp/sdk');
            }

            $root = McpProjectRoot::fromPath((string) ($this->option('path') ?: ($config['project_root'] ?: base_path())));
            $logger = $loggers->create((array) ($config['logging'] ?? []));

            return match ($transport) {
                'stdio' => $this->serveStdio($stdio, $root, $logger),
                'http' => $http->listen($this->httpOptions((array) ($config['http'] ?? [])), $root, $logger),
                default => $this->refuse("Unsupported MCP transport: {$transport}. Use stdio or http."),
            };
        } catch (McpConfigurationException|MissingDependencyException $exception) {
            return $this->refuse($exception->getMessage());
        }
```

Nothing else in the command changes.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --no-coverage tests/Feature/WithoutOptionalPackagesTest.php`
Expected: PASS, 9 tests (agent, list, discovery/audit, 4 AST command data sets, 2 transport data sets) with no failures, errors or skips.

Run: `vendor/bin/phpunit --no-coverage tests/Feature/Mcp`
Expected: PASS (the real SDK is installed, so the MCP server still starts).

Run: `vendor/bin/phpunit --no-coverage`
Expected: OK (2 pre-existing skips).

- [ ] **Step 5: Commit**

```bash
git checkout -- .phpunit.cache/test-results
git add src/Refactoring/Mcp/Commands/McpServeCommand.php tests/Feature/WithoutOptionalPackagesTest.php
git commit -m "feat: name mcp/sdk when the MCP server cannot load it

Prove the agent core, artisan list, the audit and both install hints
work with the optional packages hidden from Composer's autoloader.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Composer manifest and lock

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock` (regenerated by Composer, never by hand)
- Test: `tests/Unit/ComposerManifestTest.php` (create)

**Interfaces:**
- Consumes: nothing from earlier tasks at the code level; the runtime checks from Tasks 1–3 are what make this change safe.
- Produces: `composer.json` whose `require` has only `php`, the four `illuminate/*` packages and `guzzlehttp/guzzle`; `mcp/sdk` and `nikic/php-parser` in `require-dev` and `suggest`; `conflict` on `mcp/sdk`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ComposerManifestTest.php`:

```php
<?php

namespace Peralta\AgentKit\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The agent core must not drag the MCP server's or the AST index's packages into apps (#11). */
final class ComposerManifestTest extends TestCase
{
    private const OPTIONAL = ['mcp/sdk', 'nikic/php-parser'];

    public function test_the_mcp_and_ast_packages_are_only_suggested(): void
    {
        $manifest = $this->json('composer.json');

        foreach ([...self::OPTIONAL, 'ext-fileinfo'] as $package) {
            self::assertArrayNotHasKey($package, $manifest['require'], "{$package} must not be required.");
        }
        foreach (self::OPTIONAL as $package) {
            self::assertArrayHasKey($package, $manifest['require-dev'], "{$package} is still needed by the package's own tests.");
            self::assertArrayHasKey($package, $manifest['suggest'], "{$package} must be suggested.");
        }
        self::assertSame('<0.8.1 || >=0.9', $manifest['conflict']['mcp/sdk'] ?? null);
        self::assertArrayNotHasKey('nikic/php-parser', $manifest['conflict'], 'An old php-parser elsewhere must not block the agent core.');
    }

    public function test_a_production_install_gets_none_of_them(): void
    {
        $lock = $this->json('composer.lock');
        $production = array_column($lock['packages'], 'name');

        foreach ([
            'mcp/sdk',
            'nikic/php-parser',
            'opis/json-schema',
            'opis/string',
            'opis/uri',
            'php-http/discovery',
            'psr/http-server-handler',
            'psr/http-server-middleware',
            'react/http',
        ] as $package) {
            self::assertNotContains($package, $production, "{$package} would be installed with --no-dev.");
        }
        self::assertArrayNotHasKey('ext-fileinfo', $lock['platform']);
    }

    private function json(string $file): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/' . $file), true, flags: JSON_THROW_ON_ERROR);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --no-coverage tests/Unit/ComposerManifestTest.php`
Expected: FAIL on `mcp/sdk must not be required.` and `mcp/sdk would be installed with --no-dev.`

- [ ] **Step 3: Edit `composer.json` by hand**

Keep the file's existing formatting and key order. The four changed blocks must read exactly:

```json
    "require": {
        "php": "^8.2",
        "illuminate/contracts": "^10.0|^11.0|^12.0",
        "illuminate/support": "^10.0|^11.0|^12.0",
        "illuminate/database": "^10.0|^11.0|^12.0",
        "illuminate/http": "^10.0|^11.0|^12.0",
        "guzzlehttp/guzzle": "^7.0"
    },
    "conflict": {
        "mcp/sdk": "<0.8.1 || >=0.9"
    },
```

(`conflict` goes right after `require`, before `config`),

```json
    "require-dev": {
        "mcp/sdk": "^0.8.1",
        "nikic/php-parser": "^5.8",
        "orchestra/testbench": "^8.0|^9.0|^10.0",
        "phpunit/phpunit": "^10.0|^11.0",
        "pgvector/pgvector": "^0.2",
        "react/http": "^1.11"
    },
    "suggest": {
        "mcp/sdk": "Required by the MCP server (php artisan agent-kit:mcp), ^0.8.1; install it with --dev",
        "nikic/php-parser": "Required by the Refactoring Agent's AST capabilities (analyze, callers, dependencies, impact), ^5.0; install it with --dev",
        "pgvector/pgvector": "Required for pgvector knowledge store",
        "smalot/pdfparser": "For extracting text from PDF documents",
        "phpoffice/phpword": "For extracting text from DOCX documents",
        "react/http": "Required to serve the MCP server over Streamable HTTP (php artisan agent-kit:mcp --transport=http)"
    },
```

`config.allow-plugins` stays as it is.

- [ ] **Step 4: Regenerate the lock without changing any version**

`composer update --lock` does not move packages between `packages` and `packages-dev`, and the locked `league/commonmark` 2.9.0 has security advisories that make Composer 2.9 refuse to resolve. A partial update pinned to the locked versions, with security blocking off for this one command, moves them and changes nothing else:

```bash
cp composer.lock "$CLAUDE_JOB_DIR/tmp/composer.lock.before"
COMPOSER_NO_SECURITY_BLOCKING=1 composer update mcp/sdk nikic/php-parser --with mcp/sdk:0.8.1 --with nikic/php-parser:5.8.0 --no-interaction
```

(`CLAUDE_JOB_DIR` is set in this session; if it is empty, use any scratch directory outside the repository.)

Verify that only sections changed, never versions:

```bash
php -r '
$sections = function (string $file): array {
    $lock = json_decode(file_get_contents($file), true);
    $out = [];
    foreach (["packages", "packages-dev"] as $section) {
        foreach ($lock[$section] as $package) { $out[$package["name"]] = [$section, $package["version"]]; }
    }
    ksort($out);
    return $out;
};
$before = $sections(getenv("CLAUDE_JOB_DIR") . "/tmp/composer.lock.before");
$after = $sections("composer.lock");
foreach ($before + $after as $name => $_) {
    $a = $before[$name] ?? null; $b = $after[$name] ?? null;
    if ($a === $b) continue;
    if ($a === null || $b === null || $a[1] !== $b[1]) { echo "VERSION CHANGE: $name\n"; continue; }
    echo "$name: $a[0] -> $b[0]\n";
}'
```

Expected output, exactly these 14 lines and no `VERSION CHANGE`:

```text
doctrine/deprecations: packages -> packages-dev
mcp/sdk: packages -> packages-dev
nikic/php-parser: packages -> packages-dev
opis/json-schema: packages -> packages-dev
opis/string: packages -> packages-dev
opis/uri: packages -> packages-dev
php-http/discovery: packages -> packages-dev
phpdocumentor/reflection-common: packages -> packages-dev
phpdocumentor/reflection-docblock: packages -> packages-dev
phpdocumentor/type-resolver: packages -> packages-dev
phpstan/phpdoc-parser: packages -> packages-dev
psr/http-server-handler: packages -> packages-dev
psr/http-server-middleware: packages -> packages-dev
webmozart/assert: packages -> packages-dev
```

Run: `composer validate --no-check-publish`
Expected: `./composer.json is valid` (warnings about the lock being out of date are a failure).

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --no-coverage tests/Unit/ComposerManifestTest.php`
Expected: PASS.

Run: `vendor/bin/phpunit --no-coverage`
Expected: OK (2 pre-existing skips).

- [ ] **Step 6: Commit**

```bash
git checkout -- .phpunit.cache/test-results
git add composer.json composer.lock tests/Unit/ComposerManifestTest.php
git commit -m "build: make mcp/sdk and nikic/php-parser optional

Move both to require-dev and suggest, drop ext-fileinfo (only mcp/sdk
needed it) and keep MCP users on the tested SDK minor with a conflict
rule. A --no-dev install no longer gets the SDK, its php-http/discovery
Composer plugin or php-parser. Closes #11.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Documentation and changelog

**Files:**
- Modify: `README.md` (sections "Instalação", "Atualizando", "Refactoring Agent", "Servidor MCP")
- Modify: `SETUP.md` ("Passo 1")
- Modify: `MCP_SERVER.md` ("Prerequisites", error codes under "Result envelope", "Troubleshooting", "Upgrading the SDK")
- Modify: `REFACTORING_AGENT.md` ("Commands", "AST structural analysis")
- Modify: `ARCHITECTURE.md` (Refactoring Agent section)
- Modify: `CHANGELOG.md` (`[Não Lançado]`)

**Interfaces:**
- Consumes: the exact messages, error code and Composer rules from Tasks 1–4.
- Produces: documentation only. `tests/Feature/Mcp/DocumentationTest.php` and `tests/Feature/Refactoring/InstallAgentsCommandTest.php` read these files and must keep passing; do not rename headings.

- [ ] **Step 1: `README.md`**

In "## Instalação", insert this paragraph right after the install code block (before "A tag `agent-kit-migrations` publica..."):

```markdown
Isso basta para o núcleo de agentes: providers, tools, conversas e RAG. O Refactoring Agent
e o servidor MCP usam pacotes opcionais, instalados à parte e de preferência só em
desenvolvimento; veja [Refactoring Agent](#refactoring-agent) e [Servidor MCP](#servidor-mcp).
```

In "## Atualizando", insert this block after the bullet list that ends with "...copie o bloco `database` de `knowledge.stores` do config do pacote para o seu." and before "Vindo da v0.2.0:":

````markdown
Vindo da v0.3.x ou anterior: `mcp/sdk` e `nikic/php-parser` deixaram de ser dependências
obrigatórias. Com eles saem da sua aplicação o plugin do Composer `php-http/discovery`, as
dependências do SDK e a exigência de `ext-fileinfo`. Quem usa só o núcleo de agentes não
precisa fazer nada. Quem usa o servidor MCP (`agent-kit:mcp`) ou os comandos de AST do
Refactoring Agent (`refactor-analyze`, `-callers`, `-dependencies` e `-impact`) instala os
dois depois de atualizar:

```bash
composer require --dev mcp/sdk nikic/php-parser
```

Sem eles, o `agent-kit:mcp` sai com `The MCP server requires mcp/sdk` e os comandos de AST
respondem com o código de erro `DEPENDENCY_MISSING`, sempre com o comando de instalação.
````

In "## Refactoring Agent", insert after the paragraph that ends "Todos os comandos são somente de análise: nenhum deles modifica o código examinado.":

````markdown
`refactor-capabilities` e `refactor-audit` funcionam com a instalação padrão. Analyze,
callers, dependencies e impact montam o índice AST e precisam do `nikic/php-parser` 5.x,
que o pacote só sugere. Em desenvolvimento ele costuma já estar presente por causa do
PHPUnit; se não estiver:

```bash
composer require --dev nikic/php-parser
```

Sem ele, esses comandos respondem com o código de erro `DEPENDENCY_MISSING` e o comando de
instalação.
````

In "## Servidor MCP", insert after the existing `agent-kit:mcp` code block and before the paragraph "Configuração em `config/agent-kit.php` (`mcp`) ...":

````markdown
O servidor usa pacotes que a instalação padrão não traz. Instale-os em desenvolvimento
antes do primeiro uso:

```bash
composer require --dev mcp/sdk nikic/php-parser
```
````

- [ ] **Step 2: `SETUP.md`**

In "## 📋 Passo 1: Instalar o pacote", insert after the "> MySQL/MariaDB: ..." note and before "Alternativa mais curta para um checkout local do pacote:":

```markdown
> Refactoring Agent e servidor MCP: os comandos de AST (`refactor-analyze`, `-callers`,
> `-dependencies` e `-impact`) e o `agent-kit:mcp` usam pacotes opcionais. Instale-os só em
> desenvolvimento com `composer require --dev mcp/sdk nikic/php-parser`; veja
> [MCP_SERVER.md](MCP_SERVER.md).
```

- [ ] **Step 3: `MCP_SERVER.md`**

Replace the whole "## Prerequisites" list with:

```markdown
- PHP 8.2+; Laravel 10, 11 or 12 with Agent Kit installed.
- `mcp/sdk` and `nikic/php-parser`, which Agent Kit only suggests. Install them
  in development: `composer require --dev mcp/sdk nikic/php-parser`. The SDK
  needs `ext-fileinfo`, and Agent Kit's `conflict` rule keeps it on `^0.8.1`
  (see [Upgrading the SDK](#upgrading-the-sdk)).
- The SDK brings the `php-http/discovery` Composer plugin. Agent Kit passes its
  PSR-17 factories explicitly and does not need it; to keep it from running,
  run `composer config allow-plugins.php-http/discovery false` before the
  `require` (the default Laravel skeleton allows it).
- Streamable HTTP additionally needs `react/http`:
  `composer require react/http`.
```

Replace the error code sentence under "### Result envelope":

```markdown
Error codes: `INVALID_TARGET`, `TARGET_NOT_FOUND`, `AMBIGUOUS_TARGET`,
`UNSUPPORTED_TARGET`, `TARGET_OUTSIDE_PROJECT`, `PROJECT_ROOT_NOT_FOUND`, and
`DEPENDENCY_MISSING` (the analyze, callers, dependencies and impact tools need
`nikic/php-parser`; the message says how to install it).
```

In "Troubleshooting", add these two bullets right before "- *`requires react/http`*: `composer require react/http`.":

```markdown
- *`The MCP server requires mcp/sdk`*: `composer require --dev mcp/sdk nikic/php-parser`.
- *`DEPENDENCY_MISSING` from a tool*: `composer require --dev nikic/php-parser`.
```

Replace the first sentence of "## Upgrading the SDK" ("`mcp/sdk` is pre-1.0 and its minor releases contain breaking changes; the package pins `^0.8.1` (`>=0.8.1 <0.9.0`).") with:

```markdown
`mcp/sdk` is pre-1.0 and its minor releases contain breaking changes. Agent Kit
tests against `^0.8.1` (`require-dev`) and, because applications install the
SDK themselves, holds them to the same range with
`"conflict": {"mcp/sdk": "<0.8.1 || >=0.9"}`; move both constraints together.
```

(the rest of that section, from "Before moving to a new minor, ..." on, is unchanged).

- [ ] **Step 4: `REFACTORING_AGENT.md`**

In "## Commands", insert after the paragraph "`refactor-capabilities` lists every capability with its MCP tool name and CLI fallback; the generated skills call it first.":

````markdown
`refactor-capabilities` and `refactor-audit` work with a default install.
`refactor-analyze`, `refactor-callers`, `refactor-dependencies` and
`refactor-impact` build the AST index and need `nikic/php-parser` 5.x, which
Agent Kit only suggests. PHPUnit usually brings it into development already;
otherwise run `composer require --dev nikic/php-parser`. Without it those
commands fail with the `DEPENDENCY_MISSING` error code:

```json
{"schema_version": "1.0", "error": {"code": "DEPENDENCY_MISSING", "message": "The AST analysis (analyze, callers, dependencies, impact) requires nikic/php-parser. Install it with: composer require --dev nikic/php-parser"}}
```
````

In "## AST structural analysis", replace "The structural analyzer uses `nikic/php-parser` rather than regular expressions." with "The structural analyzer uses `nikic/php-parser` (optional, see [Commands](#commands)) rather than regular expressions."

- [ ] **Step 5: `ARCHITECTURE.md`**

Replace "memória (`nikic/php-parser`) e responde consultas determinísticas de dependência," with "memória (`nikic/php-parser`, dependência opcional) e responde consultas determinísticas de dependência,".

- [ ] **Step 6: `CHANGELOG.md`**

In `## [Não Lançado]`, append this bullet at the end of the existing `### Alterado` list:

```markdown
- **BREAKING** para quem usa o servidor MCP ou os comandos de AST do Refactoring Agent: `mcp/sdk` e `nikic/php-parser` passaram de `require` para `suggest` (#11). Instalar o pacote só pelo núcleo de agentes deixa de trazer o SDK e suas dependências, entre elas o plugin do Composer `php-http/discovery`, e de impor uma versão do `nikic/php-parser`. Quem usa `agent-kit:mcp` ou `refactor-analyze`, `-callers`, `-dependencies` e `-impact` deve rodar `composer require --dev mcp/sdk nikic/php-parser` ao atualizar. Sem eles, o `agent-kit:mcp` sai com código 1 e o comando de instalação, e os comandos de AST e as tools MCP correspondentes respondem com o novo código de erro `DEPENDENCY_MISSING`; `refactor-audit` e `refactor-capabilities` continuam funcionando. O `nikic/php-parser` aceito passa a ser qualquer 5.x (antes `^5.8`), e o `mcp/sdk` continua limitado a `^0.8.1` por uma regra `conflict`.
```

and add a new section right after that `### Alterado` list (still inside `[Não Lançado]`):

```markdown
### Removido
- Exigência da extensão `ext-fileinfo`, que só existia por causa do `mcp/sdk`; o SDK continua exigindo-a de quem o instala (#11).
```

- [ ] **Step 7: Verify**

Run: `vendor/bin/phpunit --no-coverage tests/Feature/Mcp/DocumentationTest.php tests/Feature/Refactoring/InstallAgentsCommandTest.php`
Expected: PASS.

Run: `grep -rn "ext-fileinfo\|installed with the package" README.md SETUP.md MCP_SERVER.md REFACTORING_AGENT.md ARCHITECTURE.md`
Expected: only the new `MCP_SERVER.md` prerequisite line ("The SDK needs `ext-fileinfo`") and the README "Atualizando" paragraph.

Run: `vendor/bin/phpunit --no-coverage`
Expected: OK (2 pre-existing skips).

- [ ] **Step 8: Commit**

```bash
git checkout -- .phpunit.cache/test-results
git add README.md SETUP.md MCP_SERVER.md REFACTORING_AGENT.md ARCHITECTURE.md CHANGELOG.md
git commit -m "docs: document the optional MCP and AST packages

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Final verification (controller, after all tasks)

- [ ] Full suite: `vendor/bin/phpunit --no-coverage` → OK.
- [ ] Database group, if a local server is available: `AGENT_KIT_TEST_DB_CONNECTION=... vendor/bin/phpunit --no-coverage --group database` (not affected by this change; CI covers it).
- [ ] Consumer smoke test in a scratch directory outside the repository: a fresh Laravel skeleton that requires this worktree through a `path` repository with `--no-dev` semantics checked via `composer show`. Record which of `mcp/sdk`, `opis/*`, `php-http/discovery`, `psr/http-server-*` and `nikic/php-parser` get installed (the skeleton's own `laravel/tinker` → `psy/psysh` brings php-parser regardless; note it), that Agent Kit no longer contributes `ext-fileinfo` (`composer check-platform-reqs`), `php artisan list | grep agent-kit`, and `php artisan agent-kit:mcp` (expect the install hint and exit code 1). The agent call itself is covered by `WithoutOptionalPackagesTest`.
- [ ] `git status` clean except `.phpunit.cache/test-results` restored; push the branch and open one PR against `main` with `Closes #11`, the evidence above and the attribution line.
