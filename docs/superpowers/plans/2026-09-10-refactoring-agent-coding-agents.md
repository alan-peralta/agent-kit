# Refactoring Agent Coding-Agent Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose the existing deterministic refactoring engine through one application capability API, thin Artisan commands, and safely installable Cursor and Claude Code skills.

**Architecture:** Add a transport-independent `RefactoringCapabilities` boundary over the existing scanner, AST index, graph, callers, impact, and audit services. Artisan, generated Agent Skills, and a future MCP adapter consume this boundary; Cursor and Claude files are rendered from canonical resources through platform adapters and installed with conflict protection.

**Tech Stack:** PHP 8.2+, Laravel/Illuminate Console 10–12, nikic/php-parser 5.8, Orchestra Testbench, PHPUnit 10–11, Markdown/MDC Agent Skills.

---

## File map

### Application capability layer

- Create `src/Refactoring/Application/RefactoringCapabilities.php` — stable transport-independent API.
- Create `src/Refactoring/Application/DefaultRefactoringCapabilities.php` — orchestration over existing deterministic services.
- Create `src/Refactoring/Application/CapabilityResult.php` — versioned success envelope.
- Create `src/Refactoring/Application/CapabilityException.php` — stable error code plus message.
- Create `src/Refactoring/Application/RefactoringTarget.php` — parses `Class::method` without guessing.
- Modify `src/Refactoring/Analysis/Index/CodebaseIndex.php` — retain unresolved references.
- Modify `src/Refactoring/Analysis/Index/CodebaseIndexer.php` — pass unresolved references into the index.
- Modify `src/Refactoring/Analysis/CallerAnalyzer.php` and `src/Refactoring/Analysis/DTOs/CallerResult.php` — include transitive and unresolved data.
- Modify `src/Refactoring/Analysis/ImpactAnalyzer.php` and `src/Refactoring/Analysis/DTOs/ImpactResult.php` — support optional method scope.

### Console adapters

- Create `src/Refactoring/Commands/RefactorCapabilitiesCommand.php` — capability discovery.
- Modify all five existing `src/Refactoring/Commands/Refactor*Command.php` files — delegate to the application API and emit versioned JSON.
- Modify `src/AgentKitServiceProvider.php` — bind the application API and register new commands/services.

### Agent resources and generation

- Create `resources/agents/refactoring/instructions.md` and shared rule files.
- Create six canonical files in `resources/agents/refactoring/commands/`.
- Create `src/Refactoring/Agents/AgentCommandRepository.php` — canonical resource loading and composition.
- Create `src/Refactoring/Agents/AgentTemplateRenderer.php` — strict placeholder replacement.
- Create `src/Refactoring/Agents/AgentAdapter.php` and `GeneratedAgentFile.php` — adapter contract and generated artifact DTO.
- Create `src/Refactoring/Agents/CursorAgentAdapter.php` and `ClaudeCodeAgentAdapter.php` — native file layouts.
- Create `src/Refactoring/Agents/AgentAdapterRegistry.php` — adapter lookup.
- Create `src/Refactoring/Agents/AgentConfigurationInstaller.php` and `InstallationResult.php` — safe, atomic installation.
- Create `src/Refactoring/Commands/InstallAgentsCommand.php` — user-facing installer.

### Tests, fixtures, and documentation

- Create unit tests under `tests/Unit/Refactoring/Application/` and `tests/Unit/Refactoring/Agents/`.
- Extend `tests/Feature/Refactoring/RefactoringCommandsTest.php`.
- Create `tests/Feature/Refactoring/InstallAgentsCommandTest.php`.
- Create golden files under `tests/Fixtures/Refactoring/Agents/Expected/`.
- Modify `README.md` and `REFACTORING_AGENT.md`.

## Task 1: Add the versioned capability contract and target value object

**Files:**

- Create: `src/Refactoring/Application/CapabilityResult.php`
- Create: `src/Refactoring/Application/CapabilityException.php`
- Create: `src/Refactoring/Application/RefactoringTarget.php`
- Create: `src/Refactoring/Application/RefactoringCapabilities.php`
- Test: `tests/Unit/Refactoring/Application/CapabilityResultTest.php`
- Test: `tests/Unit/Refactoring/Application/RefactoringTargetTest.php`

- [ ] **Step 1: Write failing envelope and target parsing tests**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Application;

use Peralta\AgentKit\Refactoring\Application\CapabilityResult;
use PHPUnit\Framework\TestCase;

final class CapabilityResultTest extends TestCase
{
    public function test_it_serializes_a_stable_success_envelope(): void
    {
        $result = new CapabilityResult('impact', ['target' => 'App\\Service'], [['file' => 'Broken.php']], [['type' => 'dynamic']]);

        $this->assertSame([
            'schema_version' => '1.0',
            'capability' => 'impact',
            'incomplete' => true,
            'data' => ['target' => 'App\\Service'],
            'diagnostics' => [['file' => 'Broken.php']],
            'unresolved' => [['type' => 'dynamic']],
        ], $result->toArray());
    }
}
```

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Application;

use InvalidArgumentException;
use Peralta\AgentKit\Refactoring\Application\RefactoringTarget;
use PHPUnit\Framework\TestCase;

final class RefactoringTargetTest extends TestCase
{
    public function test_it_parses_class_and_optional_method(): void
    {
        $class = RefactoringTarget::parse('\\App\\Services\\PaymentService');
        $method = RefactoringTarget::parse('App\\Services\\PaymentService::charge');

        $this->assertSame('App\\Services\\PaymentService', $class->value);
        $this->assertNull($class->method);
        $this->assertSame('App\\Services\\PaymentService', $method->value);
        $this->assertSame('charge', $method->method);
    }

    public function test_it_rejects_an_empty_or_malformed_target(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RefactoringTarget::parse('App\\Service::');
    }
}
```

- [ ] **Step 2: Run the tests and verify RED**

Run:

```bash
vendor/bin/phpunit tests/Unit/Refactoring/Application/CapabilityResultTest.php tests/Unit/Refactoring/Application/RefactoringTargetTest.php
```

Expected: FAIL because the application classes do not exist.

- [ ] **Step 3: Implement the result, exception, target, and interface**

```php
<?php

namespace Peralta\AgentKit\Refactoring\Application;

final readonly class CapabilityResult
{
    public const SCHEMA_VERSION = '1.0';

    public function __construct(
        public string $capability,
        public array $data,
        public array $diagnostics = [],
        public array $unresolved = [],
    ) {}

    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'capability' => $this->capability,
            'incomplete' => $this->incomplete(),
            'data' => $this->data,
            'diagnostics' => $this->normalize($this->diagnostics),
            'unresolved' => $this->normalize($this->unresolved),
        ];
    }

    public function incomplete(): bool
    {
        return $this->diagnostics !== [] || $this->unresolved !== [];
    }

    private function normalize(array $items): array
    {
        return array_map(
            fn ($item) => is_object($item) && method_exists($item, 'toArray') ? $item->toArray() : $item,
            $items,
        );
    }
}
```

```php
<?php

namespace Peralta\AgentKit\Refactoring\Application;

use RuntimeException;

final class CapabilityException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function toArray(): array
    {
        return [
            'schema_version' => CapabilityResult::SCHEMA_VERSION,
            'error' => ['code' => $this->errorCode, 'message' => $this->getMessage()],
        ];
    }
}
```

```php
<?php

namespace Peralta\AgentKit\Refactoring\Application;

use InvalidArgumentException;

final readonly class RefactoringTarget
{
    private function __construct(public string $value, public ?string $method) {}

    public static function parse(string $target): self
    {
        $target = trim($target);
        if ($target === '' || str_ends_with($target, '::')) {
            throw new InvalidArgumentException('A non-empty refactoring target is required.');
        }

        [$value, $method] = array_pad(explode('::', $target, 2), 2, null);
        $value = ltrim(trim($value), '\\');
        $method = $method !== null ? trim($method) : null;
        if ($value === '' || $method === '') {
            throw new InvalidArgumentException("Invalid refactoring target: {$target}");
        }

        return new self($value, $method);
    }
}
```

```php
<?php

namespace Peralta\AgentKit\Refactoring\Application;

interface RefactoringCapabilities
{
    public function describeCapabilities(): CapabilityResult;
    public function audit(string $projectRoot): CapabilityResult;
    public function analyze(string $projectRoot, string $target): CapabilityResult;
    public function findCallers(string $projectRoot, string $target): CapabilityResult;
    public function dependencies(string $projectRoot, string $target): CapabilityResult;
    public function impact(string $projectRoot, string $target): CapabilityResult;
}
```

- [ ] **Step 4: Run tests and verify GREEN**

Run the Step 2 command. Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Refactoring/Application tests/Unit/Refactoring/Application
git commit -m "feat: define refactoring capability contract"
```

## Task 2: Preserve unresolved references and make callers/impact method-aware

**Files:**

- Modify: `src/Refactoring/Analysis/Index/CodebaseIndex.php`
- Modify: `src/Refactoring/Analysis/Index/CodebaseIndexer.php`
- Modify: `src/Refactoring/Analysis/CallerAnalyzer.php`
- Modify: `src/Refactoring/Analysis/DTOs/CallerResult.php`
- Modify: `src/Refactoring/Analysis/ImpactAnalyzer.php`
- Modify: `src/Refactoring/Analysis/DTOs/ImpactResult.php`
- Modify: `tests/Unit/Refactoring/CodebaseIndexerTest.php`
- Modify: `tests/Unit/Refactoring/CallerAnalyzerTest.php`
- Modify: `tests/Unit/Refactoring/ImpactAnalyzerTest.php`

- [ ] **Step 1: Add failing tests for unresolved references, transitive callers, and method impact**

Append focused assertions:

```php
public function test_it_retains_dynamic_references_that_cannot_enter_the_graph(): void
{
    $index = $this->indexer()->build($this->fixtureRoot());

    $unknown = $index->unresolvedReferences();

    $this->assertNotEmpty($unknown);
    $this->assertContains('unknown', array_column($unknown, 'confidence'));
    $this->assertContains(null, array_column($unknown, 'target'));
}
```

```php
public function test_it_returns_transitive_dependents_and_unresolved_references(): void
{
    $result = $this->analyzer()->findCallers(
        $this->index(),
        'Fixtures\\Payments\\PaymentService',
        'charge',
    );

    $this->assertIsArray($result->transitiveDependents);
    $this->assertIsArray($result->unresolved);
    $this->assertArrayHasKey('transitive_dependents', $result->toArray());
    $this->assertArrayHasKey('unresolved', $result->toArray());
}
```

In `ImpactAnalyzerTest`, add two call edges targeting different methods and assert only the selected method is counted:

```php
public function test_it_limits_direct_callers_to_the_requested_method(): void
{
    $graph = new DependencyGraph();
    foreach (['Target', 'ChargeCaller', 'StatusCaller'] as $name) {
        $graph->addNode(new DependencyNode($name, 'class', "{$name}.php", 1));
    }
    $graph->addEdge(new DependencyEdge('ChargeCaller', 'run', 'Target', 'charge', DependencyType::METHOD_CALL, Confidence::EXACT, 'ChargeCaller.php', 5));
    $graph->addEdge(new DependencyEdge('StatusCaller', 'run', 'Target', 'status', DependencyType::METHOD_CALL, Confidence::EXACT, 'StatusCaller.php', 5));

    $result = (new ImpactAnalyzer())->analyze($this->index($graph, ['Target', 'ChargeCaller', 'StatusCaller']), 'Target', 'charge');

    $this->assertSame('charge', $result->method);
    $this->assertSame(1, $result->directCallers);
    $this->assertSame(['ChargeCaller'], array_column($result->direct, 'source'));
}
```

- [ ] **Step 2: Run the three unit test files and verify RED**

```bash
vendor/bin/phpunit tests/Unit/Refactoring/CodebaseIndexerTest.php tests/Unit/Refactoring/CallerAnalyzerTest.php tests/Unit/Refactoring/ImpactAnalyzerTest.php
```

Expected: FAIL on missing properties/methods and method filtering.

- [ ] **Step 3: Retain unresolved references in the index**

Add a fourth constructor argument and accessor to `CodebaseIndex`:

```php
public function __construct(
    private array $symbols,
    private DependencyGraph $dependencyGraph,
    private array $parseDiagnostics = [],
    private array $unresolved = [],
) {
    $methods = [];
    $files = [];
    foreach ($symbols as $fqcn => $symbol) {
        foreach ($symbol->methods as $method) {
            $methods[$fqcn][strtolower($method['name'])] = $method;
        }
        $files[str_replace('\\', '/', $symbol->file)][] = $symbol;
    }
    $this->methodsByClass = $methods;
    $this->symbolsByFile = $files;
}

public function unresolvedReferences(): array
{
    return array_map(fn ($reference) => $reference->toArray(), $this->unresolved);
}
```

In `CodebaseIndexer::build()`, split references before graph construction and pass unresolved references to the index:

```php
$unresolved = array_values(array_filter($references, fn ($reference) => $reference->target === null));

foreach ($references as $reference) {
    if ($reference->target === null) {
        continue;
    }
    // Keep existing edge creation.
}

return new CodebaseIndex($symbols, $graph, $diagnostics, $unresolved);
```

- [ ] **Step 4: Extend caller and impact result DTOs**

Add `transitiveDependents` and `unresolved` to `CallerResult`, and include them in `toArray()`:

```php
public function __construct(
    public string $target,
    public ?string $method,
    public array $directCallers,
    public array $structuralDependencies,
    public array $transitiveDependents,
    public array $unresolved,
    public array $diagnostics,
) {}
```

In `CallerAnalyzer::findCallers()` calculate the new fields:

```php
$directSources = array_fill_keys(array_column($direct, 'source'), true);
$structuralSources = array_fill_keys(array_column($structural, 'source'), true);
$transitive = array_values(array_filter(
    $index->graph()->transitiveDependents($target),
    fn (array $row) => !isset($directSources[$row['fqcn']]) && !isset($structuralSources[$row['fqcn']]),
));

return new CallerResult(
    $target,
    $method,
    $direct,
    $structural,
    $transitive,
    $index->unresolvedReferences(),
    $index->diagnostics(),
);
```

Add `public ?string $method` to `ImpactResult`, serialize it, update `ImpactAnalyzer::analyze()` to accept `?string $method = null`, and call:

```php
$callers = (new CallerAnalyzer())->findCallers($index, $class, $method);
```

Pass `$method` into the `ImpactResult` constructor immediately after the target.

- [ ] **Step 5: Run tests and verify GREEN**

Run the Step 2 command. Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Refactoring/Analysis tests/Unit/Refactoring
git commit -m "feat: expose unresolved and method-scoped impact data"
```

## Task 3: Implement the default RefactoringCapabilities service

**Files:**

- Create: `src/Refactoring/Application/DefaultRefactoringCapabilities.php`
- Create: `tests/Unit/Refactoring/Application/DefaultRefactoringCapabilitiesTest.php`

- [ ] **Step 1: Write failing integration-style unit tests for all capabilities**

Use real deterministic services over `tests/Fixtures/Refactoring/Ast` and assert the public envelope:

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Application;

use Peralta\AgentKit\Refactoring\Application\CapabilityException;
use Peralta\AgentKit\Refactoring\Application\DefaultRefactoringCapabilities;
use Peralta\AgentKit\Refactoring\Analysis\Ast\PhpAstParser;
use Peralta\AgentKit\Refactoring\Analysis\CallerAnalyzer;
use Peralta\AgentKit\Refactoring\Analysis\ImpactAnalyzer;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexer;
use Peralta\AgentKit\Refactoring\Support\PhpFileAnalyzer;
use Peralta\AgentKit\Refactoring\Support\ProjectScanner;
use Peralta\AgentKit\Refactoring\Support\RefactoringReport;
use PHPUnit\Framework\TestCase;

final class DefaultRefactoringCapabilitiesTest extends TestCase
{
    public function test_it_describes_every_supported_capability(): void
    {
        $names = array_column($this->service()->describeCapabilities()->data['capabilities'], 'name');
        $this->assertSame(['audit', 'analyze', 'find_callers', 'dependencies', 'impact'], $names);
    }

    public function test_it_exposes_audit_analyze_callers_dependencies_and_impact(): void
    {
        $root = $this->fixtureRoot();

        $this->assertArrayHasKey('summary', $this->service()->audit($root)->data);
        $this->assertSame('CheckoutService.php', $this->service()->analyze($root, 'Fixtures\\Checkout\\CheckoutService')->data['metrics']['path']);
        $this->assertNotEmpty($this->service()->findCallers($root, 'Fixtures\\Payments\\PaymentService::charge')->data['direct_callers']);
        $this->assertNotEmpty($this->service()->dependencies($root, 'Fixtures\\Checkout\\CheckoutService')->data['upstream_dependencies']);
        $this->assertSame('charge', $this->service()->impact($root, 'Fixtures\\Payments\\PaymentService::charge')->data['method']);
    }

    public function test_it_returns_stable_errors_for_invalid_roots_and_targets(): void
    {
        try {
            $this->service()->impact('/missing/root', 'Missing\\Service');
            $this->fail('Expected CapabilityException.');
        } catch (CapabilityException $exception) {
            $this->assertSame('PROJECT_ROOT_NOT_FOUND', $exception->errorCode);
        }
    }

    private function service(): DefaultRefactoringCapabilities
    {
        $analyzer = new PhpFileAnalyzer([]);
        $scanner = new ProjectScanner($analyzer, []);

        return new DefaultRefactoringCapabilities(
            $scanner,
            $analyzer,
            new RefactoringReport(),
            new CodebaseIndexer($scanner, new PhpAstParser(['Illuminate\\Support\\Facades\\'])),
            new CallerAnalyzer(),
            new ImpactAnalyzer(),
        );
    }

    private function fixtureRoot(): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/Refactoring/Ast';
    }
}
```

- [ ] **Step 2: Run the test and verify RED**

```bash
vendor/bin/phpunit tests/Unit/Refactoring/Application/DefaultRefactoringCapabilitiesTest.php
```

Expected: FAIL because the service does not exist.

- [ ] **Step 3: Implement capability orchestration**

Create the complete service:

```php
<?php

namespace Peralta\AgentKit\Refactoring\Application;

use InvalidArgumentException;
use Peralta\AgentKit\Refactoring\Analysis\CallerAnalyzer;
use Peralta\AgentKit\Refactoring\Analysis\ImpactAnalyzer;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndex;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexer;
use Peralta\AgentKit\Refactoring\Support\PhpFileAnalyzer;
use Peralta\AgentKit\Refactoring\Support\ProjectScanner;
use Peralta\AgentKit\Refactoring\Support\RefactoringReport;

final class DefaultRefactoringCapabilities implements RefactoringCapabilities
{
    public function __construct(
        private readonly ProjectScanner $scanner,
        private readonly PhpFileAnalyzer $fileAnalyzer,
        private readonly RefactoringReport $report,
        private readonly CodebaseIndexer $indexer,
        private readonly CallerAnalyzer $callers,
        private readonly ImpactAnalyzer $impactAnalyzer,
    ) {}

public function describeCapabilities(): CapabilityResult
{
    $definitions = [
        ['name' => 'audit', 'targets' => ['project'], 'cli' => 'agent-kit:refactor-audit', 'json' => true],
        ['name' => 'analyze', 'targets' => ['file', 'fqcn'], 'cli' => 'agent-kit:refactor-analyze', 'json' => true],
        ['name' => 'find_callers', 'targets' => ['fqcn', 'fqcn::method'], 'cli' => 'agent-kit:refactor-callers', 'json' => true],
        ['name' => 'dependencies', 'targets' => ['fqcn'], 'cli' => 'agent-kit:refactor-dependencies', 'json' => true],
        ['name' => 'impact', 'targets' => ['fqcn', 'fqcn::method'], 'cli' => 'agent-kit:refactor-impact', 'json' => true],
    ];

    return new CapabilityResult('capability_discovery', ['capabilities' => $definitions]);
}

public function audit(string $projectRoot): CapabilityResult
{
    $root = $this->root($projectRoot);
    return new CapabilityResult('audit', $this->report->build($this->scanner->scan($root), $root));
}

public function analyze(string $projectRoot, string $target): CapabilityResult
{
    $root = $this->root($projectRoot);
    $parsed = $this->target($target);
    $index = $this->indexer->build($root);
    $path = realpath($parsed->value) ?: realpath($root . '/' . $parsed->value);
    $fqcn = null;

    if ($path !== false && is_file($path)) {
        $relative = str_replace('\\', '/', ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR));
        $symbols = $index->classesInFile($relative);
        $fqcn = $symbols[0]->fqcn ?? null;
    } else {
        $symbol = $this->requireClass($index, $parsed->value);
        $fqcn = $symbol->fqcn;
        $relative = $symbol->file;
        $path = $root . '/' . $relative;
    }

    if ($parsed->method !== null && $fqcn === null) {
        throw new CapabilityException('UNSUPPORTED_TARGET', 'Method analysis requires a class target.');
    }
    if ($fqcn !== null) {
        $this->requireMethod($index, $fqcn, $parsed->method);
    }

    $metrics = $this->fileAnalyzer->analyze($path, $relative)->toArray();
    $data = ['target' => $fqcn ?? $relative, 'method' => $parsed->method, 'metrics' => $metrics];

    if ($fqcn !== null) {
        $callerResult = $this->callers->findCallers($index, $fqcn, $parsed->method);
        $impactResult = $this->impactAnalyzer->analyze($index, $fqcn, $parsed->method);
        $data['upstream_dependencies'] = array_map(fn ($edge) => $edge->toArray(), $index->findDependencies($fqcn));
        $data['direct_callers'] = $callerResult->directCallers;
        $data['structural_dependencies'] = $callerResult->structuralDependencies;
        $data['transitive_impact'] = $impactResult->transitive;
        $data['risk'] = $impactResult->risk;
    }

    return new CapabilityResult('analyze', $data, $index->diagnostics(), $index->unresolvedReferences());
}

public function findCallers(string $projectRoot, string $target): CapabilityResult
{
    $root = $this->root($projectRoot);
    $parsed = $this->target($target);
    $index = $this->indexer->build($root);
    $this->requireClass($index, $parsed->value);
    $this->requireMethod($index, $parsed->value, $parsed->method);
    $result = $this->callers->findCallers($index, $parsed->value, $parsed->method);
    $data = $result->toArray();
    unset($data['diagnostics'], $data['unresolved']);

    return new CapabilityResult('find_callers', $data, $result->diagnostics, $result->unresolved);
}

public function dependencies(string $projectRoot, string $target): CapabilityResult
{
    $root = $this->root($projectRoot);
    $parsed = $this->target($target);
    if ($parsed->method !== null) {
        throw new CapabilityException('UNSUPPORTED_TARGET', 'Dependency analysis accepts a class target, not a method.');
    }
    $index = $this->indexer->build($root);
    $this->requireClass($index, $parsed->value);

    return new CapabilityResult('dependencies', [
        'target' => $parsed->value,
        'upstream_dependencies' => array_map(fn ($edge) => $edge->toArray(), $index->findDependencies($parsed->value)),
        'downstream_dependents' => array_map(fn ($edge) => $edge->toArray(), $index->findReferencesTo($parsed->value)),
        'transitive_dependents' => $index->graph()->transitiveDependents($parsed->value),
    ], $index->diagnostics(), $index->unresolvedReferences());
}

public function impact(string $projectRoot, string $target): CapabilityResult
{
    $root = $this->root($projectRoot);
    $parsed = $this->target($target);
    $index = $this->indexer->build($root);
    $this->requireClass($index, $parsed->value);
    $this->requireMethod($index, $parsed->value, $parsed->method);
    $result = $this->impactAnalyzer->analyze($index, $parsed->value, $parsed->method);
    $data = $result->toArray();
    unset($data['diagnostics']);

    return new CapabilityResult('impact', $data, $result->diagnostics, $index->unresolvedReferences());
}

private function root(string $requested): string
{
    $root = realpath($requested);
    if ($root === false || !is_dir($root)) {
        throw new CapabilityException('PROJECT_ROOT_NOT_FOUND', "Project root not found: {$requested}");
    }
    return rtrim($root, DIRECTORY_SEPARATOR);
}

private function target(string $target): RefactoringTarget
{
    try {
        return RefactoringTarget::parse($target);
    } catch (InvalidArgumentException $exception) {
        throw new CapabilityException('INVALID_TARGET', $exception->getMessage());
    }
}

private function requireClass(CodebaseIndex $index, string $fqcn): object
{
    $symbol = $index->findClass($fqcn);
    if ($symbol === null) {
        throw new CapabilityException('TARGET_NOT_FOUND', "Class not found in index: {$fqcn}");
    }
    return $symbol;
}

private function requireMethod(CodebaseIndex $index, string $fqcn, ?string $method): void
{
    if ($method !== null && $index->findMethod($fqcn, $method) === null) {
        throw new CapabilityException('TARGET_NOT_FOUND', "Method not found in index: {$fqcn}::{$method}");
    }
}
}
```

- [ ] **Step 4: Run the test and verify GREEN**

Run the Step 2 command. Expected: PASS.

- [ ] **Step 5: Run all refactoring unit tests**

```bash
vendor/bin/phpunit tests/Unit/Refactoring
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Refactoring/Application tests/Unit/Refactoring/Application
git commit -m "feat: add default refactoring capabilities"
```

## Task 4: Make Artisan a thin capability adapter with stable JSON

**Files:**

- Create: `src/Refactoring/Commands/Concerns/RendersCapabilityResults.php`
- Create: `src/Refactoring/Commands/RefactorCapabilitiesCommand.php`
- Modify: `src/Refactoring/Commands/RefactorAuditCommand.php`
- Modify: `src/Refactoring/Commands/RefactorAnalyzeCommand.php`
- Modify: `src/Refactoring/Commands/RefactorCallersCommand.php`
- Modify: `src/Refactoring/Commands/RefactorDependenciesCommand.php`
- Modify: `src/Refactoring/Commands/RefactorImpactCommand.php`
- Modify: `src/AgentKitServiceProvider.php`
- Modify: `tests/Feature/Refactoring/RefactoringCommandsTest.php`

- [ ] **Step 1: Rewrite feature expectations first**

Update JSON assertions to read from the versioned envelope and add audit/analyze/discovery coverage:

```php
$decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
$this->assertSame('1.0', $decoded['schema_version']);
$this->assertFalse($decoded['incomplete']);
$this->assertSame('Fixtures\\Payments\\PaymentService', $decoded['data']['target']);
```

Add:

```php
public function test_discovery_audit_and_analyze_emit_versioned_json(): void
{
    $this->assertSame(0, Artisan::call('agent-kit:refactor-capabilities', ['--json' => true]));
    $capabilities = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('capability_discovery', $capabilities['capability']);

    $this->assertSame(0, Artisan::call('agent-kit:refactor-audit', [
        'path' => $this->fixtureRoot(), '--no-baseline' => true, '--json' => true,
    ]));
    $audit = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $this->assertArrayHasKey('summary', $audit['data']);

    $this->assertSame(0, Artisan::call('agent-kit:refactor-analyze', [
        'target' => 'Fixtures\\Checkout\\CheckoutService', '--path' => $this->fixtureRoot(), '--json' => true,
    ]));
    $analysis = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('CheckoutService.php', $analysis['data']['metrics']['path']);
}

public function test_json_failure_uses_the_error_envelope(): void
{
    $this->assertSame(1, Artisan::call('agent-kit:refactor-impact', [
        'target' => 'Missing\\Service', '--path' => $this->fixtureRoot(), '--json' => true,
    ]));
    $error = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('TARGET_NOT_FOUND', $error['error']['code']);
}
```

- [ ] **Step 2: Run feature tests and verify RED**

```bash
vendor/bin/phpunit tests/Feature/Refactoring/RefactoringCommandsTest.php
```

Expected: FAIL because discovery and the envelopes do not exist.

- [ ] **Step 3: Add shared JSON/error rendering**

```php
<?php

namespace Peralta\AgentKit\Refactoring\Commands\Concerns;

use Peralta\AgentKit\Refactoring\Application\CapabilityException;
use Peralta\AgentKit\Refactoring\Application\CapabilityResult;

trait RendersCapabilityResults
{
    private function json(CapabilityResult $result): void
    {
        $this->line(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function failure(CapabilityException $exception, bool $json): int
    {
        if ($json) {
            $this->line(json_encode($exception->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->error($exception->getMessage());
        }

        return self::FAILURE;
    }
}
```

- [ ] **Step 4: Add discovery command and convert all five commands**

Create discovery:

```php
final class RefactorCapabilitiesCommand extends Command
{
    use RendersCapabilityResults;

    protected $signature = 'agent-kit:refactor-capabilities {--json : Emit JSON only}';
    protected $description = 'Describe deterministic Agent Kit refactoring capabilities';

    public function handle(RefactoringCapabilities $capabilities): int
    {
        $result = $capabilities->describeCapabilities();
        if ($this->option('json')) {
            $this->json($result);
        } else {
            $this->table(['Capability', 'Targets', 'CLI', 'JSON'], array_map(
                fn (array $item) => [$item['name'], implode(', ', $item['targets']), $item['cli'], $item['json'] ? 'yes' : 'no'],
                $result->data['capabilities'],
            ));
        }
        return self::SUCCESS;
    }
}
```

Use these exact signatures and capability calls:

```php
// RefactorAuditCommand
protected $signature = 'agent-kit:refactor-audit
    {path? : Project root}
    {--output= : Output directory}
    {--no-baseline : Do not update baseline.json}
    {--json : Emit JSON only}';

public function handle(RefactoringCapabilities $capabilities, RefactoringReport $reporter): int
{
    try {
        $root = (string) ($this->argument('path') ?: base_path());
        $result = $capabilities->audit($root);
    } catch (CapabilityException $exception) {
        return $this->failure($exception, (bool) $this->option('json'));
    }
    if ($this->option('json')) {
        $this->json($result);
        return self::SUCCESS;
    }
    $output = (string) ($this->option('output') ?: rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR) . '/.agent-kit/refactoring');
    if (!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)) {
        return $this->failure(new CapabilityException('OUTPUT_WRITE_FAILED', "Could not create output directory: {$output}"), false);
    }
    file_put_contents($output . '/audit.json', json_encode($result->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    file_put_contents($output . '/audit.md', $reporter->markdown($result->data));
    if (!$this->option('no-baseline')) {
        file_put_contents($output . '/baseline.json', json_encode($result->data['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
    $summary = $result->data['summary'];
    $this->table(['PHP files', 'Lines', 'High', 'Medium', 'Low'], [[
        $summary['php_files'], $summary['lines'], $summary['issues']['high'], $summary['issues']['medium'], $summary['issues']['low'],
    ]]);
    $this->info("Reports written to {$output}/audit.md and audit.json");
    return self::SUCCESS;
}
```

```php
// RefactorAnalyzeCommand
protected $signature = 'agent-kit:refactor-analyze
    {target : PHP file or fully qualified class}
    {--path= : Project root; defaults to the Laravel base path}
    {--json : Emit JSON only}';

public function handle(RefactoringCapabilities $capabilities): int
{
    try {
        $result = $capabilities->analyze((string) ($this->option('path') ?: base_path()), (string) $this->argument('target'));
    } catch (CapabilityException $exception) {
        return $this->failure($exception, (bool) $this->option('json'));
    }
    if ($this->option('json')) {
        $this->json($result);
        return self::SUCCESS;
    }
    $metrics = $result->data['metrics'];
    $this->table(['Metric', 'Value'], [
        ['Target', $result->data['target']], ['Lines', $metrics['lines']], ['Methods/functions', $metrics['methods']],
        ['Imports/uses', $metrics['dependencies']], ['Branches', $metrics['branches']], ['Risk', $result->data['risk'] ?? 'UNKNOWN'],
    ]);
    $this->table(['Severity', 'Smell', 'Reason'], array_map(
        fn (array $smell) => [strtoupper($smell['severity']), $smell['name'], $smell['reason']],
        $metrics['smells'],
    ));
    return self::SUCCESS;
}
```

```php
// RefactorCallersCommand
protected $signature = 'agent-kit:refactor-callers
    {target : Fully qualified class or Class::method}
    {--path= : Project root; defaults to the Laravel base path}
    {--json : Emit JSON only}';

public function handle(RefactoringCapabilities $capabilities): int
{
    try {
        $result = $capabilities->findCallers((string) ($this->option('path') ?: base_path()), (string) $this->argument('target'));
    } catch (CapabilityException $exception) {
        return $this->failure($exception, (bool) $this->option('json'));
    }
    if ($this->option('json')) {
        $this->json($result);
        return self::SUCCESS;
    }
    $this->info('REFACTORING CALLER ANALYSIS');
    $this->line('Target: ' . $result->data['target'] . ($result->data['method'] ? '::' . $result->data['method'] : ''));
    $this->info('DIRECT CALLERS');
    $this->renderIncomingEdges($result->data['direct_callers']);
    $this->info('STRUCTURAL DEPENDENCIES');
    $this->renderIncomingEdges($result->data['structural_dependencies']);
    $this->info('TRANSITIVE DEPENDENTS');
    foreach ($result->data['transitive_dependents'] as $row) $this->line(implode(' -> ', $row['path']));
    if ($result->incomplete()) $this->warn('Static analysis is incomplete; inspect diagnostics and unresolved references.');
    return self::SUCCESS;
}
```

```php
// RefactorDependenciesCommand
protected $signature = 'agent-kit:refactor-dependencies
    {target : Fully qualified target class}
    {--path= : Project root; defaults to the Laravel base path}
    {--json : Emit JSON only}';

public function handle(RefactoringCapabilities $capabilities): int
{
    try {
        $result = $capabilities->dependencies((string) ($this->option('path') ?: base_path()), (string) $this->argument('target'));
    } catch (CapabilityException $exception) {
        return $this->failure($exception, (bool) $this->option('json'));
    }
    if ($this->option('json')) {
        $this->json($result);
        return self::SUCCESS;
    }
    $this->info('UPSTREAM DEPENDENCIES');
    $this->renderOutgoingEdges($result->data['upstream_dependencies']);
    $this->info('DOWNSTREAM DEPENDENTS');
    $this->renderIncomingEdges($result->data['downstream_dependents']);
    $this->info('TRANSITIVE DEPENDENTS');
    foreach ($result->data['transitive_dependents'] as $row) $this->line(implode(' -> ', $row['path']));
    if ($result->incomplete()) $this->warn('Static analysis is incomplete; inspect diagnostics and unresolved references.');
    return self::SUCCESS;
}
```

```php
// RefactorImpactCommand
protected $signature = 'agent-kit:refactor-impact
    {target : Fully qualified class or Class::method}
    {--path= : Project root; defaults to the Laravel base path}
    {--json : Emit JSON only}';

public function handle(RefactoringCapabilities $capabilities): int
{
    try {
        $result = $capabilities->impact((string) ($this->option('path') ?: base_path()), (string) $this->argument('target'));
    } catch (CapabilityException $exception) {
        return $this->failure($exception, (bool) $this->option('json'));
    }
    if ($this->option('json')) {
        $this->json($result);
        return self::SUCCESS;
    }
    $data = $result->data;
    $this->info('REFACTORING IMPACT ANALYSIS');
    $this->table(['Target', 'Method', 'Risk', 'Direct', 'Structural', 'Transitive', 'Files'], [[
        $data['target'], $data['method'] ?? '-', $data['risk'], $data['direct_callers'],
        $data['structural_dependencies'], $data['transitive_dependents'], $data['affected_files'],
    ]]);
    $this->info('DIRECT CALLERS');
    $this->renderIncomingEdges($data['direct']);
    $this->info('STRUCTURAL DEPENDENCIES');
    $this->renderIncomingEdges($data['structural']);
    $this->info('TRANSITIVE IMPACT');
    foreach ($data['transitive'] as $row) $this->line(implode(' -> ', $row['path']));
    if ($result->incomplete()) $this->warn('Static analysis is incomplete; inspect diagnostics and unresolved references.');
    return self::SUCCESS;
}
```

Use these helpers in the commands that render edge tables:

```php
private function renderIncomingEdges(array $edges): void
{
    $this->table(['Source', 'Method', 'Type', 'Confidence', 'Location'], array_map(
        fn (array $edge) => [
            $edge['source'], $edge['source_method'] ?? '-', strtoupper($edge['type']),
            strtoupper($edge['confidence']), $edge['file'] . ':' . $edge['line'],
        ],
        $edges,
    ));
}

private function renderOutgoingEdges(array $edges): void
{
    $this->table(['Target', 'Method', 'Type', 'Confidence', 'Location'], array_map(
        fn (array $edge) => [
            $edge['target'], $edge['target_method'] ?? '-', strtoupper($edge['type']),
            strtoupper($edge['confidence']), $edge['file'] . ':' . $edge['line'],
        ],
        $edges,
    ));
}
```

`RefactorCallersCommand` and `RefactorImpactCommand` need only `renderIncomingEdges()`. `RefactorDependenciesCommand` needs both helpers. No command may inject `CodebaseIndexer`, `CallerAnalyzer`, `ImpactAnalyzer`, `ProjectScanner`, or `PhpFileAnalyzer` after this task.

- [ ] **Step 5: Bind and register the application service**

In `registerRefactoring()`:

```php
$this->app->bind(RefactoringCapabilities::class, fn ($app) => new DefaultRefactoringCapabilities(
    $app->make(ProjectScanner::class),
    $app->make(PhpFileAnalyzer::class),
    $app->make(RefactoringReport::class),
    $app->make(CodebaseIndexer::class),
    $app->make(CallerAnalyzer::class),
    $app->make(ImpactAnalyzer::class),
));
```

Register `RefactorCapabilitiesCommand::class` in `boot()`.

- [ ] **Step 6: Run feature and unit tests**

```bash
vendor/bin/phpunit tests/Feature/Refactoring/RefactoringCommandsTest.php tests/Unit/Refactoring
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Refactoring/Commands src/AgentKitServiceProvider.php tests/Feature/Refactoring/RefactoringCommandsTest.php
git commit -m "refactor: route refactoring CLI through capabilities"
```

## Task 5: Add canonical agent resources, repository, and strict renderer

**Files:**

- Create: `resources/agents/refactoring/instructions.md`
- Create: `resources/agents/refactoring/rules/core.md`
- Create: `resources/agents/refactoring/rules/laravel.md`
- Create: `resources/agents/refactoring/rules/smells.md`
- Create: `resources/agents/refactoring/rules/patterns.md`
- Create: six files under `resources/agents/refactoring/commands/`
- Create: `src/Refactoring/Agents/AgentCommandRepository.php`
- Create: `src/Refactoring/Agents/AgentTemplateRenderer.php`
- Test: `tests/Unit/Refactoring/Agents/AgentCommandRepositoryTest.php`
- Test: `tests/Unit/Refactoring/Agents/AgentTemplateRendererTest.php`

- [ ] **Step 1: Write failing resource and renderer tests**

```php
public function test_every_command_composes_the_same_deterministic_first_instructions(): void
{
    $repository = new AgentCommandRepository(dirname(__DIR__, 4) . '/resources/agents/refactoring');
    foreach ($repository->names() as $name) {
        $content = $repository->command($name);
        $this->assertStringContainsString('MCP tools', $content);
        $this->assertStringContainsString('Agent Kit CLI', $content);
        $this->assertStringContainsString('FACTS', $content);
        $this->assertStringContainsString('ANALYZE != MODIFY', $content);
    }
}

public function test_renderer_replaces_known_values_and_rejects_unknown_placeholders(): void
{
    $renderer = new AgentTemplateRenderer();
    $this->assertSame('Run command', $renderer->render('Run {{cli_command}}', ['cli_command' => 'command']));

    $this->expectException(InvalidArgumentException::class);
    $renderer->render('Run {{missing}}', []);
}
```

- [ ] **Step 2: Run tests and verify RED**

```bash
vendor/bin/phpunit tests/Unit/Refactoring/Agents/AgentCommandRepositoryTest.php tests/Unit/Refactoring/Agents/AgentTemplateRendererTest.php
```

Expected: FAIL because resources and classes do not exist.

- [ ] **Step 3: Create canonical instructions and rules**

`instructions.md` must contain this exact execution discipline:

```markdown
## Execution priority

ANALYZE != MODIFY. Do not modify source files.

Before analyzing relationships manually:
1. Discover compatible MCP tools and use the relevant deterministic capability when available.
2. Otherwise run `php artisan agent-kit:refactor-capabilities --json`, then use the Agent Kit CLI command below with `--json`.
3. Use repository search and source/test reading only to complement unresolved context.
4. Use LLM inference only for interpretation, never to invent structural relationships.

Separate the response into FACTS, INTERPRETATION, and RECOMMENDATIONS. Mark dynamic targets that static analysis cannot resolve as UNKNOWN or UNRESOLVED DYNAMIC REFERENCE.
```

Create the four rule bodies exactly as follows:

```markdown
# Refactoring safety

- Preserve behavior.
- Prefer small changes.
- Search callers before moving public methods.
- Search events and jobs before changing side effects.
- Check tests before recommending a refactor.
- Never assume a class is isolated.
- Prefer evidence over speculation.
- Mark unresolved dynamic behavior explicitly.
```

```markdown
# Laravel awareness

When Laravel is detected, consider Controllers, FormRequests, Services, Actions, Models, Jobs, Events, Listeners, queued listeners, Observers, Policies, Commands, scheduled commands, Providers, Facades, Eloquent relationships, container bindings, Gateways, external integrations, and tests before recommending a change.
```

```markdown
# Code smells

Metrics and code smells are deterministic signals, not architectural verdicts. Confirm responsibility, coupling, callers, side effects, and tests before drawing a conclusion.
```

```markdown
# Patterns

A DESIGN PATTERN IS NOT A GOAL. Recommend a pattern only after naming the concrete problem it solves, the evidence for that problem, and the smallest behavior-preserving change that addresses it.
```

- [ ] **Step 4: Create all six command bodies**

Each command begins with a heading and relies on `instructions.md` being prepended by the repository. Use these complete operation-specific bodies:

```markdown
# Refactoring Audit

Run `{{cli_audit}} --json` when an MCP audit capability is unavailable. Do not modify source files.

Use dependency and impact analysis for high-risk areas. Never recommend a pattern before identifying the concrete problem.

Return Executive Summary, Architecture Score (label it as deterministic or interpretive), Critical Issues, High Priority Issues, Code Smells, Coupling Risks, High Impact Classes, Testing Risks, and Recommended Roadmap.
```

```markdown
# Analyze Refactoring Target

Analyze the target supplied with this invocation. If it is missing, ask for a file, class, or module. Run `{{cli_analyze}} "<target>" --json` when the equivalent MCP capability is unavailable, then retrieve dependencies, callers, impact when appropriate, source context, and tests.

Return Target, Responsibilities, Metrics, Code Smells, Dependencies, Direct Callers, Transitive Impact, Side Effects, Tests, Refactoring Opportunities, and Risk.
```

```markdown
# Find Callers

Find callers for the class or `Class::method` supplied with this invocation. Run `{{cli_callers}} "<target>" --json` when an MCP caller capability is unavailable. Do not infer callers solely by reading source code.

Return DIRECT CALLERS, STRUCTURAL DEPENDENCIES, TRANSITIVE DEPENDENTS, and UNRESOLVED/DYNAMIC REFERENCES.
```

```markdown
# Analyze Dependencies

Analyze the class supplied with this invocation. Run `{{cli_dependencies}} "<target>" --json` when an MCP dependency capability is unavailable.

Return UPSTREAM DEPENDENCIES, DOWNSTREAM DEPENDENTS, RELATIONSHIP TYPES, confidence, unresolved references, and dependency paths when available.
```

```markdown
# Analyze Change Impact

Answer: “If I change this, what can potentially be affected?” Run `{{cli_impact}} "<target>" --json` when an MCP impact capability is unavailable. Inspect relevant tests, jobs, events, and integrations after deterministic analysis.

Return CHANGE IMPACT, Target, Risk, Direct Callers, Structural Dependencies, Transitive Dependents, Affected Files, Affected Modules, Jobs/Events, External Integrations, Relevant Tests, Potential Breakage Scenarios, and Recommended Verification. Say “potentially affected” or “should be verified”; dependency does not prove breakage.
```

```markdown
# Build a Refactoring Plan

Do not modify code. For the target supplied with this invocation, complete Analyze -> Callers -> Impact -> Tests -> Plan using MCP first and the documented CLI fallbacks second.

Return REFACTORING PLAN, Goal, Current Problem, Evidence, Affected Components, Risk, Preparation, small isolated numbered steps, Validation after each step, Rollback Considerations, and Definition of Done. Never propose a broad rewrite slogan in place of steps.
```

- [ ] **Step 5: Implement repository and strict renderer**

```php
final class AgentCommandRepository
{
    private const COMMANDS = ['audit', 'analyze', 'callers', 'dependencies', 'impact', 'plan'];

    public function __construct(private readonly string $root) {}

    public function names(): array { return self::COMMANDS; }

    public function command(string $name): string
    {
        if (!in_array($name, self::COMMANDS, true)) {
            throw new InvalidArgumentException("Unknown refactoring agent command: {$name}");
        }
        return trim($this->read('instructions.md')) . "\n\n" . trim($this->read("commands/{$name}.md")) . "\n";
    }

    public function rules(): string
    {
        return implode("\n\n", array_map(fn ($file) => trim($this->read("rules/{$file}.md")), ['core', 'laravel', 'smells', 'patterns'])) . "\n";
    }

    private function read(string $path): string
    {
        $file = $this->root . '/' . $path;
        if (!is_file($file)) throw new RuntimeException("Agent resource not found: {$path}");
        return (string) file_get_contents($file);
    }
}
```

```php
final class AgentTemplateRenderer
{
    public function render(string $template, array $values): string
    {
        $rendered = strtr($template, array_combine(
            array_map(fn ($key) => '{{' . $key . '}}', array_keys($values)),
            array_values($values),
        ) ?: []);
        if (preg_match('/\{\{[a-z_]+\}\}/', $rendered, $match)) {
            throw new InvalidArgumentException("Unresolved agent template placeholder: {$match[0]}");
        }
        return $rendered;
    }
}
```

- [ ] **Step 6: Run tests and verify GREEN**

Run the Step 2 command. Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add resources/agents/refactoring src/Refactoring/Agents tests/Unit/Refactoring/Agents
git commit -m "feat: add shared refactoring agent templates"
```

## Task 6: Generate native Cursor and Claude Code artifacts

**Files:**

- Create: `src/Refactoring/Agents/AgentAdapter.php`
- Create: `src/Refactoring/Agents/GeneratedAgentFile.php`
- Create: `src/Refactoring/Agents/CursorAgentAdapter.php`
- Create: `src/Refactoring/Agents/ClaudeCodeAgentAdapter.php`
- Create: golden files under `tests/Fixtures/Refactoring/Agents/Expected/cursor/`
- Create: golden files under `tests/Fixtures/Refactoring/Agents/Expected/claude/`
- Create: `tests/Unit/Refactoring/Agents/AgentAdapterTest.php`

- [ ] **Step 1: Write a failing golden-file test**

```php
#[DataProvider('adapters')]
public function test_adapter_output_matches_golden_files(AgentAdapter $adapter, string $fixture): void
{
    $files = $adapter->generate($this->repository(), new AgentTemplateRenderer());
    $this->assertCount(7, $files);
    foreach ($files as $file) {
        $expected = file_get_contents($fixture . '/' . $file->path);
        $this->assertSame($expected, $file->content, $file->path);
    }
}

public static function adapters(): array
{
    $expected = dirname(__DIR__, 3) . '/Fixtures/Refactoring/Agents/Expected';
    return [
        'cursor' => [new CursorAgentAdapter(), "{$expected}/cursor"],
        'claude' => [new ClaudeCodeAgentAdapter(), "{$expected}/claude"],
    ];
}
```

- [ ] **Step 2: Run the test and verify RED**

```bash
vendor/bin/phpunit tests/Unit/Refactoring/Agents/AgentAdapterTest.php
```

Expected: FAIL because adapter types and fixtures do not exist.

- [ ] **Step 3: Add adapter contracts and deterministic generation**

```php
interface AgentAdapter
{
    public function id(): string;
    /** @return list<GeneratedAgentFile> */
    public function generate(AgentCommandRepository $repository, AgentTemplateRenderer $renderer): array;
}

final readonly class GeneratedAgentFile
{
    public function __construct(public string $path, public string $content) {}
}
```

Both adapters iterate `AgentCommandRepository::names()`, render the same composed body with this value map, and prepend native frontmatter:

```php
private const VALUES = [
    'cli_audit' => 'php artisan agent-kit:refactor-audit',
    'cli_analyze' => 'php artisan agent-kit:refactor-analyze',
    'cli_callers' => 'php artisan agent-kit:refactor-callers',
    'cli_dependencies' => 'php artisan agent-kit:refactor-dependencies',
    'cli_impact' => 'php artisan agent-kit:refactor-impact',
];
```

Cursor destinations:

```text
.cursor/skills/refactor-audit/SKILL.md
.cursor/skills/refactor-analyze/SKILL.md
.cursor/skills/refactor-callers/SKILL.md
.cursor/skills/refactor-dependencies/SKILL.md
.cursor/skills/refactor-impact/SKILL.md
.cursor/skills/refactor-plan/SKILL.md
.cursor/rules/agent-kit-refactoring.mdc
```

Cursor skill frontmatter contains `name` and `description`. The rule uses `alwaysApply: true`.

Claude destinations use the same skill names under `.claude/skills/` plus `.claude/rules/agent-kit-refactoring.md`. Claude skill frontmatter adds `disable-model-invocation: true` so analysis workflows run only when explicitly requested.

- [ ] **Step 4: Generate and check in exact golden fixtures**

Temporarily add this method to `AgentAdapterTest`, run it once for each provider, then remove the method before committing:

```php
#[DataProvider('adapters')]
public function test_write_reviewed_golden_files(AgentAdapter $adapter, string $fixture): void
{
    foreach ($adapter->generate($this->repository(), new AgentTemplateRenderer()) as $file) {
        $target = $fixture . '/' . $file->path;
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0777, true);
        file_put_contents($target, $file->content);
    }
    $this->addToAssertionCount(1);
}
```

Run:

```bash
vendor/bin/phpunit tests/Unit/Refactoring/Agents/AgentAdapterTest.php --filter=test_write_reviewed_golden_files
rg -n '\{\{|generator-only' tests/Fixtures/Refactoring/Agents/Expected
```

Expected: the first command writes fourteen reviewed fixtures; the `rg` command returns no matches. Remove `test_write_reviewed_golden_files()` and keep the read-only golden comparison test.

- [ ] **Step 5: Run adapter tests and verify GREEN**

Run the Step 2 command. Expected: PASS with 7 artifacts per adapter.

- [ ] **Step 6: Commit**

```bash
git add src/Refactoring/Agents tests/Unit/Refactoring/Agents tests/Fixtures/Refactoring/Agents
git commit -m "feat: generate Cursor and Claude refactoring skills"
```

## Task 7: Implement safe atomic installation and adapter registry

**Files:**

- Create: `src/Refactoring/Agents/AgentAdapterRegistry.php`
- Create: `src/Refactoring/Agents/InstallationResult.php`
- Create: `src/Refactoring/Agents/AgentConfigurationInstaller.php`
- Create: `tests/Unit/Refactoring/Agents/AgentConfigurationInstallerTest.php`

- [ ] **Step 1: Write failing installer tests**

Cover creation, idempotency, conflict, force, unknown adapter, and path safety:

```php
public function test_it_creates_then_leaves_identical_files_unchanged(): void
{
    $root = $this->temporaryDirectory();
    $installer = $this->installer();

    $created = $installer->install($root, ['cursor']);
    $unchanged = $installer->install($root, ['cursor']);

    $this->assertCount(7, $created->created);
    $this->assertCount(7, $unchanged->unchanged);
    $this->assertSame([], $unchanged->conflicts);
}

public function test_it_preserves_custom_content_unless_force_is_explicit(): void
{
    $root = $this->temporaryDirectory();
    $file = $root . '/.cursor/skills/refactor-impact/SKILL.md';
    mkdir(dirname($file), 0777, true);
    file_put_contents($file, 'custom');

    $conflict = $this->installer()->install($root, ['cursor']);
    $this->assertContains('.cursor/skills/refactor-impact/SKILL.md', $conflict->conflicts);
    $this->assertSame('custom', file_get_contents($file));

    $forced = $this->installer()->install($root, ['cursor'], true);
    $this->assertContains('.cursor/skills/refactor-impact/SKILL.md', $forced->overwritten);
    $this->assertNotSame('custom', file_get_contents($file));
}
```

- [ ] **Step 2: Run the test and verify RED**

```bash
vendor/bin/phpunit tests/Unit/Refactoring/Agents/AgentConfigurationInstallerTest.php
```

Expected: FAIL because installer classes do not exist.

- [ ] **Step 3: Implement registry and result DTO**

```php
final class AgentAdapterRegistry
{
    /** @param list<AgentAdapter> $adapters */
    public function __construct(private readonly array $adapters) {}

    public function ids(): array
    {
        return array_map(fn (AgentAdapter $adapter) => $adapter->id(), $this->adapters);
    }

    public function get(string $id): AgentAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->id() === $id) return $adapter;
        }
        throw new InvalidArgumentException("Unsupported coding agent: {$id}");
    }
}

final readonly class InstallationResult
{
    public function __construct(
        public array $created = [],
        public array $unchanged = [],
        public array $conflicts = [],
        public array $overwritten = [],
    ) {}

    public function successful(): bool { return $this->conflicts === []; }
}
```

- [ ] **Step 4: Implement atomic, conflict-safe installation**

The installer constructor receives registry, repository, and renderer. `install()` resolves the project root, obtains each adapter's artifacts, rejects absolute or traversal paths, compares existing bytes, and writes via a same-directory temporary file plus `rename()`:

```php
private function writeAtomically(string $target, string $content): void
{
    $directory = dirname($target);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException("Could not create agent directory: {$directory}");
    }
    $temporary = $target . '.agent-kit-' . bin2hex(random_bytes(6));
    try {
        if (file_put_contents($temporary, $content, LOCK_EX) === false || !rename($temporary, $target)) {
            throw new RuntimeException("Could not install agent file: {$target}");
        }
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
}

private function assertSafeRelativePath(string $path): void
{
    if ($path === '' || str_starts_with($path, '/') || preg_match('#(^|/)\.\.(/|$)#', str_replace('\\', '/', $path))) {
        throw new InvalidArgumentException("Unsafe generated agent path: {$path}");
    }
}
```

Collect the four status arrays across all selected adapters and return one `InstallationResult`. Do not abort on the first content conflict; report all conflicts and write non-conflicting missing files.

- [ ] **Step 5: Run installer tests and verify GREEN**

Run the Step 2 command. Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Refactoring/Agents tests/Unit/Refactoring/Agents/AgentConfigurationInstallerTest.php
git commit -m "feat: install agent files without silent overwrite"
```

## Task 8: Add the Artisan installer and container wiring

**Files:**

- Create: `src/Refactoring/Commands/InstallAgentsCommand.php`
- Modify: `src/AgentKitServiceProvider.php`
- Create: `tests/Feature/Refactoring/InstallAgentsCommandTest.php`

- [ ] **Step 1: Write failing command tests**

```php
public function test_it_installs_selected_agents_and_supports_all(): void
{
    $root = $this->temporaryDirectory();
    $this->assertSame(0, Artisan::call('agent-kit:agents:install', ['agents' => ['cursor'], '--path' => $root]));
    $this->assertFileExists($root . '/.cursor/skills/refactor-impact/SKILL.md');

    $other = $this->temporaryDirectory();
    $this->assertSame(0, Artisan::call('agent-kit:agents:install', ['--all' => true, '--path' => $other]));
    $this->assertFileExists($other . '/.cursor/skills/refactor-impact/SKILL.md');
    $this->assertFileExists($other . '/.claude/skills/refactor-impact/SKILL.md');
}

public function test_it_returns_failure_and_reports_conflicts(): void
{
    $root = $this->temporaryDirectory();
    $file = $root . '/.claude/rules/agent-kit-refactoring.md';
    mkdir(dirname($file), 0777, true);
    file_put_contents($file, 'custom');

    $this->assertSame(1, Artisan::call('agent-kit:agents:install', ['agents' => ['claude'], '--path' => $root]));
    $this->assertStringContainsString('CONFLICT', Artisan::output());
    $this->assertSame('custom', file_get_contents($file));
}
```

- [ ] **Step 2: Run command tests and verify RED**

```bash
vendor/bin/phpunit tests/Feature/Refactoring/InstallAgentsCommandTest.php
```

Expected: FAIL because the command is not registered.

- [ ] **Step 3: Implement the installer command**

```php
final class InstallAgentsCommand extends Command
{
    protected $signature = 'agent-kit:agents:install
        {agents?* : cursor and/or claude}
        {--all : Install every supported adapter}
        {--path= : Consumer project root}
        {--force : Overwrite conflicting Agent Kit-dedicated files}';

    protected $description = 'Install Agent Kit refactoring skills for coding agents';

    public function handle(AgentConfigurationInstaller $installer, AgentAdapterRegistry $registry): int
    {
        $agents = $this->option('all') ? $registry->ids() : array_values($this->argument('agents'));
        if ($agents === [] && $this->input->isInteractive()) {
            $agents = $this->choice('Select coding agents', $registry->ids(), null, null, true);
        }
        if ($agents === []) {
            $this->error('Select at least one coding agent or pass --all.');
            return self::FAILURE;
        }

        try {
            $result = $installer->install((string) ($this->option('path') ?: base_path()), $agents, (bool) $this->option('force'));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        foreach (['created', 'unchanged', 'overwritten', 'conflicts'] as $status) {
            foreach ($result->{$status} as $path) $this->line(strtoupper($status) . " {$path}");
        }
        return $result->successful() ? self::SUCCESS : self::FAILURE;
    }
}
```

- [ ] **Step 4: Register generation services and the command**

In `registerRefactoring()` bind singleton repository, renderer, registry, and installer. The repository root is:

```php
__DIR__ . '/../resources/agents/refactoring'
```

The registry receives `new CursorAgentAdapter()` and `new ClaudeCodeAgentAdapter()`. Register `InstallAgentsCommand::class` in `boot()`.

- [ ] **Step 5: Run command and full feature tests**

```bash
vendor/bin/phpunit tests/Feature/Refactoring
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Refactoring/Commands/InstallAgentsCommand.php src/AgentKitServiceProvider.php tests/Feature/Refactoring/InstallAgentsCommandTest.php
git commit -m "feat: add coding-agent installer command"
```

## Task 9: Document usage, validate safety, and finish the branch

**Files:**

- Modify: `README.md`
- Modify: `REFACTORING_AGENT.md`
- Modify: tests only if final verification reveals a missing specification assertion

- [ ] **Step 1: Add documentation assertions before editing docs**

Create a data-provider test in `tests/Feature/Refactoring/InstallAgentsCommandTest.php` that reads both documentation files and requires:

```php
#[DataProvider('documentationFiles')]
public function test_documentation_covers_agent_installation_and_fallback(string $file): void
{
    $content = file_get_contents($file);
    $this->assertStringContainsString('Using Refactoring Agent with Coding Agents', $content);
    $this->assertStringContainsString('agent-kit:agents:install', $content);
    $this->assertStringContainsString('/refactor-impact', $content);
    $this->assertStringContainsString('agent-kit:refactor-impact', $content);
    $this->assertStringContainsString('MCP', $content);
}

public static function documentationFiles(): array
{
    $root = dirname(__DIR__, 3);
    return ['README' => ["{$root}/README.md"], 'guide' => ["{$root}/REFACTORING_AGENT.md"]];
}
```

- [ ] **Step 2: Run the documentation test and verify RED**

```bash
vendor/bin/phpunit tests/Feature/Refactoring/InstallAgentsCommandTest.php
```

Expected: FAIL on missing documentation section.

- [ ] **Step 3: Update README and Refactoring Agent guide**

Add this section to both documents, expanding the command descriptions in `REFACTORING_AGENT.md` where that guide already contains more detail:

````markdown
## Using Refactoring Agent with Coding Agents

Install native Agent Skills and persistent rules for one or both supported coding agents:

```bash
php artisan agent-kit:agents:install cursor
php artisan agent-kit:agents:install claude
php artisan agent-kit:agents:install --all
```

The installer provides `/refactor-audit`, `/refactor-analyze`, `/refactor-callers`, `/refactor-dependencies`, `/refactor-impact`, and `/refactor-plan`. For example:

```text
/refactor-impact App\Services\PaymentService::charge
```

The skills prefer deterministic MCP tools, then Agent Kit CLI with `--json`, then repository search/read, and finally LLM inference. Their responses distinguish FACTS, INTERPRETATION, and RECOMMENDATIONS.

```text
                 Refactoring Core
                       |
        +--------------+--------------+
        v              v              v
       CLI        Coding Agents       MCP
```

Without MCP, use the CLI directly:

```bash
php artisan agent-kit:refactor-impact "App\Services\PaymentService::charge" --json
```

Existing differing skill or rule files are reported as conflicts and are not overwritten unless `--force` is explicit. Static analysis marks unresolved dynamic behavior instead of guessing runtime targets. `/refactor-apply` is not available. The next architectural step is an MCP server over the same Refactoring Core capabilities.
````

- [ ] **Step 4: Run focused tests and verify GREEN**

```bash
vendor/bin/phpunit tests/Feature/Refactoring tests/Unit/Refactoring
```

Expected: PASS.

- [ ] **Step 5: Run complete verification**

```bash
composer validate --strict
vendor/bin/phpunit
git diff --check origin/main...HEAD
```

Expected: Composer manifest valid; all tests pass; diff check emits no output. Existing PHPUnit deprecations may remain only if their count and source are unchanged from the branch baseline.

- [ ] **Step 6: Verify generated skills never instruct source modification**

```bash
rg -n "modify source|edit source|apply refactor|rewrite" resources/agents/refactoring tests/Fixtures/Refactoring/Agents/Expected
```

Expected: only prohibitions such as “Do not modify source files”; no positive source-edit instruction.

- [ ] **Step 7: Commit documentation**

```bash
git add README.md REFACTORING_AGENT.md tests/Feature/Refactoring/InstallAgentsCommandTest.php
git commit -m "docs: explain refactoring skills for coding agents"
```

- [ ] **Step 8: Push the updated PR branch after final review**

```bash
git status --short --branch
git push origin codex/refactoring-ast-graph-pr
```

Expected: clean worktree before push; PR #2 updates with the new commits.
