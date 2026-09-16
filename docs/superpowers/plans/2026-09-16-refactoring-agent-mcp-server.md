# Refactoring Agent MCP Server Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose the six existing `RefactoringCapabilities` operations and one discovery resource through an MCP server (stdio + Streamable HTTP) built on the official `mcp/sdk` PHP package, with a fingerprint-validated in-memory index cache and bearer-protected, loopback-only-by-default HTTP.

**Architecture:** A thin adapter namespace `Peralta\AgentKit\Refactoring\Mcp` declares tool/resource definitions as data (`RefactoringToolCatalog`), maps `tools/call` to `RefactoringCapabilities` (`RefactoringToolHandler`), and builds the SDK `Mcp\Server` from the Laravel container (`McpServerFactory`). Transports are SDK components: `StdioTransport` driven by an Artisan command, and `StreamableHttpTransport` hosted by a persistent ReactPHP listener behind a PSR-15 stack (CORS → DNS-rebinding/Origin → bearer auth). A `CachedCodebaseIndexer` decorator reuses the `CodebaseIndex` across calls while a content fingerprint proves nothing changed.

**Tech Stack:** PHP ^8.2, Laravel/Illuminate 10–12, `mcp/sdk ^0.8.1`, `react/http ^1.11` (optional, dev), `guzzlehttp/psr7 ^2` (already present; PSR-7/PSR-17), PHPUnit 10/11, Orchestra Testbench (incl. `vendor/bin/testbench` CLI for subprocess tests).

**Spec:** `docs/superpowers/specs/2026-09-16-refactoring-agent-mcp-server-design.md`

## Global Constraints

- `composer.json` `require` gains `"mcp/sdk": "^0.8.1"`; `require-dev` gains `"react/http": "^1.11"`; `suggest` gains `react/http`. No other runtime dependency. `composer.lock` is committed.
- PHP `^8.2`, Laravel 10–12 support unchanged; no new PSR-7 implementation (`guzzlehttp/psr7` via `php-http/discovery`).
- Tool names, exactly and only: `refactoring_capabilities`, `refactoring_audit`, `refactoring_analyze`, `refactoring_callers`, `refactoring_dependencies`, `refactoring_impact`. Resource URI: `agent-kit://refactoring/capabilities`. No tool may write, apply, mutate, or execute anything.
- Every tool result carries the existing envelope unchanged in `structuredContent` (`schema_version` stays `1.0`); domain errors are `CallToolResult(isError: true)` with the existing error envelope.
- The MCP adapter depends on `RefactoringCapabilities` only; it never references `Illuminate\Console`, `Artisan`, or parses CLI output.
- The server root is fixed at start-up; tools never accept a root argument.
- stdio: nothing but JSON-RPC lines on stdout; logs and diagnostics on stderr; start-up failures exit `1`.
- HTTP: disabled unless `AGENT_KIT_MCP_HTTP_ENABLED=true`; loopback bind unless `--allow-remote`; bearer token (≥ 32 chars) always required; token never logged, echoed, committed, or read from the query string.
- Commit messages: `tipo: descrição` (Conventional-style, per `CONTRIBUTING.md`) followed by a blank line and `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- Existing tests, CLI contracts, installers and golden fixtures keep passing (fixtures are regenerated only where template wording changes).
- All test commands run from the package root `/Users/alanperalta/www/agent-kit` on branch `codex/refactoring-mcp-server`.

## File map

### Dependencies and configuration
- Modify: `composer.json`, `composer.lock` — SDK + optional ReactPHP.
- Modify: `config/agent-kit.php` — new `mcp` section.
- Modify: `.env.example` — MCP variables (empty token).

### Index cache (analysis layer)
- Create: `src/Refactoring/Analysis/Index/CodebaseIndexBuilder.php` — interface `build(string $root): CodebaseIndex`.
- Modify: `src/Refactoring/Analysis/Index/CodebaseIndexer.php` — implements the interface.
- Create: `src/Refactoring/Analysis/Index/ProjectFingerprint.php` — content fingerprint over scanner-selected files.
- Create: `src/Refactoring/Analysis/Index/CachedCodebaseIndexer.php` — LRU cache decorator.
- Modify: `src/Refactoring/Application/DefaultRefactoringCapabilities.php` — constructor types the interface; descriptors gain `mcp_tool`.
- Modify: `src/Refactoring/Commands/RefactorCapabilitiesCommand.php` — prints the MCP tool column.

### MCP adapter
- Create: `src/Refactoring/Mcp/McpConfigurationException.php`
- Create: `src/Refactoring/Mcp/McpProjectRoot.php`
- Create: `src/Refactoring/Mcp/ToolDefinition.php`
- Create: `src/Refactoring/Mcp/RefactoringToolCatalog.php`
- Create: `src/Refactoring/Mcp/RefactoringToolHandler.php`
- Create: `src/Refactoring/Mcp/CapabilitiesResourceHandler.php`
- Create: `src/Refactoring/Mcp/McpLoggerFactory.php`
- Create: `src/Refactoring/Mcp/McpServerFactory.php`
- Create: `src/Refactoring/Mcp/Transport/StdioServerRunner.php`
- Create: `src/Refactoring/Mcp/Transport/Http/HttpServerOptions.php`
- Create: `src/Refactoring/Mcp/Transport/Http/StaticBearerTokenValidator.php`
- Create: `src/Refactoring/Mcp/Transport/Http/BearerTokenAuthenticationMiddleware.php`
- Create: `src/Refactoring/Mcp/Transport/Http/BoundedInMemorySessionStore.php`
- Create: `src/Refactoring/Mcp/Transport/Http/HttpTransportFactory.php`
- Create: `src/Refactoring/Mcp/Transport/Http/ReactHttpListener.php`
- Create: `src/Refactoring/Mcp/Commands/McpServeCommand.php`
- Modify: `src/AgentKitServiceProvider.php` — `registerMcp()`, index cache bindings, command registration.

### Templates, docs
- Modify: `resources/agents/refactoring/instructions.md`, `resources/agents/refactoring/commands/*.md`, golden fixtures under `tests/Fixtures/Refactoring/Agents/Expected/`.
- Create: `MCP_SERVER.md`. Modify: `README.md`, `REFACTORING_AGENT.md`, `SETUP.md`, `CHANGELOG.md`.

### Tests
- `tests/Feature/Mcp/McpConfigurationTest.php`
- `tests/Unit/Refactoring/CachedCodebaseIndexerTest.php`, `tests/Feature/Refactoring/IndexCacheBindingTest.php`
- `tests/Unit/Refactoring/Mcp/{RefactoringToolCatalogTest,McpProjectRootTest,RefactoringToolHandlerTest,CapabilitiesResourceHandlerTest,RecordingCapabilities}.php`
- `tests/Unit/Refactoring/Mcp/Http/{HttpServerOptionsTest,StaticBearerTokenValidatorTest,BearerTokenAuthenticationMiddlewareTest,BoundedInMemorySessionStoreTest}.php`
- `tests/Feature/Mcp/{McpServerProtocolTest,StdioServerCommandTest,HttpTransportPipelineTest,HttpListenerCommandTest}.php`
- `tests/Feature/Mcp/Concerns/SpawnsMcpServer.php`

---

### Task 1: Dependencies and configuration

**Files:**
- Modify: `composer.json`, `composer.lock`
- Modify: `config/agent-kit.php` (append after the `analytics` section)
- Modify: `.env.example` (append after the Analytics block)
- Test: `tests/Feature/Mcp/McpConfigurationTest.php`

**Interfaces:**
- Produces: `config('agent-kit.mcp')` with the keys listed in the test below; every later task reads them.

- [ ] **Step 1: Write the failing configuration test**

```php
<?php

namespace Peralta\AgentKit\Tests\Feature\Mcp;

use Peralta\AgentKit\Tests\TestCase;

final class McpConfigurationTest extends TestCase
{
    public function test_mcp_defaults_are_local_only_and_http_is_disabled(): void
    {
        $config = config('agent-kit.mcp');

        self::assertTrue($config['enabled']);
        self::assertSame('stdio', $config['transport']);
        self::assertNull($config['project_root']);
        self::assertFalse($config['http']['enabled']);
        self::assertSame('127.0.0.1', $config['http']['host']);
        self::assertSame(8787, $config['http']['port']);
        self::assertSame('/mcp', $config['http']['path']);
        self::assertFalse($config['http']['allow_remote']);
        self::assertSame('', $config['http']['allowed_origins']);
        self::assertNull($config['http']['bearer_token']);
        self::assertSame(1048576, $config['http']['max_body_bytes']);
        self::assertSame(60, $config['http']['idle_timeout']);
        self::assertSame(4, $config['http']['max_concurrent_requests']);
        self::assertSame(3600, $config['http']['session_ttl']);
        self::assertSame(100, $config['http']['max_sessions']);
        self::assertSame(1, $config['index_cache']['max_entries']);
        self::assertSame('info', $config['logging']['level']);
        self::assertNull($config['logging']['channel']);
    }

    public function test_the_sdk_is_installed(): void
    {
        self::assertTrue(class_exists(\Mcp\Server::class));
        self::assertTrue(class_exists(\React\Http\HttpServer::class), 'react/http must be a dev dependency so the HTTP listener is tested.');
    }
}
```

- [ ] **Step 2: Run it and verify RED**

Run: `vendor/bin/phpunit tests/Feature/Mcp/McpConfigurationTest.php`
Expected: FAIL (`Undefined array key "mcp"` / `Mcp\Server` missing).

- [ ] **Step 3: Add the dependencies**

Run:

```bash
composer require mcp/sdk:^0.8.1 --no-interaction
composer require --dev react/http:^1.11 -W --no-interaction
```

`-W` is required: `react/http` needs `psr/http-message ^1.0`, so the lock downgrades `psr/http-message` 2.0 → 1.1 (accepted by every current dependency). `mcp/sdk` hard-requires `ext-fileinfo`, so declare it too, and pin the `php-http/discovery` Composer plugin decision so installs stay non-interactive (factories are always passed explicitly in this package, the plugin is not needed). Edit `composer.json`:

```json
"require": {
    "php": "^8.2",
    "ext-fileinfo": "*",
    "illuminate/contracts": "^10.0|^11.0|^12.0",
    "illuminate/support": "^10.0|^11.0|^12.0",
    "illuminate/database": "^10.0|^11.0|^12.0",
    "illuminate/http": "^10.0|^11.0|^12.0",
    "guzzlehttp/guzzle": "^7.0",
    "nikic/php-parser": "^5.8",
    "mcp/sdk": "^0.8.1"
},
"config": {
    "allow-plugins": {
        "php-http/discovery": false
    }
},
```

(keep the existing keys; `composer require` already added `mcp/sdk` and `react/http`). Then edit `suggest`:

```json
"suggest": {
    "pgvector/pgvector": "Required for pgvector knowledge store",
    "smalot/pdfparser": "For extracting text from PDF documents",
    "phpoffice/phpword": "For extracting text from DOCX documents",
    "react/http": "Required to serve the MCP server over Streamable HTTP (php artisan agent-kit:mcp --transport=http)"
}
```

Verify: `composer validate --strict` prints `./composer.json is valid`.

- [ ] **Step 4: Add the `mcp` configuration section**

Append to `config/agent-kit.php` after the `analytics` array (before the closing `];`):

```php
    /*
    |--------------------------------------------------------------------------
    | MCP Server (Refactoring Agent)
    |--------------------------------------------------------------------------
    | Expõe as capabilities de refactoring a clientes MCP (Claude Code, Cursor,
    | Codex, MCP Inspector). stdio é o transporte padrão. HTTP é opt-in, faz
    | bind apenas em loopback por padrão e sempre exige bearer token.
    */
    'mcp' => [
        'enabled' => env('AGENT_KIT_MCP_ENABLED', true),
        'transport' => env('AGENT_KIT_MCP_TRANSPORT', 'stdio'),
        // null = base_path() da aplicação Laravel que hospeda o pacote
        'project_root' => env('AGENT_KIT_MCP_PROJECT_ROOT'),

        'http' => [
            'enabled' => env('AGENT_KIT_MCP_HTTP_ENABLED', false),
            'host' => env('AGENT_KIT_MCP_HTTP_HOST', '127.0.0.1'),
            'port' => (int) env('AGENT_KIT_MCP_HTTP_PORT', 8787),
            'path' => env('AGENT_KIT_MCP_HTTP_PATH', '/mcp'),
            // Bind fora de loopback exige opt-in explícito; o token continua obrigatório
            'allow_remote' => (bool) env('AGENT_KIT_MCP_ALLOW_REMOTE', false),
            // Hosts/origins adicionais permitidos (separados por vírgula); loopback já é permitido
            'allowed_origins' => env('AGENT_KIT_MCP_ALLOWED_ORIGINS', ''),
            // Mínimo de 32 caracteres. Gere com: php -r 'echo bin2hex(random_bytes(32));'
            'bearer_token' => env('AGENT_KIT_MCP_BEARER_TOKEN'),
            'max_body_bytes' => (int) env('AGENT_KIT_MCP_HTTP_MAX_BODY_BYTES', 1048576),
            'idle_timeout' => (int) env('AGENT_KIT_MCP_HTTP_IDLE_TIMEOUT', 60),
            'max_concurrent_requests' => (int) env('AGENT_KIT_MCP_HTTP_MAX_CONCURRENT', 4),
            'session_ttl' => (int) env('AGENT_KIT_MCP_HTTP_SESSION_TTL', 3600),
            'max_sessions' => (int) env('AGENT_KIT_MCP_HTTP_MAX_SESSIONS', 100),
        ],

        'index_cache' => [
            // Índices AST mantidos em memória por processo (um por raiz de projeto)
            'max_entries' => (int) env('AGENT_KIT_MCP_INDEX_CACHE_MAX_ENTRIES', 1),
        ],

        'logging' => [
            'level' => env('AGENT_KIT_MCP_LOG_LEVEL', 'info'),
            // null = stderr (obrigatório para stdio); ou um canal de config/logging.php
            'channel' => env('AGENT_KIT_MCP_LOG_CHANNEL'),
        ],
    ],
```

- [ ] **Step 5: Document the variables in `.env.example`**

Append after the Analytics block (before `# EXEMPLOS DE CONFIGURAÇÃO`):

```dotenv
# ============================================================================
# MCP SERVER (Refactoring Agent para Claude Code, Cursor, Codex, Inspector)
# ============================================================================

AGENT_KIT_MCP_ENABLED=true
# stdio (padrão) ou http
AGENT_KIT_MCP_TRANSPORT=stdio
# Raiz do projeto analisado; vazio = base_path()
AGENT_KIT_MCP_PROJECT_ROOT=

# Streamable HTTP: opt-in, loopback por padrão, bearer token obrigatório
AGENT_KIT_MCP_HTTP_ENABLED=false
AGENT_KIT_MCP_HTTP_HOST=127.0.0.1
AGENT_KIT_MCP_HTTP_PORT=8787
AGENT_KIT_MCP_HTTP_PATH=/mcp
AGENT_KIT_MCP_ALLOW_REMOTE=false
# Hosts/origins extras permitidos, separados por vírgula (ex.: http://localhost:6274,mcp.internal)
AGENT_KIT_MCP_ALLOWED_ORIGINS=
# Mínimo 32 caracteres. Gere com: php -r 'echo bin2hex(random_bytes(32));'
AGENT_KIT_MCP_BEARER_TOKEN=
AGENT_KIT_MCP_HTTP_MAX_BODY_BYTES=1048576
AGENT_KIT_MCP_HTTP_IDLE_TIMEOUT=60
AGENT_KIT_MCP_HTTP_MAX_CONCURRENT=4
AGENT_KIT_MCP_HTTP_SESSION_TTL=3600
AGENT_KIT_MCP_HTTP_MAX_SESSIONS=100

AGENT_KIT_MCP_INDEX_CACHE_MAX_ENTRIES=1
# Nível de log (stderr por padrão)
AGENT_KIT_MCP_LOG_LEVEL=info
AGENT_KIT_MCP_LOG_CHANNEL=
```

- [ ] **Step 6: Run the test and verify GREEN**

Run: `vendor/bin/phpunit tests/Feature/Mcp/McpConfigurationTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 7: Run the whole suite to prove nothing regressed with the lock change**

Run: `vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add composer.json composer.lock config/agent-kit.php .env.example tests/Feature/Mcp/McpConfigurationTest.php
git commit -m "chore: add mcp/sdk dependency and MCP configuration

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

### Task 2: Fingerprint-validated index cache

**Files:**
- Create: `src/Refactoring/Analysis/Index/CodebaseIndexBuilder.php`
- Create: `src/Refactoring/Analysis/Index/ProjectFingerprint.php`
- Create: `src/Refactoring/Analysis/Index/CachedCodebaseIndexer.php`
- Modify: `src/Refactoring/Analysis/Index/CodebaseIndexer.php:15`
- Modify: `src/Refactoring/Application/DefaultRefactoringCapabilities.php:18-25`
- Modify: `src/AgentKitServiceProvider.php:254-285`
- Test: `tests/Unit/Refactoring/CachedCodebaseIndexerTest.php`, `tests/Feature/Refactoring/IndexCacheBindingTest.php`

**Interfaces:**
- Produces: `interface CodebaseIndexBuilder { public function build(string $root): CodebaseIndex; }`; `final class ProjectFingerprint { __construct(ProjectScanner $scanner); compute(string $root): string }`; `final class CachedCodebaseIndexer implements CodebaseIndexBuilder { __construct(CodebaseIndexBuilder $inner, ProjectFingerprint $fingerprint, int $maxEntries = 1); build(); clear(): void; count(): int }`. Container: `CodebaseIndexBuilder::class` resolves to the `CachedCodebaseIndexer` singleton.

- [ ] **Step 1: Write the failing cache tests**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring;

use Peralta\AgentKit\Refactoring\Analysis\Ast\PhpAstParser;
use Peralta\AgentKit\Refactoring\Analysis\Index\CachedCodebaseIndexer;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndex;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexBuilder;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexer;
use Peralta\AgentKit\Refactoring\Analysis\Index\ProjectFingerprint;
use Peralta\AgentKit\Refactoring\Support\PhpFileAnalyzer;
use Peralta\AgentKit\Refactoring\Support\ProjectScanner;
use PHPUnit\Framework\TestCase;

final class CachedCodebaseIndexerTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->directories) as $directory) {
            foreach (glob($directory . '/*.php') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
        parent::tearDown();
    }

    public function test_an_unchanged_project_reuses_the_same_index_instance(): void
    {
        $root = $this->project(['Service.php' => '<?php namespace Demo; class Service {}']);
        $inner = $this->countingBuilder();
        $cache = $this->cache($inner);

        $first = $cache->build($root);
        $second = $cache->build($root);

        self::assertSame($first, $second);
        self::assertSame(1, $inner->builds);
        self::assertSame(1, $cache->count());
    }

    public function test_a_same_size_content_change_invalidates_the_index(): void
    {
        $root = $this->project(['Service.php' => '<?php namespace Demo; class Servica {}']);
        $inner = $this->countingBuilder();
        $cache = $this->cache($inner);
        $cache->build($root);

        // Same byte length and same second: only a content fingerprint can notice this.
        file_put_contents($root . '/Service.php', '<?php namespace Demo; class Service {}');
        $index = $cache->build($root);

        self::assertSame(2, $inner->builds);
        self::assertNotNull($index->findClass('Demo\\Service'));
        self::assertNull($index->findClass('Demo\\Servica'));
    }

    public function test_created_and_removed_files_invalidate_the_index(): void
    {
        $root = $this->project(['Service.php' => '<?php namespace Demo; class Service {}']);
        $inner = $this->countingBuilder();
        $cache = $this->cache($inner);
        $cache->build($root);

        file_put_contents($root . '/Extra.php', '<?php namespace Demo; class Extra {}');
        $withExtra = $cache->build($root);
        self::assertSame(2, $inner->builds);
        self::assertNotNull($withExtra->findClass('Demo\\Extra'));

        unlink($root . '/Extra.php');
        $withoutExtra = $cache->build($root);
        self::assertSame(3, $inner->builds);
        self::assertNull($withoutExtra->findClass('Demo\\Extra'));
    }

    public function test_different_roots_never_share_entries(): void
    {
        $first = $this->project(['A.php' => '<?php namespace Demo; class A {}']);
        $second = $this->project(['B.php' => '<?php namespace Demo; class B {}']);
        $cache = $this->cache($this->countingBuilder(), 2);

        $firstIndex = $cache->build($first);
        $secondIndex = $cache->build($second);

        self::assertNotNull($firstIndex->findClass('Demo\\A'));
        self::assertNull($firstIndex->findClass('Demo\\B'));
        self::assertNotNull($secondIndex->findClass('Demo\\B'));
        self::assertSame(2, $cache->count());
    }

    public function test_the_entry_bound_evicts_the_least_recently_used_root(): void
    {
        $first = $this->project(['A.php' => '<?php namespace Demo; class A {}']);
        $second = $this->project(['B.php' => '<?php namespace Demo; class B {}']);
        $inner = $this->countingBuilder();
        $cache = $this->cache($inner, 1);

        $cache->build($first);
        $cache->build($second);
        self::assertSame(1, $cache->count());
        $cache->build($first);

        self::assertSame(3, $inner->builds);
    }

    public function test_a_rebuilt_index_equals_a_fresh_index(): void
    {
        $root = $this->project([
            'Service.php' => '<?php namespace Demo; class Service { public function run(): void {} }',
            'Caller.php' => '<?php namespace Demo; class Caller { public function __construct(private Service $s) {} public function go(): void { $this->s->run(); } }',
        ]);
        $cache = $this->cache($this->countingBuilder());
        $cache->build($root);
        file_put_contents($root . '/Other.php', '<?php namespace Demo; class Other {}');

        $cached = $cache->build($root);
        $fresh = $this->realIndexer()->build($root);

        self::assertEquals($fresh, $cached);
    }

    public function test_clear_forgets_every_entry(): void
    {
        $root = $this->project(['A.php' => '<?php namespace Demo; class A {}']);
        $inner = $this->countingBuilder();
        $cache = $this->cache($inner);
        $cache->build($root);

        $cache->clear();
        $cache->build($root);

        self::assertSame(2, $inner->builds);
    }

    public function test_it_rejects_a_zero_entry_bound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cache($this->countingBuilder(), 0);
    }

    private function cache(CodebaseIndexBuilder $inner, int $maxEntries = 1): CachedCodebaseIndexer
    {
        return new CachedCodebaseIndexer($inner, new ProjectFingerprint($this->scanner()), $maxEntries);
    }

    private function realIndexer(): CodebaseIndexer
    {
        return new CodebaseIndexer($this->scanner(), new PhpAstParser());
    }

    private function scanner(): ProjectScanner
    {
        return new ProjectScanner(new PhpFileAnalyzer());
    }

    /** @return CodebaseIndexBuilder&object{builds: int} */
    private function countingBuilder(): CodebaseIndexBuilder
    {
        return new class($this->realIndexer()) implements CodebaseIndexBuilder {
            public int $builds = 0;

            public function __construct(private readonly CodebaseIndexBuilder $inner) {}

            public function build(string $root): CodebaseIndex
            {
                $this->builds++;

                return $this->inner->build($root);
            }
        };
    }

    /** @param array<string, string> $files */
    private function project(array $files): string
    {
        $directory = sys_get_temp_dir() . '/agent-kit-index-cache-' . bin2hex(random_bytes(6));
        mkdir($directory, 0777, true);
        $this->directories[] = $directory;
        foreach ($files as $name => $contents) {
            file_put_contents($directory . '/' . $name, $contents);
        }

        return $directory;
    }
}
```

And the binding test:

```php
<?php

namespace Peralta\AgentKit\Tests\Feature\Refactoring;

use Peralta\AgentKit\Refactoring\Analysis\Index\CachedCodebaseIndexer;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexBuilder;
use Peralta\AgentKit\Refactoring\Application\DefaultRefactoringCapabilities;
use Peralta\AgentKit\Tests\TestCase;
use ReflectionClass;

final class IndexCacheBindingTest extends TestCase
{
    public function test_the_index_builder_is_a_process_wide_cached_singleton(): void
    {
        $first = $this->app->make(CodebaseIndexBuilder::class);
        $second = $this->app->make(CodebaseIndexBuilder::class);

        self::assertInstanceOf(CachedCodebaseIndexer::class, $first);
        self::assertSame($first, $second);
    }

    public function test_capabilities_depend_on_the_builder_interface(): void
    {
        $parameters = (new ReflectionClass(DefaultRefactoringCapabilities::class))->getConstructor()->getParameters();
        $types = array_map(fn ($parameter) => $parameter->getType()?->getName(), $parameters);

        self::assertContains(CodebaseIndexBuilder::class, $types);
    }
}
```

- [ ] **Step 2: Run them and verify RED**

Run: `vendor/bin/phpunit tests/Unit/Refactoring/CachedCodebaseIndexerTest.php tests/Feature/Refactoring/IndexCacheBindingTest.php`
Expected: FAIL (classes missing).

- [ ] **Step 3: Add the interface and make `CodebaseIndexer` implement it**

`src/Refactoring/Analysis/Index/CodebaseIndexBuilder.php`:

```php
<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Index;

interface CodebaseIndexBuilder
{
    public function build(string $root): CodebaseIndex;
}
```

In `CodebaseIndexer.php` change the class line to `final class CodebaseIndexer implements CodebaseIndexBuilder`.

- [ ] **Step 4: Add `ProjectFingerprint`**

```php
<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Index;

use Peralta\AgentKit\Refactoring\Support\ProjectRoot;
use Peralta\AgentKit\Refactoring\Support\ProjectScanner;

final class ProjectFingerprint
{
    public function __construct(private readonly ProjectScanner $scanner) {}

    /**
     * Content fingerprint of every PHP file the indexer would parse. Content hashes
     * (not mtimes) are used on purpose: two edits inside the same second, or a
     * restored file with the same size, must still invalidate the cache.
     */
    public function compute(string $root): string
    {
        $root = ProjectRoot::normalize($root);
        $context = hash_init('xxh128');

        foreach ($this->scanner->phpFiles($root) as $file) {
            $size = is_file($file) ? filesize($file) : false;
            $digest = is_file($file) ? hash_file('xxh128', $file) : false;
            hash_update($context, implode("\0", [
                ProjectRoot::relative($root, $file),
                $size === false ? 'missing' : (string) $size,
                $digest === false ? 'missing' : $digest,
            ]) . "\n");
        }

        return hash_final($context);
    }
}
```

- [ ] **Step 5: Add `CachedCodebaseIndexer`**

```php
<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Index;

use InvalidArgumentException;
use Peralta\AgentKit\Refactoring\Support\ProjectRoot;

final class CachedCodebaseIndexer implements CodebaseIndexBuilder
{
    /** @var array<string, array{fingerprint: string, index: CodebaseIndex}> insertion order doubles as LRU order */
    private array $entries = [];

    public function __construct(
        private readonly CodebaseIndexBuilder $inner,
        private readonly ProjectFingerprint $fingerprint,
        private readonly int $maxEntries = 1,
    ) {
        if ($maxEntries < 1) {
            throw new InvalidArgumentException('The index cache must keep at least one entry.');
        }
    }

    public function build(string $root): CodebaseIndex
    {
        $key = ProjectRoot::normalize($root);
        $fingerprint = $this->fingerprint->compute($key);
        $entry = $this->entries[$key] ?? null;

        if ($entry !== null && $entry['fingerprint'] === $fingerprint) {
            unset($this->entries[$key]);
            $this->entries[$key] = $entry;

            return $entry['index'];
        }

        $index = $this->inner->build($key);
        unset($this->entries[$key]);
        $this->entries[$key] = ['fingerprint' => $fingerprint, 'index' => $index];

        while (count($this->entries) > $this->maxEntries) {
            unset($this->entries[array_key_first($this->entries)]);
        }

        return $index;
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
```

- [ ] **Step 6: Type the interface in `DefaultRefactoringCapabilities` and bind the cache**

In `DefaultRefactoringCapabilities.php` replace `use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexer;` with `use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexBuilder;` and the constructor parameter `private readonly CodebaseIndexer $indexer,` with `private readonly CodebaseIndexBuilder $indexer,`.

In `AgentKitServiceProvider::registerRefactoring()` add the imports `CachedCodebaseIndexer`, `CodebaseIndexBuilder`, `ProjectFingerprint` and replace the `CodebaseIndexer` binding block with:

```php
        $this->app->bind(CodebaseIndexer::class, fn ($app) => new CodebaseIndexer(
            $app->make(ProjectScanner::class),
            $app->make(AstParser::class),
        ));
        $this->app->singleton(ProjectFingerprint::class, fn ($app) => new ProjectFingerprint(
            $app->make(ProjectScanner::class),
        ));
        // One cache per process: the MCP server keeps it for its whole life, the CLI for one command.
        $this->app->singleton(CachedCodebaseIndexer::class, fn ($app) => new CachedCodebaseIndexer(
            $app->make(CodebaseIndexer::class),
            $app->make(ProjectFingerprint::class),
            max(1, (int) config('agent-kit.mcp.index_cache.max_entries', 1)),
        ));
        $this->app->bind(CodebaseIndexBuilder::class, fn ($app) => $app->make(CachedCodebaseIndexer::class));
```

and in the `RefactoringCapabilities` binding replace `$app->make(CodebaseIndexer::class)` with `$app->make(CodebaseIndexBuilder::class)`.

- [ ] **Step 7: Run the tests and verify GREEN**

Run: `vendor/bin/phpunit tests/Unit/Refactoring/CachedCodebaseIndexerTest.php tests/Feature/Refactoring/IndexCacheBindingTest.php tests/Unit/Refactoring tests/Feature/Refactoring`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Refactoring/Analysis/Index src/Refactoring/Application/DefaultRefactoringCapabilities.php src/AgentKitServiceProvider.php tests/Unit/Refactoring/CachedCodebaseIndexerTest.php tests/Feature/Refactoring/IndexCacheBindingTest.php
git commit -m "feat: cache codebase index by content fingerprint

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

### Task 3: Capability descriptors name their MCP tool

**Files:**
- Modify: `src/Refactoring/Application/DefaultRefactoringCapabilities.php:27-63`
- Modify: `src/Refactoring/Commands/RefactorCapabilitiesCommand.php:27-35`
- Modify: `tests/Unit/Refactoring/Application/DefaultRefactoringCapabilitiesTest.php:28-65`
- Modify: `tests/Feature/Refactoring/RefactoringCommandsTest.php:46-68`

**Interfaces:**
- Produces: every descriptor in `describeCapabilities()->data['capabilities']` has `'mcp_tool'` (`refactoring_audit`, `refactoring_analyze`, `refactoring_callers`, `refactoring_dependencies`, `refactoring_impact`) between `targets` and `cli_fallback`. Task 4 asserts a bijection against these names.

- [ ] **Step 1: Update the exact-equality unit test**

In `test_it_describes_the_ordered_capability_contract` insert the key in each descriptor, e.g. for audit:

```php
            [
                'name' => 'audit',
                'targets' => ['project'],
                'mcp_tool' => 'refactoring_audit',
                'cli_fallback' => 'php artisan agent-kit:refactor-audit --json',
                'json' => true,
            ],
```

and likewise `refactoring_analyze`, `refactoring_callers` (for `find_callers`), `refactoring_dependencies`, `refactoring_impact`. In `RefactoringCommandsTest::test_capability_discovery_emits_the_versioned_json_envelope_only` add after the names assertion:

```php
        self::assertSame(
            ['refactoring_audit', 'refactoring_analyze', 'refactoring_callers', 'refactoring_dependencies', 'refactoring_impact'],
            array_column($decoded['data']['capabilities'], 'mcp_tool'),
        );
```

- [ ] **Step 2: Run and verify RED**

Run: `vendor/bin/phpunit tests/Unit/Refactoring/Application/DefaultRefactoringCapabilitiesTest.php tests/Feature/Refactoring/RefactoringCommandsTest.php`
Expected: FAIL on the missing `mcp_tool` key.

- [ ] **Step 3: Add the key and the CLI column**

In `describeCapabilities()` add `'mcp_tool' => 'refactoring_audit',` after `'targets' => ['project'],` and the corresponding value for each of the other four descriptors. In `RefactorCapabilitiesCommand::handle()` change the table to:

```php
        $this->table(
            ['Capability', 'Targets', 'MCP tool', 'CLI fallback', 'JSON'],
            array_map(static fn (array $item): array => [
                $item['name'],
                implode(', ', $item['targets']),
                $item['mcp_tool'],
                $item['cli_fallback'],
                $item['json'] ? 'yes' : 'no',
            ], $result->data['capabilities']),
        );
```

- [ ] **Step 4: Run and verify GREEN**

Run: `vendor/bin/phpunit tests/Unit/Refactoring/Application tests/Feature/Refactoring`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Refactoring/Application/DefaultRefactoringCapabilities.php src/Refactoring/Commands/RefactorCapabilitiesCommand.php tests/Unit/Refactoring/Application/DefaultRefactoringCapabilitiesTest.php tests/Feature/Refactoring/RefactoringCommandsTest.php
git commit -m "feat: name the MCP tool in capability descriptors

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

### Task 4: Tool catalog (definitions, schemas, resource definition)

**Files:**
- Create: `src/Refactoring/Mcp/ToolDefinition.php`
- Create: `src/Refactoring/Mcp/RefactoringToolCatalog.php`
- Test: `tests/Unit/Refactoring/Mcp/RefactoringToolCatalogTest.php`

**Interfaces:**
- Consumes: `Mcp\Schema\Tool`, `Mcp\Schema\ToolAnnotations`, `Mcp\Schema\ResourceDefinition` (SDK), `RefactoringCapabilities::describeCapabilities()` (`mcp_tool` from Task 3).
- Produces: `final readonly class ToolDefinition { public string $name; public string $capability; public string $title; public string $description; public array $inputSchema; public array $outputSchema; requiresTarget(): bool; toTool(): Tool }`; `final class RefactoringToolCatalog { const RESOURCE_URI = 'agent-kit://refactoring/capabilities'; const RESOURCE_NAME = 'refactoring_capabilities'; tools(): list<ToolDefinition>; names(): list<string>; tool(string $name): ToolDefinition; resourceDefinition(): ResourceDefinition }`.

- [ ] **Step 1: Write the failing catalog tests**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp;

use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\Tool;
use Peralta\AgentKit\Refactoring\Analysis\Ast\PhpAstParser;
use Peralta\AgentKit\Refactoring\Analysis\CallerAnalyzer;
use Peralta\AgentKit\Refactoring\Analysis\ImpactAnalyzer;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexer;
use Peralta\AgentKit\Refactoring\Application\DefaultRefactoringCapabilities;
use Peralta\AgentKit\Refactoring\Mcp\RefactoringToolCatalog;
use Peralta\AgentKit\Refactoring\Support\PhpFileAnalyzer;
use Peralta\AgentKit\Refactoring\Support\ProjectScanner;
use Peralta\AgentKit\Refactoring\Support\RefactoringReport;
use PHPUnit\Framework\TestCase;

final class RefactoringToolCatalogTest extends TestCase
{
    public function test_it_exposes_exactly_the_six_read_only_tools_in_order(): void
    {
        self::assertSame([
            'refactoring_capabilities',
            'refactoring_audit',
            'refactoring_analyze',
            'refactoring_callers',
            'refactoring_dependencies',
            'refactoring_impact',
        ], (new RefactoringToolCatalog())->names());
    }

    public function test_no_tool_can_apply_write_or_execute_anything(): void
    {
        foreach ((new RefactoringToolCatalog())->tools() as $definition) {
            foreach (['apply', 'write', 'edit', 'mutate', 'delete', 'exec', 'shell', 'refresh'] as $verb) {
                self::assertStringNotContainsString($verb, $definition->name);
            }
            $tool = $definition->toTool();
            self::assertTrue($tool->annotations?->readOnlyHint);
            self::assertFalse($tool->annotations?->destructiveHint);
            self::assertTrue($tool->annotations?->idempotentHint);
            self::assertFalse($tool->annotations?->openWorldHint);
        }
    }

    public function test_target_tools_require_a_non_empty_target_and_reject_unknown_arguments(): void
    {
        $catalog = new RefactoringToolCatalog();

        foreach (['refactoring_analyze', 'refactoring_callers', 'refactoring_dependencies', 'refactoring_impact'] as $name) {
            $schema = $catalog->tool($name)->inputSchema;
            self::assertSame('object', $schema['type'], $name);
            self::assertSame(['target'], $schema['required'], $name);
            self::assertSame('string', $schema['properties']['target']['type'], $name);
            self::assertSame(1, $schema['properties']['target']['minLength'], $name);
            self::assertFalse($schema['additionalProperties'], $name);
            self::assertTrue($catalog->tool($name)->requiresTarget(), $name);
        }

        foreach (['refactoring_capabilities', 'refactoring_audit'] as $name) {
            $schema = $catalog->tool($name)->inputSchema;
            self::assertSame(['type' => 'object', 'properties' => [], 'additionalProperties' => false], $schema, $name);
            self::assertFalse($catalog->tool($name)->requiresTarget(), $name);
        }
    }

    public function test_every_output_schema_accepts_the_success_and_the_error_envelope(): void
    {
        foreach ((new RefactoringToolCatalog())->tools() as $definition) {
            $schema = $definition->outputSchema;
            self::assertSame('object', $schema['type'], $definition->name);
            self::assertCount(2, $schema['oneOf'], $definition->name);
            [$success, $error] = $schema['oneOf'];
            self::assertSame(
                ['schema_version', 'capability', 'incomplete', 'data', 'diagnostics', 'unresolved'],
                $success['required'],
                $definition->name,
            );
            self::assertSame($definition->capability, $success['properties']['capability']['const'], $definition->name);
            self::assertSame(['schema_version', 'error'], $error['required'], $definition->name);
            self::assertSame(['code', 'message'], $error['properties']['error']['required'], $definition->name);
        }
    }

    public function test_tool_definitions_serialize_with_schemas_and_annotations(): void
    {
        $tool = (new RefactoringToolCatalog())->tool('refactoring_impact')->toTool();
        $serialized = $tool->jsonSerialize();

        self::assertInstanceOf(Tool::class, $tool);
        self::assertSame('refactoring_impact', $serialized['name']);
        self::assertArrayHasKey('inputSchema', $serialized);
        self::assertArrayHasKey('outputSchema', $serialized);
        self::assertArrayHasKey('annotations', $serialized);
        self::assertNotSame('', $serialized['description']);
    }

    public function test_tools_map_one_to_one_onto_the_capability_descriptors(): void
    {
        $catalog = new RefactoringToolCatalog();
        $descriptors = $this->capabilities()->describeCapabilities()->data['capabilities'];

        foreach ($descriptors as $descriptor) {
            self::assertSame($descriptor['name'], $catalog->tool($descriptor['mcp_tool'])->capability, $descriptor['name']);
        }

        $catalogCapabilities = array_values(array_filter(
            array_map(fn ($definition) => $definition->capability, $catalog->tools()),
            fn (string $capability) => $capability !== 'capability_discovery',
        ));
        self::assertSame(array_column($descriptors, 'name'), $catalogCapabilities);
        self::assertSame('capability_discovery', $catalog->tool('refactoring_capabilities')->capability);
    }

    public function test_the_resource_definition_is_a_read_only_json_document(): void
    {
        $resource = (new RefactoringToolCatalog())->resourceDefinition();

        self::assertInstanceOf(ResourceDefinition::class, $resource);
        self::assertSame('agent-kit://refactoring/capabilities', $resource->uri);
        self::assertSame('refactoring_capabilities', $resource->name);
        self::assertSame('application/json', $resource->mimeType);
    }

    public function test_an_unknown_tool_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RefactoringToolCatalog())->tool('refactoring_apply');
    }

    private function capabilities(): DefaultRefactoringCapabilities
    {
        $analyzer = new PhpFileAnalyzer();
        $scanner = new ProjectScanner($analyzer);

        return new DefaultRefactoringCapabilities(
            $scanner,
            $analyzer,
            new RefactoringReport(),
            new CodebaseIndexer($scanner, new PhpAstParser()),
            new CallerAnalyzer(),
            new ImpactAnalyzer(),
        );
    }
}
```

- [ ] **Step 2: Run and verify RED**

Run: `vendor/bin/phpunit tests/Unit/Refactoring/Mcp/RefactoringToolCatalogTest.php`
Expected: FAIL (classes missing).

- [ ] **Step 3: Add `ToolDefinition`**

```php
<?php

namespace Peralta\AgentKit\Refactoring\Mcp;

use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;

final readonly class ToolDefinition
{
    public function __construct(
        public string $name,
        public string $capability,
        public string $title,
        public string $description,
        public array $inputSchema,
        public array $outputSchema,
    ) {}

    public function requiresTarget(): bool
    {
        return isset($this->inputSchema['properties']['target']);
    }

    public function toTool(): Tool
    {
        return new Tool(
            name: $this->name,
            title: $this->title,
            inputSchema: $this->inputSchema,
            description: $this->description,
            annotations: new ToolAnnotations(
                title: $this->title,
                readOnlyHint: true,
                destructiveHint: false,
                idempotentHint: true,
                openWorldHint: false,
            ),
            outputSchema: $this->outputSchema,
        );
    }
}
```

- [ ] **Step 4: Add `RefactoringToolCatalog`**

The `data` schemas mirror the arrays produced by `DefaultRefactoringCapabilities`, `RefactoringReport::build()`, `FileAnalysis::toArray()`, `DependencyEdge::toArray()`, `Reference::toArray()`, `ParseDiagnostic::toArray()`, `CallerResult::toArray()`, `ImpactResult::toArray()` and `DependencyGraph::transitiveDependents()`.

```php
<?php

namespace Peralta\AgentKit\Refactoring\Mcp;

use InvalidArgumentException;
use Mcp\Schema\ResourceDefinition;

final class RefactoringToolCatalog
{
    public const RESOURCE_URI = 'agent-kit://refactoring/capabilities';
    public const RESOURCE_NAME = 'refactoring_capabilities';

    private const TARGET_DESCRIPTION = 'Project-relative or absolute in-project PHP file (analyze only), fully qualified class name, or Class::method where the capability supports method scope. The path is resolved inside the fixed project root of this server.';

    private const NO_ARGUMENTS = ['type' => 'object', 'properties' => [], 'additionalProperties' => false];

    private const TARGET_ARGUMENT = [
        'type' => 'object',
        'properties' => [
            'target' => ['type' => 'string', 'minLength' => 1, 'description' => self::TARGET_DESCRIPTION],
        ],
        'required' => ['target'],
        'additionalProperties' => false,
    ];

    private const EDGE = [
        'type' => 'object',
        'properties' => [
            'source' => ['type' => 'string'],
            'source_method' => ['type' => ['string', 'null']],
            'target' => ['type' => ['string', 'null']],
            'target_method' => ['type' => ['string', 'null']],
            'type' => ['type' => 'string'],
            'confidence' => ['type' => 'string', 'enum' => ['exact', 'inferred', 'unknown']],
            'file' => ['type' => 'string'],
            'line' => ['type' => 'integer'],
            'metadata' => ['type' => ['object', 'array']],
        ],
        'required' => ['source', 'target', 'type', 'confidence', 'file', 'line'],
    ];

    private const EDGES = ['type' => 'array', 'items' => self::EDGE];

    private const TRANSITIVE = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => [
                'fqcn' => ['type' => 'string'],
                'file' => ['type' => 'string'],
                'depth' => ['type' => 'integer'],
                'path' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['fqcn', 'file', 'depth', 'path'],
        ],
    ];

    private const DIAGNOSTIC = [
        'type' => 'object',
        'properties' => [
            'file' => ['type' => 'string'],
            'line' => ['type' => ['integer', 'null']],
            'message' => ['type' => 'string'],
        ],
        'required' => ['file', 'message'],
    ];

    private const METRICS = [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string'],
            'lines' => ['type' => 'integer'],
            'methods' => ['type' => 'integer'],
            'dependencies' => ['type' => 'integer'],
            'branches' => ['type' => 'integer'],
            'smells' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'severity' => ['type' => 'string'],
                    'reason' => ['type' => 'string'],
                ],
                'required' => ['name', 'severity', 'reason'],
            ]],
        ],
        'required' => ['path', 'lines', 'methods', 'dependencies', 'branches', 'smells'],
    ];

    /** @var list<ToolDefinition>|null */
    private ?array $tools = null;

    /** @return list<ToolDefinition> */
    public function tools(): array
    {
        return $this->tools ??= [
            new ToolDefinition(
                'refactoring_capabilities',
                'capability_discovery',
                'Refactoring capabilities',
                'Describe the deterministic Agent Kit refactoring capabilities served by this MCP server: capability names, accepted targets, the MCP tool and the JSON CLI fallback for each. Read-only.',
                self::NO_ARGUMENTS,
                self::outputSchema('capability_discovery', [
                    'capabilities' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'targets' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'mcp_tool' => ['type' => 'string'],
                            'cli_fallback' => ['type' => 'string'],
                            'json' => ['type' => 'boolean'],
                        ],
                        'required' => ['name', 'targets', 'mcp_tool', 'cli_fallback', 'json'],
                    ]],
                ], ['capabilities']),
            ),
            new ToolDefinition(
                'refactoring_audit',
                'audit',
                'Refactoring audit',
                'Audit the whole project root of this server for deterministic refactoring signals (file metrics, threshold-based smells, issue counts). Read-only; writes no report files.',
                self::NO_ARGUMENTS,
                self::outputSchema('audit', [
                    'generated_at' => ['type' => 'string'],
                    'project_root' => ['type' => 'string'],
                    'stack' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'summary' => [
                        'type' => 'object',
                        'properties' => [
                            'php_files' => ['type' => 'integer'],
                            'lines' => ['type' => 'integer'],
                            'issues' => ['type' => 'object', 'properties' => [
                                'high' => ['type' => 'integer'],
                                'medium' => ['type' => 'integer'],
                                'low' => ['type' => 'integer'],
                            ], 'required' => ['high', 'medium', 'low']],
                            'smells' => ['type' => ['object', 'array']],
                        ],
                        'required' => ['php_files', 'lines', 'issues', 'smells'],
                    ],
                    'files' => ['type' => 'array', 'items' => self::METRICS],
                ], ['generated_at', 'project_root', 'stack', 'summary', 'files']),
            ),
            new ToolDefinition(
                'refactoring_analyze',
                'analyze',
                'Analyze refactoring target',
                'Analyze one PHP file, class or Class::method: metrics, smells, upstream dependencies, direct callers, structural dependents, transitive impact and risk. Read-only.',
                self::TARGET_ARGUMENT,
                self::outputSchema('analyze', [
                    'target' => ['type' => 'string'],
                    'method' => ['type' => ['string', 'null']],
                    'metrics' => self::METRICS,
                    'upstream_dependencies' => self::EDGES,
                    'direct_callers' => self::EDGES,
                    'structural_dependencies' => self::EDGES,
                    'transitive_impact' => self::TRANSITIVE,
                    'risk' => ['type' => 'string'],
                ], ['target', 'method', 'metrics', 'upstream_dependencies', 'direct_callers', 'structural_dependencies', 'transitive_impact', 'risk']),
            ),
            new ToolDefinition(
                'refactoring_callers',
                'find_callers',
                'Find callers',
                'Find direct callers, structural dependents and transitive dependents of a class or Class::method. Unresolved dynamic references are reported separately. Read-only.',
                self::TARGET_ARGUMENT,
                self::outputSchema('find_callers', [
                    'target' => ['type' => 'string'],
                    'method' => ['type' => ['string', 'null']],
                    'direct_callers' => self::EDGES,
                    'structural_dependencies' => self::EDGES,
                    'transitive_dependents' => self::TRANSITIVE,
                    'unresolved_scope' => ['type' => 'string'],
                ], ['target', 'method', 'direct_callers', 'structural_dependencies', 'transitive_dependents', 'unresolved_scope']),
            ),
            new ToolDefinition(
                'refactoring_dependencies',
                'dependencies',
                'Analyze dependencies',
                'List typed upstream dependencies, downstream dependents and transitive dependents of a class. Read-only.',
                self::TARGET_ARGUMENT,
                self::outputSchema('dependencies', [
                    'target' => ['type' => 'string'],
                    'upstream_dependencies' => self::EDGES,
                    'downstream_dependents' => self::EDGES,
                    'transitive_dependents' => self::TRANSITIVE,
                ], ['target', 'upstream_dependencies', 'downstream_dependents', 'transitive_dependents']),
            ),
            new ToolDefinition(
                'refactoring_impact',
                'impact',
                'Analyze change impact',
                'Estimate what is potentially affected by changing a class or Class::method: dependent counts, risk level, affected files and the underlying direct, structural and transitive records. Read-only.',
                self::TARGET_ARGUMENT,
                self::outputSchema('impact', [
                    'target' => ['type' => 'string'],
                    'method' => ['type' => ['string', 'null']],
                    'direct_callers' => ['type' => 'integer'],
                    'structural_dependencies' => ['type' => 'integer'],
                    'transitive_dependents' => ['type' => 'integer'],
                    'affected_files' => ['type' => 'integer'],
                    'risk' => ['type' => 'string'],
                    'direct' => self::EDGES,
                    'structural' => self::EDGES,
                    'transitive' => self::TRANSITIVE,
                ], ['target', 'method', 'direct_callers', 'structural_dependencies', 'transitive_dependents', 'affected_files', 'risk', 'direct', 'structural', 'transitive']),
            ),
        ];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (ToolDefinition $definition): string => $definition->name, $this->tools());
    }

    public function tool(string $name): ToolDefinition
    {
        foreach ($this->tools() as $definition) {
            if ($definition->name === $name) {
                return $definition;
            }
        }

        throw new InvalidArgumentException("Unknown refactoring MCP tool: {$name}");
    }

    public function resourceDefinition(): ResourceDefinition
    {
        return new ResourceDefinition(
            uri: self::RESOURCE_URI,
            name: self::RESOURCE_NAME,
            title: 'Refactoring capabilities',
            description: 'Versioned description of the refactoring tools served by this Agent Kit MCP server: arguments, result envelopes, limitations and the absence of any apply/mutation capability.',
            mimeType: 'application/json',
        );
    }

    /**
     * @param array<string, array<string, mixed>> $dataProperties
     * @param list<string> $requiredData
     */
    private static function outputSchema(string $capability, array $dataProperties, array $requiredData): array
    {
        return [
            'type' => 'object',
            'oneOf' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'schema_version' => ['type' => 'string'],
                        'capability' => ['const' => $capability],
                        'incomplete' => ['type' => 'boolean'],
                        'data' => [
                            'type' => 'object',
                            'properties' => $dataProperties,
                            'required' => $requiredData,
                            'additionalProperties' => true,
                        ],
                        'diagnostics' => ['type' => 'array', 'items' => self::DIAGNOSTIC],
                        'unresolved' => ['type' => 'array', 'items' => self::EDGE],
                    ],
                    'required' => ['schema_version', 'capability', 'incomplete', 'data', 'diagnostics', 'unresolved'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'schema_version' => ['type' => 'string'],
                        'error' => [
                            'type' => 'object',
                            'properties' => [
                                'code' => ['type' => 'string'],
                                'message' => ['type' => 'string'],
                            ],
                            'required' => ['code', 'message'],
                        ],
                    ],
                    'required' => ['schema_version', 'error'],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 5: Run and verify GREEN**

Run: `vendor/bin/phpunit tests/Unit/Refactoring/Mcp/RefactoringToolCatalogTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 6: Commit**

```bash
git add src/Refactoring/Mcp/ToolDefinition.php src/Refactoring/Mcp/RefactoringToolCatalog.php tests/Unit/Refactoring/Mcp/RefactoringToolCatalogTest.php
git commit -m "feat: define the refactoring MCP tool catalog

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

### Task 5: Fixed project root and configuration errors

**Files:**
- Create: `src/Refactoring/Mcp/McpConfigurationException.php`
- Create: `src/Refactoring/Mcp/McpProjectRoot.php`
- Test: `tests/Unit/Refactoring/Mcp/McpProjectRootTest.php`

**Interfaces:**
- Produces: `final class McpConfigurationException extends \RuntimeException {}`; `final readonly class McpProjectRoot { public string $path; static fromPath(string $path): self }` (throws `McpConfigurationException`).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp;

use Peralta\AgentKit\Refactoring\Mcp\McpConfigurationException;
use Peralta\AgentKit\Refactoring\Mcp\McpProjectRoot;
use PHPUnit\Framework\TestCase;

final class McpProjectRootTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/agent-kit-mcp-root-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/sub', 0777, true);
        file_put_contents($this->directory . '/file.php', '<?php');
    }

    protected function tearDown(): void
    {
        if (is_link($this->directory . '/link')) {
            unlink($this->directory . '/link');
        }
        unlink($this->directory . '/file.php');
        rmdir($this->directory . '/sub');
        rmdir($this->directory);
    }

    public function test_it_canonicalizes_an_existing_directory(): void
    {
        $root = McpProjectRoot::fromPath($this->directory . '/sub/../');

        self::assertSame(realpath($this->directory), $root->path);
    }

    public function test_it_resolves_symlinks_to_their_real_path(): void
    {
        if (!function_exists('symlink') || !@symlink($this->directory . '/sub', $this->directory . '/link')) {
            self::markTestSkipped('Symbolic links are not available.');
        }

        self::assertSame(realpath($this->directory . '/sub'), McpProjectRoot::fromPath($this->directory . '/link')->path);
    }

    public function test_it_rejects_a_missing_directory(): void
    {
        $this->expectException(McpConfigurationException::class);
        $this->expectExceptionMessage('does not exist or is not a directory');
        McpProjectRoot::fromPath($this->directory . '/missing');
    }

    public function test_it_rejects_a_regular_file(): void
    {
        $this->expectException(McpConfigurationException::class);
        McpProjectRoot::fromPath($this->directory . '/file.php');
    }

    public function test_it_rejects_an_empty_path(): void
    {
        $this->expectException(McpConfigurationException::class);
        $this->expectExceptionMessage('must not be empty');
        McpProjectRoot::fromPath('   ');
    }
}
```

- [ ] **Step 2: Run and verify RED**

Run: `vendor/bin/phpunit tests/Unit/Refactoring/Mcp/McpProjectRootTest.php`
Expected: FAIL (classes missing).

- [ ] **Step 3: Implement**

`src/Refactoring/Mcp/McpConfigurationException.php`:

```php
<?php

namespace Peralta\AgentKit\Refactoring\Mcp;

use RuntimeException;

final class McpConfigurationException extends RuntimeException {}
```

`src/Refactoring/Mcp/McpProjectRoot.php`:

```php
<?php

namespace Peralta\AgentKit\Refactoring\Mcp;

use Peralta\AgentKit\Refactoring\Support\ProjectRoot;

final readonly class McpProjectRoot
{
    private function __construct(public string $path) {}

    public static function fromPath(string $path): self
    {
        $path = trim($path);
        if ($path === '') {
            throw new McpConfigurationException('The MCP project root must not be empty.');
        }

        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new McpConfigurationException("The MCP project root does not exist or is not a directory: {$path}");
        }

        return new self(ProjectRoot::normalize($real));
    }
}
```

- [ ] **Step 4: Run and verify GREEN**

Run: `vendor/bin/phpunit tests/Unit/Refactoring/Mcp/McpProjectRootTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Refactoring/Mcp/McpConfigurationException.php src/Refactoring/Mcp/McpProjectRoot.php tests/Unit/Refactoring/Mcp/McpProjectRootTest.php
git commit -m "feat: add fixed MCP project root

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

### Task 6: Tool handler (tools/call → RefactoringCapabilities)

**Files:**
- Create: `src/Refactoring/Mcp/RefactoringToolHandler.php`
- Create: `tests/Unit/Refactoring/Mcp/RecordingCapabilities.php` (test double shared by Tasks 6–7)
- Test: `tests/Unit/Refactoring/Mcp/RefactoringToolHandlerTest.php`

**Interfaces:**
- Consumes: `ToolDefinition` (Task 4), `McpProjectRoot` (Task 5), `RefactoringCapabilities`, `CapabilityResult`, `CapabilityException`, SDK `ToolHandlerInterface`, `ClientGateway`, `CallToolResult`, `TextContent`.
- Produces: `final class RefactoringToolHandler implements ToolHandlerInterface { __construct(RefactoringCapabilities $capabilities, McpProjectRoot $root, ToolDefinition $definition); execute(array $arguments, ClientGateway $gateway): CallToolResult; call(array $arguments): CallToolResult; const JSON_FLAGS }`.

- [ ] **Step 1: Add the recording test double**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp;

use Peralta\AgentKit\Refactoring\Application\CapabilityException;
use Peralta\AgentKit\Refactoring\Application\CapabilityResult;
use Peralta\AgentKit\Refactoring\Application\RefactoringCapabilities;

final class RecordingCapabilities implements RefactoringCapabilities
{
    /** @var list<array{0: string, 1: array}> */
    public array $calls = [];

    public ?CapabilityException $failure = null;

    public array $diagnostics = [];

    public array $unresolved = [];

    public function describeCapabilities(): CapabilityResult
    {
        return $this->record('describeCapabilities', [], 'capability_discovery', ['capabilities' => [
            ['name' => 'audit', 'targets' => ['project'], 'mcp_tool' => 'refactoring_audit', 'cli_fallback' => 'php artisan agent-kit:refactor-audit --json', 'json' => true],
            ['name' => 'analyze', 'targets' => ['file', 'class', 'method'], 'mcp_tool' => 'refactoring_analyze', 'cli_fallback' => 'php artisan agent-kit:refactor-analyze <target> --json', 'json' => true],
            ['name' => 'find_callers', 'targets' => ['class', 'method'], 'mcp_tool' => 'refactoring_callers', 'cli_fallback' => 'php artisan agent-kit:refactor-callers <class> --method=<method> --json', 'json' => true],
            ['name' => 'dependencies', 'targets' => ['class'], 'mcp_tool' => 'refactoring_dependencies', 'cli_fallback' => 'php artisan agent-kit:refactor-dependencies <class> --json', 'json' => true],
            ['name' => 'impact', 'targets' => ['class', 'method'], 'mcp_tool' => 'refactoring_impact', 'cli_fallback' => 'php artisan agent-kit:refactor-impact <class> --method=<method> --json', 'json' => true],
        ]]);
    }

    public function audit(string $projectRoot): CapabilityResult
    {
        return $this->record('audit', [$projectRoot], 'audit', ['project_root' => $projectRoot]);
    }

    public function analyze(string $projectRoot, string $target): CapabilityResult
    {
        return $this->record('analyze', [$projectRoot, $target], 'analyze', ['target' => $target]);
    }

    public function findCallers(string $projectRoot, string $target): CapabilityResult
    {
        return $this->record('findCallers', [$projectRoot, $target], 'find_callers', ['target' => $target]);
    }

    public function dependencies(string $projectRoot, string $target): CapabilityResult
    {
        return $this->record('dependencies', [$projectRoot, $target], 'dependencies', ['target' => $target]);
    }

    public function impact(string $projectRoot, string $target): CapabilityResult
    {
        return $this->record('impact', [$projectRoot, $target], 'impact', ['target' => $target]);
    }

    private function record(string $method, array $arguments, string $capability, array $data): CapabilityResult
    {
        $this->calls[] = [$method, $arguments];
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new CapabilityResult($capability, $data, $this->diagnostics, $this->unresolved);
    }
}
```

- [ ] **Step 2: Write the failing handler tests**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Peralta\AgentKit\Refactoring\Application\CapabilityException;
use Peralta\AgentKit\Refactoring\Mcp\McpProjectRoot;
use Peralta\AgentKit\Refactoring\Mcp\RefactoringToolCatalog;
use Peralta\AgentKit\Refactoring\Mcp\RefactoringToolHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefactoringToolHandlerTest extends TestCase
{
    #[DataProvider('toolMappings')]
    public function test_each_tool_calls_its_capability_with_the_fixed_root_and_trimmed_target(
        string $tool,
        string $method,
        array $arguments,
        array $expectedArguments,
    ): void {
        $capabilities = new RecordingCapabilities();
        $root = McpProjectRoot::fromPath(__DIR__);

        $result = $this->handler($capabilities, $tool)->call($arguments);

        self::assertSame([[$method, array_map(
            fn ($value) => $value === '{root}' ? $root->path : $value,
            $expectedArguments,
        )]], $capabilities->calls);
        self::assertFalse($result->isError);
    }

    public static function toolMappings(): array
    {
        return [
            'capabilities' => ['refactoring_capabilities', 'describeCapabilities', [], []],
            'audit' => ['refactoring_audit', 'audit', [], ['{root}']],
            'analyze' => ['refactoring_analyze', 'analyze', ['target' => '  app/Service.php '], ['{root}', 'app/Service.php']],
            'callers' => ['refactoring_callers', 'findCallers', ['target' => 'App\\Service::run'], ['{root}', 'App\\Service::run']],
            'dependencies' => ['refactoring_dependencies', 'dependencies', ['target' => 'App\\Service'], ['{root}', 'App\\Service']],
            'impact' => ['refactoring_impact', 'impact', ['target' => 'App\\Service::run'], ['{root}', 'App\\Service::run']],
        ];
    }

    public function test_success_results_carry_the_cli_envelope_as_structured_content_and_text(): void
    {
        $capabilities = new RecordingCapabilities();
        $capabilities->diagnostics = [['file' => 'Broken.php', 'line' => 1, 'message' => 'Syntax error']];
        $capabilities->unresolved = [['source' => 'App\\A', 'target' => null]];

        $result = $this->handler($capabilities, 'refactoring_impact')->call(['target' => 'App\\Service']);
        $expected = $capabilities->impact(McpProjectRoot::fromPath(__DIR__)->path, 'App\\Service')->toArray();

        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertSame($expected, $result->structuredContent);
        self::assertSame('1.0', $result->structuredContent['schema_version']);
        self::assertTrue($result->structuredContent['incomplete']);
        self::assertSame($capabilities->diagnostics, $result->structuredContent['diagnostics']);
        self::assertSame($capabilities->unresolved, $result->structuredContent['unresolved']);
        self::assertCount(1, $result->content);
        self::assertInstanceOf(TextContent::class, $result->content[0]);
        self::assertSame(
            json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $result->content[0]->text,
        );
    }

    public function test_domain_errors_become_tool_errors_with_the_stable_error_envelope(): void
    {
        $capabilities = new RecordingCapabilities();
        $capabilities->failure = new CapabilityException('TARGET_NOT_FOUND', 'Class not found: Missing\\Service');

        $result = $this->handler($capabilities, 'refactoring_callers')->call(['target' => 'Missing\\Service']);

        self::assertTrue($result->isError);
        self::assertSame([
            'schema_version' => '1.0',
            'error' => ['code' => 'TARGET_NOT_FOUND', 'message' => 'Class not found: Missing\\Service'],
        ], $result->structuredContent);
        self::assertSame(
            json_encode($result->structuredContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $result->content[0]->text,
        );
    }

    public function test_a_non_string_target_is_a_domain_error_not_a_crash(): void
    {
        $capabilities = new RecordingCapabilities();

        $result = $this->handler($capabilities, 'refactoring_analyze')->call(['target' => ['nested']]);

        self::assertTrue($result->isError);
        self::assertSame('INVALID_TARGET', $result->structuredContent['error']['code']);
        self::assertSame([], $capabilities->calls);
    }

    public function test_the_adapter_never_touches_artisan_or_the_console_layer(): void
    {
        foreach ([
            dirname(__DIR__, 4) . '/src/Refactoring/Mcp/RefactoringToolHandler.php',
            dirname(__DIR__, 4) . '/src/Refactoring/Mcp/RefactoringToolCatalog.php',
        ] as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString('Illuminate\\Console', $source, $file);
            self::assertStringNotContainsString('Artisan', $source, $file);
            self::assertStringNotContainsString('DefaultRefactoringCapabilities', $source, $file);
        }
    }

    private function handler(RecordingCapabilities $capabilities, string $tool): RefactoringToolHandler
    {
        return new RefactoringToolHandler(
            $capabilities,
            McpProjectRoot::fromPath(__DIR__),
            (new RefactoringToolCatalog())->tool($tool),
        );
    }
}
```

- [ ] **Step 3: Run and verify RED**

Run: `vendor/bin/phpunit tests/Unit/Refactoring/Mcp/RefactoringToolHandlerTest.php`
Expected: FAIL (class missing).

- [ ] **Step 4: Implement the handler**

```php
<?php

namespace Peralta\AgentKit\Refactoring\Mcp;

use LogicException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Peralta\AgentKit\Refactoring\Application\CapabilityException;
use Peralta\AgentKit\Refactoring\Application\CapabilityResult;
use Peralta\AgentKit\Refactoring\Application\RefactoringCapabilities;

final class RefactoringToolHandler implements ToolHandlerInterface
{
    // Same flags as the CLI --json renderer so both adapters emit identical text.
    public const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly RefactoringCapabilities $capabilities,
        private readonly McpProjectRoot $root,
        private readonly ToolDefinition $definition,
    ) {}

    public function execute(array $arguments, ClientGateway $gateway): CallToolResult
    {
        return $this->call($arguments);
    }

    /** @param array<string, mixed> $arguments */
    public function call(array $arguments): CallToolResult
    {
        try {
            $envelope = $this->dispatch($arguments)->toArray();
        } catch (CapabilityException $exception) {
            $envelope = $exception->toArray();

            return new CallToolResult(
                [new TextContent(json_encode($envelope, self::JSON_FLAGS))],
                isError: true,
                structuredContent: $envelope,
            );
        }

        return new CallToolResult(
            [new TextContent(json_encode($envelope, self::JSON_FLAGS))],
            isError: false,
            structuredContent: $envelope,
        );
    }

    /** @param array<string, mixed> $arguments */
    private function dispatch(array $arguments): CapabilityResult
    {
        $root = $this->root->path;

        return match ($this->definition->capability) {
            'capability_discovery' => $this->capabilities->describeCapabilities(),
            'audit' => $this->capabilities->audit($root),
            'analyze' => $this->capabilities->analyze($root, $this->target($arguments)),
            'find_callers' => $this->capabilities->findCallers($root, $this->target($arguments)),
            'dependencies' => $this->capabilities->dependencies($root, $this->target($arguments)),
            'impact' => $this->capabilities->impact($root, $this->target($arguments)),
            default => throw new LogicException("Unmapped capability: {$this->definition->capability}"),
        };
    }

    /** @param array<string, mixed> $arguments */
    private function target(array $arguments): string
    {
        $target = $arguments['target'] ?? null;
        if (!is_string($target)) {
            throw new CapabilityException('INVALID_TARGET', 'The target argument must be a string.');
        }

        return trim($target);
    }
}
```

- [ ] **Step 5: Run and verify GREEN**

Run: `vendor/bin/phpunit tests/Unit/Refactoring/Mcp/RefactoringToolHandlerTest.php`
Expected: PASS, 10 tests.

- [ ] **Step 6: Commit**

```bash
git add src/Refactoring/Mcp/RefactoringToolHandler.php tests/Unit/Refactoring/Mcp/RecordingCapabilities.php tests/Unit/Refactoring/Mcp/RefactoringToolHandlerTest.php
git commit -m "feat: map MCP tool calls onto refactoring capabilities

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

### Task 7: Capabilities resource handler

**Files:**
- Create: `src/Refactoring/Mcp/CapabilitiesResourceHandler.php`
- Test: `tests/Unit/Refactoring/Mcp/CapabilitiesResourceHandlerTest.php`

**Interfaces:**
- Consumes: `RefactoringToolCatalog`, `McpProjectRoot`, `RefactoringCapabilities`, SDK `ResourceHandlerInterface`, `TextResourceContents`.
- Produces: `final class CapabilitiesResourceHandler implements ResourceHandlerInterface { __construct(RefactoringCapabilities $capabilities, RefactoringToolCatalog $catalog, McpProjectRoot $root, string $serverName, string $serverVersion); read(string $uri, ClientGateway $gateway): TextResourceContents; describe(): array }`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp;

use Peralta\AgentKit\Refactoring\Mcp\CapabilitiesResourceHandler;
use Peralta\AgentKit\Refactoring\Mcp\McpProjectRoot;
use Peralta\AgentKit\Refactoring\Mcp\RefactoringToolCatalog;
use PHPUnit\Framework\TestCase;

final class CapabilitiesResourceHandlerTest extends TestCase
{
    public function test_it_describes_every_tool_from_the_catalog_and_the_capability_descriptors(): void
    {
        $capabilities = new RecordingCapabilities();
        $catalog = new RefactoringToolCatalog();
        $document = $this->handler($capabilities, $catalog)->describe();

        self::assertSame('1.0', $document['schema_version']);
        self::assertSame(['name' => 'agent-kit-refactoring', 'version' => '9.9.9'], $document['server']);
        self::assertSame(McpProjectRoot::fromPath(__DIR__)->path, $document['project_root']);
        self::assertSame($catalog->names(), array_column($document['tools'], 'name'));
        self::assertSame([['describeCapabilities', []]], $capabilities->calls);

        $impact = $document['tools'][5];
        self::assertSame('impact', $impact['capability']);
        self::assertSame(['class', 'method'], $impact['targets']);
        self::assertSame('php artisan agent-kit:refactor-impact <class> --method=<method> --json', $impact['cli_fallback']);
        self::assertSame($catalog->tool('refactoring_impact')->inputSchema, $impact['input_schema']);
        self::assertSame($catalog->tool('refactoring_impact')->outputSchema, $impact['output_schema']);

        self::assertFalse($document['mutation']['supported']);
        self::assertStringContainsString('ANALYZE != MODIFY', $document['mutation']['note']);
        self::assertNotEmpty($document['limitations']);
        self::assertArrayHasKey('success', $document['result_format']);
        self::assertArrayHasKey('error', $document['result_format']);
    }

    public function test_read_returns_a_json_text_resource_for_the_catalog_uri(): void
    {
        $contents = $this->handler(new RecordingCapabilities(), new RefactoringToolCatalog())->readDocument(RefactoringToolCatalog::RESOURCE_URI);

        self::assertSame(RefactoringToolCatalog::RESOURCE_URI, $contents->uri);
        self::assertSame('application/json', $contents->mimeType);
        $decoded = json_decode($contents->text, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('1.0', $decoded['schema_version']);
        self::assertCount(6, $decoded['tools']);
    }

    private function handler(RecordingCapabilities $capabilities, RefactoringToolCatalog $catalog): CapabilitiesResourceHandler
    {
        return new CapabilitiesResourceHandler(
            $capabilities,
            $catalog,
            McpProjectRoot::fromPath(__DIR__),
            'agent-kit-refactoring',
            '9.9.9',
        );
    }
}
```

- [ ] **Step 2: Run and verify RED**

Run: `vendor/bin/phpunit tests/Unit/Refactoring/Mcp/CapabilitiesResourceHandlerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php

namespace Peralta\AgentKit\Refactoring\Mcp;

use Mcp\Schema\Content\TextResourceContents;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ResourceHandlerInterface;
use Peralta\AgentKit\Refactoring\Application\CapabilityResult;
use Peralta\AgentKit\Refactoring\Application\RefactoringCapabilities;

final class CapabilitiesResourceHandler implements ResourceHandlerInterface
{
    public function __construct(
        private readonly RefactoringCapabilities $capabilities,
        private readonly RefactoringToolCatalog $catalog,
        private readonly McpProjectRoot $root,
        private readonly string $serverName,
        private readonly string $serverVersion,
    ) {}

    public function read(string $uri, ClientGateway $gateway): TextResourceContents
    {
        return $this->readDocument($uri);
    }

    public function readDocument(string $uri): TextResourceContents
    {
        return new TextResourceContents(
            $uri,
            'application/json',
            json_encode($this->describe(), RefactoringToolHandler::JSON_FLAGS),
        );
    }

    public function describe(): array
    {
        $descriptors = [];
        foreach ($this->capabilities->describeCapabilities()->data['capabilities'] as $descriptor) {
            $descriptors[$descriptor['name']] = $descriptor;
        }

        $tools = [];
        foreach ($this->catalog->tools() as $definition) {
            $descriptor = $descriptors[$definition->capability] ?? null;
            $tools[] = [
                'name' => $definition->name,
                'capability' => $definition->capability,
                'title' => $definition->title,
                'description' => $definition->description,
                'targets' => $descriptor['targets'] ?? [],
                'cli_fallback' => $descriptor['cli_fallback'] ?? 'php artisan agent-kit:refactor-capabilities --json',
                'input_schema' => $definition->inputSchema,
                'output_schema' => $definition->outputSchema,
            ];
        }

        return [
            'schema_version' => CapabilityResult::SCHEMA_VERSION,
            'server' => ['name' => $this->serverName, 'version' => $this->serverVersion],
            'project_root' => $this->root->path,
            'tools' => $tools,
            'result_format' => [
                'success' => ['schema_version', 'capability', 'incomplete', 'data', 'diagnostics', 'unresolved'],
                'error' => ['schema_version', 'error' => ['code', 'message']],
                'incomplete_semantics' => 'incomplete=true means static analysis could not resolve every reference; diagnostics list parse problems and unresolved lists dynamic references. Nothing is invented to fill them.',
                'errors' => 'Domain failures are tool results with isError=true carrying the error envelope in structuredContent; schema violations are JSON-RPC -32602 errors.',
            ],
            'limitations' => [
                'Static PHP analysis only: no code execution, no runtime container resolution, no dynamic class strings, no reflection or macros.',
                'Every tool operates on the single project root fixed when the server started; paths outside it are rejected.',
                'The codebase index is rebuilt whenever any included PHP file changes; results always reflect the current files.',
                'Dependency does not prove breakage: treat impact results as potentially affected components to verify.',
            ],
            'mutation' => [
                'supported' => false,
                'note' => 'ANALYZE != MODIFY: this server only audits, analyzes and explains. No apply, edit, write or shell capability exists or is planned.',
            ],
        ];
    }
}
```

- [ ] **Step 4: Run and verify GREEN**

Run: `vendor/bin/phpunit tests/Unit/Refactoring/Mcp/CapabilitiesResourceHandlerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Refactoring/Mcp/CapabilitiesResourceHandler.php tests/Unit/Refactoring/Mcp/CapabilitiesResourceHandlerTest.php
git commit -m "feat: expose refactoring capabilities as an MCP resource

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

### Task 8: Server factory, stderr logger and in-process protocol tests

**Files:**
- Create: `src/Refactoring/Mcp/McpLoggerFactory.php`
- Create: `src/Refactoring/Mcp/McpServerFactory.php`
- Create: `src/Refactoring/Mcp/Transport/StdioRunnerControl.php`
- Modify: `src/AgentKitServiceProvider.php` (new `registerMcp()` called from `register()`)
- Test: `tests/Feature/Mcp/McpServerProtocolTest.php`

**Interfaces:**
- Consumes: Tasks 4–7; SDK `Server`, `Builder`, `ServerCapabilities`, `InMemorySessionStore`, `SessionStoreInterface`, `StdioTransport`, `RunnerControlInterface`, `RunnerState`; Laravel `LogManager`.
- Produces: `final class McpLoggerFactory { __construct(LogManager $log); create(array $config): LoggerInterface }`; `final class McpServerFactory { const SERVER_NAME = 'agent-kit-refactoring'; static version(): string; create(McpProjectRoot $root, LoggerInterface $logger, SessionStoreInterface $sessions, int $gcProbability = 1): Server }`; `final class StdioRunnerControl implements RunnerControlInterface { getState(): RunnerState; stop(): void }`. Container bindings: `RefactoringToolCatalog` (singleton), `McpLoggerFactory` (singleton), `McpServerFactory` (bind).

- [ ] **Step 1: Write the failing in-process protocol test**

The SDK `StdioTransport::close()` `fclose()`s both streams after `run()`, so the output is read back from a temporary file.

```php
<?php

namespace Peralta\AgentKit\Tests\Feature\Mcp;

use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Transport\StdioTransport;
use Opis\JsonSchema\Validator;
use Peralta\AgentKit\Refactoring\Application\RefactoringCapabilities;
use Peralta\AgentKit\Refactoring\Mcp\McpProjectRoot;
use Peralta\AgentKit\Refactoring\Mcp\McpServerFactory;
use Peralta\AgentKit\Refactoring\Mcp\RefactoringToolCatalog;
use Peralta\AgentKit\Refactoring\Mcp\Transport\StdioRunnerControl;
use Peralta\AgentKit\Tests\TestCase;
use Psr\Log\NullLogger;

final class McpServerProtocolTest extends TestCase
{
    private const INITIALIZE = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
        'protocolVersion' => '2025-11-25',
        'capabilities' => [],
        'clientInfo' => ['name' => 'agent-kit-tests', 'version' => '1.0.0'],
    ]];
    private const INITIALIZED = ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'];

    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                array_map('unlink', glob($path . '/*.php') ?: []);
                rmdir($path);
            }
        }
        parent::tearDown();
    }

    public function test_initialize_advertises_tools_and_resources_only(): void
    {
        $responses = $this->run([self::INITIALIZE]);

        $result = $responses[1]['result'];
        self::assertSame('2025-11-25', $result['protocolVersion']);
        self::assertSame('agent-kit-refactoring', $result['serverInfo']['name']);
        self::assertArrayHasKey('tools', $result['capabilities']);
        self::assertArrayHasKey('resources', $result['capabilities']);
        self::assertArrayNotHasKey('prompts', $result['capabilities']);
        self::assertStringContainsString('ANALYZE != MODIFY', $result['instructions']);
    }

    public function test_tools_list_matches_the_catalog_with_schemas_and_read_only_annotations(): void
    {
        $responses = $this->run([self::INITIALIZE, self::INITIALIZED, $this->request(2, 'tools/list')]);

        $tools = $responses[2]['result']['tools'];
        self::assertSame((new RefactoringToolCatalog())->names(), array_column($tools, 'name'));
        foreach ($tools as $tool) {
            self::assertSame('object', $tool['inputSchema']['type'], $tool['name']);
            self::assertArrayHasKey('outputSchema', $tool, $tool['name']);
            self::assertTrue($tool['annotations']['readOnlyHint'], $tool['name']);
            self::assertFalse($tool['annotations']['destructiveHint'], $tool['name']);
        }
    }

    public function test_every_tool_returns_the_same_envelope_as_the_capability_layer(): void
    {
        $root = McpProjectRoot::fromPath($this->fixtureRoot())->path;
        $direct = $this->app->make(RefactoringCapabilities::class);
        $calls = [
            2 => ['refactoring_capabilities', [], $direct->describeCapabilities()->toArray()],
            3 => ['refactoring_audit', [], null],
            4 => ['refactoring_analyze', ['target' => 'CheckoutService.php'], $direct->analyze($root, 'CheckoutService.php')->toArray()],
            5 => ['refactoring_callers', ['target' => 'Fixtures\\Payments\\PaymentService::charge'], $direct->findCallers($root, 'Fixtures\\Payments\\PaymentService::charge')->toArray()],
            6 => ['refactoring_dependencies', ['target' => 'Fixtures\\Checkout\\CheckoutService'], $direct->dependencies($root, 'Fixtures\\Checkout\\CheckoutService')->toArray()],
            7 => ['refactoring_impact', ['target' => 'Fixtures\\Payments\\PaymentService::charge'], $direct->impact($root, 'Fixtures\\Payments\\PaymentService::charge')->toArray()],
        ];
        $messages = [self::INITIALIZE, self::INITIALIZED];
        foreach ($calls as $id => [$name, $arguments]) {
            $messages[] = $this->request($id, 'tools/call', ['name' => $name, 'arguments' => (object) $arguments]);
        }

        $responses = $this->run($messages);
        $catalog = new RefactoringToolCatalog();

        foreach ($calls as $id => [$name, , $expected]) {
            $result = $responses[$id]['result'];
            self::assertFalse($result['isError'] ?? false, $name);
            self::assertSame('1.0', $result['structuredContent']['schema_version'], $name);
            self::assertSame($catalog->tool($name)->capability, $result['structuredContent']['capability'], $name);
            self::assertSame('text', $result['content'][0]['type'], $name);
            self::assertSame($result['structuredContent'], json_decode($result['content'][0]['text'], true), $name);
            if ($expected !== null) {
                // audit carries generated_at; every other envelope must equal the direct call byte for byte.
                self::assertSame($expected, $result['structuredContent'], $name);
            } else {
                self::assertSame($root, $result['structuredContent']['data']['project_root']);
            }
            $this->assertMatchesOutputSchema($catalog->tool($name)->outputSchema, $result['structuredContent'], $name);
        }
    }

    public function test_domain_errors_are_tool_errors_that_still_match_the_output_schema(): void
    {
        $responses = $this->run([
            self::INITIALIZE,
            self::INITIALIZED,
            $this->request(2, 'tools/call', ['name' => 'refactoring_impact', 'arguments' => ['target' => 'Missing\\Service']]),
            $this->request(3, 'tools/call', ['name' => 'refactoring_dependencies', 'arguments' => ['target' => 'Fixtures\\Payments\\PaymentService::charge']]),
        ]);

        $notFound = $responses[2]['result'];
        self::assertTrue($notFound['isError']);
        self::assertSame(['schema_version' => '1.0', 'error' => [
            'code' => 'TARGET_NOT_FOUND',
            'message' => 'Class not found: Missing\\Service',
        ]], $notFound['structuredContent']);
        $this->assertMatchesOutputSchema((new RefactoringToolCatalog())->tool('refactoring_impact')->outputSchema, $notFound['structuredContent']);

        self::assertSame('UNSUPPORTED_TARGET', $responses[3]['result']['structuredContent']['error']['code']);
    }

    public function test_paths_outside_the_fixed_root_are_rejected_through_the_tool(): void
    {
        $parent = sys_get_temp_dir() . '/agent-kit-mcp-outside-' . bin2hex(random_bytes(6));
        $root = $parent . '/project';
        mkdir($root, 0777, true);
        file_put_contents($root . '/Inside.php', '<?php namespace Demo; class Inside {}');
        file_put_contents($parent . '/Outside.php', '<?php class Outside {}');
        $this->temporaryPaths[] = $parent . '/Outside.php';
        $this->temporaryPaths[] = $root;
        $this->temporaryPaths[] = $parent;

        $responses = $this->run([
            self::INITIALIZE,
            self::INITIALIZED,
            $this->request(2, 'tools/call', ['name' => 'refactoring_analyze', 'arguments' => ['target' => '../Outside.php']]),
            $this->request(3, 'tools/call', ['name' => 'refactoring_analyze', 'arguments' => ['target' => $parent . '/Outside.php']]),
        ], $root);

        foreach ([2, 3] as $id) {
            self::assertTrue($responses[$id]['result']['isError']);
            self::assertSame('TARGET_OUTSIDE_PROJECT', $responses[$id]['result']['structuredContent']['error']['code']);
        }
    }

    public function test_schema_violations_and_unknown_tools_are_invalid_params_errors(): void
    {
        $responses = $this->run([
            self::INITIALIZE,
            self::INITIALIZED,
            $this->request(2, 'tools/call', ['name' => 'refactoring_analyze', 'arguments' => (object) []]),
            $this->request(3, 'tools/call', ['name' => 'refactoring_analyze', 'arguments' => ['target' => '']]),
            $this->request(4, 'tools/call', ['name' => 'refactoring_analyze', 'arguments' => ['target' => 'A', 'extra' => true]]),
            $this->request(5, 'tools/call', ['name' => 'refactoring_audit', 'arguments' => ['target' => 42]]),
            $this->request(6, 'tools/call', ['name' => 'refactoring_apply', 'arguments' => (object) []]),
        ]);

        foreach ([2, 3, 4, 5, 6] as $id) {
            self::assertSame(-32602, $responses[$id]['error']['code'], "message {$id}");
        }
    }

    public function test_the_resource_lists_and_reads_the_catalog(): void
    {
        $responses = $this->run([
            self::INITIALIZE,
            self::INITIALIZED,
            $this->request(2, 'resources/list'),
            $this->request(3, 'resources/read', ['uri' => RefactoringToolCatalog::RESOURCE_URI]),
        ]);

        self::assertSame([RefactoringToolCatalog::RESOURCE_URI], array_column($responses[2]['result']['resources'], 'uri'));
        $contents = $responses[3]['result']['contents'][0];
        self::assertSame('application/json', $contents['mimeType']);
        $document = json_decode($contents['text'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame((new RefactoringToolCatalog())->names(), array_column($document['tools'], 'name'));
        self::assertFalse($document['mutation']['supported']);
    }

    public function test_malformed_json_yields_a_parse_error_and_stdout_stays_pure(): void
    {
        [$responses, $lines] = $this->runRaw([json_encode(self::INITIALIZE), '{not json', json_encode(self::INITIALIZED)]);

        $parseErrors = array_values(array_filter($responses, fn (array $message) => ($message['error']['code'] ?? null) === -32700));
        self::assertCount(1, $parseErrors);
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            self::assertIsArray($decoded, "stdout line is not JSON-RPC: {$line}");
            self::assertSame('2.0', $decoded['jsonrpc']);
        }
    }

    /** @return array<int, array> responses keyed by id */
    private function run(array $messages, ?string $root = null): array
    {
        [$responses] = $this->runRaw(array_map(fn ($message) => json_encode($message, JSON_THROW_ON_ERROR), $messages), $root);

        return $responses;
    }

    /** @return array{0: array<int, array>, 1: list<string>} */
    private function runRaw(array $lines, ?string $root = null): array
    {
        $input = fopen('php://temp', 'r+');
        fwrite($input, implode("\n", $lines) . "\n");
        rewind($input);
        $outputPath = tempnam(sys_get_temp_dir(), 'agent-kit-mcp-out-');
        $this->temporaryPaths[] = $outputPath;
        $output = fopen($outputPath, 'w+');

        $server = $this->app->make(McpServerFactory::class)->create(
            McpProjectRoot::fromPath($root ?? $this->fixtureRoot()),
            new NullLogger(),
            new InMemorySessionStore(PHP_INT_MAX),
            gcProbability: 0,
        );
        $status = $server->run(new StdioTransport($input, $output, new NullLogger(), new StdioRunnerControl()));
        self::assertSame(0, $status);

        $written = array_values(array_filter(explode("\n", (string) file_get_contents($outputPath)), fn ($line) => trim($line) !== ''));
        $responses = [];
        foreach ($written as $line) {
            $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $responses[$decoded['id'] ?? count($responses) + 1000] = $decoded;
        }

        return [$responses, $written];
    }

    private function request(int $id, string $method, array|object $params = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params === [] ? (object) [] : $params];
    }

    private function assertMatchesOutputSchema(array $schema, array $data, string $label = ''): void
    {
        $result = (new Validator())->validate(
            json_decode(json_encode($data, JSON_THROW_ON_ERROR)),
            json_decode(json_encode($schema, JSON_THROW_ON_ERROR)),
        );

        self::assertTrue($result->isValid(), $label . ': ' . ($result->error()?->message() ?? ''));
    }

    private function fixtureRoot(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Refactoring/Ast';
    }
}
```

- [ ] **Step 2: Run and verify RED**

Run: `vendor/bin/phpunit tests/Feature/Mcp/McpServerProtocolTest.php`
Expected: FAIL (`McpServerFactory` unresolvable).

- [ ] **Step 3: Add `StdioRunnerControl` (instance-scoped; the SDK default is a static)**

```php
<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport;

use Mcp\Server\Transport\Stdio\RunnerControlInterface;
use Mcp\Server\Transport\Stdio\RunnerState;

final class StdioRunnerControl implements RunnerControlInterface
{
    private RunnerState $state = RunnerState::RUNNING;

    public function getState(): RunnerState
    {
        return $this->state;
    }

    public function stop(): void
    {
        $this->state = RunnerState::STOP_AND_END_SESSION;
    }
}
```

- [ ] **Step 4: Add `McpLoggerFactory`**

```php
<?php

namespace Peralta\AgentKit\Refactoring\Mcp;

use Illuminate\Log\LogManager;
use Monolog\Handler\StreamHandler;
use Psr\Log\LoggerInterface;

final class McpLoggerFactory
{
    public function __construct(private readonly LogManager $log) {}

    /** @param array{level?: string|null, channel?: string|null} $config */
    public function create(array $config): LoggerInterface
    {
        $channel = $config['channel'] ?? null;
        if (is_string($channel) && $channel !== '') {
            return $this->log->channel($channel);
        }

        // stdout is reserved for JSON-RPC on stdio; every log line goes to stderr.
        return $this->log->build([
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => ['stream' => 'php://stderr'],
            'level' => $config['level'] ?? 'info',
        ]);
    }
}
```

- [ ] **Step 5: Add `McpServerFactory`**

```php
<?php

namespace Peralta\AgentKit\Refactoring\Mcp;

use Composer\InstalledVersions;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server;
use Mcp\Server\Session\SessionStoreInterface;
use OutOfBoundsException;
use Peralta\AgentKit\Refactoring\Application\RefactoringCapabilities;
use Psr\Log\LoggerInterface;

final class McpServerFactory
{
    public const SERVER_NAME = 'agent-kit-refactoring';

    private const INSTRUCTIONS = <<<'TEXT'
Agent Kit deterministic refactoring analysis for PHP/Laravel. ANALYZE != MODIFY: every tool is read-only and operates on the project root fixed when this server started; there is no apply, edit or shell tool.
Use refactoring_capabilities (or the agent-kit://refactoring/capabilities resource) to discover tools. Targets are project-relative PHP files (analyze only), fully qualified class names, or Class::method.
Results carry structuredContent with schema_version, capability, incomplete, data, diagnostics and unresolved. incomplete=true means static analysis could not resolve everything: read diagnostics (parse problems) and unresolved (dynamic references) instead of guessing. Domain failures come back as tool errors (isError=true) with {schema_version, error: {code, message}}.
Dependency never proves breakage; report impact as "potentially affected" and verify with tests.
TEXT;

    public function __construct(
        private readonly RefactoringCapabilities $capabilities,
        private readonly RefactoringToolCatalog $catalog,
    ) {}

    public function create(
        McpProjectRoot $root,
        LoggerInterface $logger,
        SessionStoreInterface $sessions,
        int $gcProbability = 1,
    ): Server {
        $builder = Server::builder()
            ->setServerInfo(self::SERVER_NAME, self::version(), 'Deterministic PHP/Laravel refactoring analysis (read-only).')
            ->setInstructions(self::INSTRUCTIONS)
            ->setCapabilities(new ServerCapabilities(
                tools: true,
                toolsListChanged: false,
                resources: true,
                resourcesSubscribe: false,
                resourcesListChanged: false,
                prompts: false,
                promptsListChanged: false,
                logging: false,
                completions: false,
            ))
            ->setLogger($logger)
            ->setLazyLoading(false)
            ->setSession($sessions, gcProbability: $gcProbability);

        foreach ($this->catalog->tools() as $definition) {
            $builder->add($definition->toTool(), new RefactoringToolHandler($this->capabilities, $root, $definition));
        }

        $builder->add($this->catalog->resourceDefinition(), new CapabilitiesResourceHandler(
            $this->capabilities,
            $this->catalog,
            $root,
            self::SERVER_NAME,
            self::version(),
        ));

        return $builder->build();
    }

    public static function version(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('peralta/agent-kit') ?? 'dev';
        } catch (OutOfBoundsException) {
            return 'dev';
        }
    }
}
```

- [ ] **Step 6: Register the MCP services in the provider**

In `AgentKitServiceProvider` add imports for `McpLoggerFactory`, `McpServerFactory`, `RefactoringToolCatalog`, call `$this->registerMcp();` at the end of `register()`, and add:

```php
    protected function registerMcp(): void
    {
        $this->app->singleton(RefactoringToolCatalog::class);
        $this->app->singleton(McpLoggerFactory::class, fn ($app) => new McpLoggerFactory($app->make('log')));
        $this->app->bind(McpServerFactory::class, fn ($app) => new McpServerFactory(
            $app->make(RefactoringCapabilities::class),
            $app->make(RefactoringToolCatalog::class),
        ));
    }
```

- [ ] **Step 7: Run and verify GREEN**

Run: `vendor/bin/phpunit tests/Feature/Mcp/McpServerProtocolTest.php`
Expected: PASS, 8 tests. If `test_every_tool_returns_the_same_envelope_as_the_capability_layer` reports a schema mismatch, fix the schema in `RefactoringToolCatalog` (the capability payload is the contract, the schema describes it).

- [ ] **Step 8: Commit**

```bash
git add src/Refactoring/Mcp src/AgentKitServiceProvider.php tests/Feature/Mcp/McpServerProtocolTest.php
git commit -m "feat: build the refactoring MCP server from the container

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

### Task 9: stdio runner, `agent-kit:mcp` command and subprocess tests with the SDK client

**Files:**
- Create: `src/Refactoring/Mcp/Transport/StdioServerRunner.php`
- Create: `src/Refactoring/Mcp/Commands/McpServeCommand.php`
- Modify: `src/AgentKitServiceProvider.php` (`registerMcp()` + `boot()` command list)
- Create: `tests/Feature/Mcp/Concerns/SpawnsMcpServer.php`
- Test: `tests/Feature/Mcp/StdioServerCommandTest.php`

**Interfaces:**
- Consumes: `McpServerFactory`, `McpLoggerFactory`, `McpProjectRoot`, `McpConfigurationException`, `CachedCodebaseIndexer`, `StdioRunnerControl`; SDK `StdioTransport`, `InMemorySessionStore`; SDK client `Mcp\Client`, `Mcp\Client\Transport\StdioTransport`.
- Produces: `final class StdioServerRunner { __construct(McpServerFactory $factory, CachedCodebaseIndexer $indexCache); run(McpProjectRoot $root, LoggerInterface $logger, $input, $output): int }`; Artisan `agent-kit:mcp {--transport=} {--path=}` (Task 12 adds `--host`, `--port`, `--allow-remote`).

- [ ] **Step 1: Add the subprocess helper trait**

```php
<?php

namespace Peralta\AgentKit\Tests\Feature\Mcp\Concerns;

trait SpawnsMcpServer
{
    protected function packageRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    protected function fixtureRoot(): string
    {
        return $this->packageRoot() . '/tests/Fixtures/Refactoring/Ast';
    }

    /** @return list<string> */
    protected function serverArguments(array $extra = []): array
    {
        return array_merge(
            [$this->packageRoot() . '/vendor/bin/testbench', 'agent-kit:mcp', '--path=' . $this->fixtureRoot()],
            $extra,
        );
    }

    /** @return array<string, string> */
    protected function serverEnvironment(array $overrides = []): array
    {
        $environment = array_filter(getenv(), 'is_string');
        foreach (array_keys($environment) as $name) {
            if (str_starts_with($name, 'AGENT_KIT_MCP_')) {
                unset($environment[$name]);
            }
        }

        return array_merge($environment, ['APP_ENV' => 'testing'], $overrides);
    }

    /**
     * @return array{0: resource, 1: array{0: resource, 1: resource, 2: resource}}
     */
    protected function spawn(array $arguments, array $environment = []): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->packageRoot(),
            $this->serverEnvironment($environment),
        );
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    /** @return array{status: int, stdout: string, stderr: string} */
    protected function finish($process, array $pipes, float $timeoutSeconds = 30): array
    {
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);

        if ($status['running']) {
            proc_terminate($process, 9);
            self::fail("The MCP server did not exit within {$timeoutSeconds}s.\nSTDERR:\n{$stderr}");
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);

        return ['status' => $status['exitcode'], 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
```

- [ ] **Step 2: Write the failing command tests**

```php
<?php

namespace Peralta\AgentKit\Tests\Feature\Mcp;

use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Peralta\AgentKit\Refactoring\Mcp\RefactoringToolCatalog;
use Peralta\AgentKit\Tests\Feature\Mcp\Concerns\SpawnsMcpServer;
use PHPUnit\Framework\TestCase;

final class StdioServerCommandTest extends TestCase
{
    use SpawnsMcpServer;

    public function test_the_sdk_client_completes_the_full_lifecycle_over_stdio(): void
    {
        $client = Client::builder()
            ->setClientInfo('agent-kit-tests', '1.0.0')
            ->setInitTimeout(20)
            ->setRequestTimeout(60)
            ->setMaxRetries(0)
            ->build();
        $client->connect(new StdioTransport(
            PHP_BINARY,
            $this->serverArguments(['--transport=stdio']),
            cwd: $this->packageRoot(),
            env: $this->serverEnvironment(),
        ));

        try {
            self::assertSame('agent-kit-refactoring', $client->getServerInfo()?->name);
            self::assertSame((new RefactoringToolCatalog())->names(), array_map(fn ($tool) => $tool->name, $client->listTools()->tools));

            $impact = $client->callTool('refactoring_impact', ['target' => 'Fixtures\\Payments\\PaymentService::charge']);
            self::assertFalse($impact->isError);
            self::assertSame('impact', $impact->structuredContent['capability']);
            self::assertSame('charge', $impact->structuredContent['data']['method']);

            self::assertSame('capability_discovery', $client->callTool('refactoring_capabilities')->structuredContent['capability']);
            self::assertArrayHasKey('summary', $client->callTool('refactoring_audit')->structuredContent['data']);
            self::assertSame('CheckoutService.php', $client->callTool('refactoring_analyze', ['target' => 'CheckoutService.php'])->structuredContent['data']['target']);
            self::assertNotEmpty($client->callTool('refactoring_callers', ['target' => 'Fixtures\\Payments\\PaymentService::charge'])->structuredContent['data']['direct_callers']);
            self::assertNotEmpty($client->callTool('refactoring_dependencies', ['target' => 'Fixtures\\Checkout\\CheckoutService'])->structuredContent['data']['upstream_dependencies']);

            $missing = $client->callTool('refactoring_impact', ['target' => 'Missing\\Service']);
            self::assertTrue($missing->isError);
            self::assertSame('TARGET_NOT_FOUND', $missing->structuredContent['error']['code']);

            $resources = $client->listResources()->resources;
            self::assertSame(RefactoringToolCatalog::RESOURCE_URI, $resources[0]->uri);
            $document = json_decode($client->readResource(RefactoringToolCatalog::RESOURCE_URI)->contents[0]->text, true, flags: JSON_THROW_ON_ERROR);
            self::assertFalse($document['mutation']['supported']);
        } finally {
            $client->disconnect();
        }
    }

    public function test_stdout_carries_only_json_rpc_and_logs_go_to_stderr(): void
    {
        [$process, $pipes] = $this->spawn($this->serverArguments(['--transport=stdio']), ['AGENT_KIT_MCP_LOG_LEVEL' => 'debug']);
        fwrite($pipes[0], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1'],
        ]]) . "\n");
        fwrite($pipes[0], "{not json\n");
        fwrite($pipes[0], json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']) . "\n");
        fwrite($pipes[0], json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => (object) []]) . "\n");
        fclose($pipes[0]);

        $run = $this->finish($process, $pipes);

        self::assertSame(0, $run['status'], $run['stderr']);
        $lines = array_values(array_filter(explode("\n", $run['stdout']), fn ($line) => trim($line) !== ''));
        self::assertNotEmpty($lines);
        $codes = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            self::assertIsArray($decoded, "Non JSON-RPC output on stdout: {$line}");
            self::assertSame('2.0', $decoded['jsonrpc']);
            $codes[] = $decoded['error']['code'] ?? null;
        }
        self::assertContains(-32700, $codes);
        self::assertStringContainsString('StdioTransport', $run['stderr']);
        self::assertStringNotContainsString('StdioTransport', $run['stdout']);
    }

    public function test_an_invalid_root_fails_at_startup_without_touching_stdout(): void
    {
        [$process, $pipes] = $this->spawn([$this->packageRoot() . '/vendor/bin/testbench', 'agent-kit:mcp', '--path=/definitely/missing/root']);
        fclose($pipes[0]);

        $run = $this->finish($process, $pipes);

        self::assertSame(1, $run['status']);
        self::assertSame('', $run['stdout']);
        self::assertStringContainsString('does not exist or is not a directory', $run['stderr']);
    }

    public function test_a_disabled_server_refuses_to_start(): void
    {
        [$process, $pipes] = $this->spawn($this->serverArguments(), ['AGENT_KIT_MCP_ENABLED' => 'false']);
        fclose($pipes[0]);

        $run = $this->finish($process, $pipes);

        self::assertSame(1, $run['status']);
        self::assertSame('', $run['stdout']);
        self::assertStringContainsString('AGENT_KIT_MCP_ENABLED', $run['stderr']);
    }

    public function test_an_unknown_transport_is_rejected(): void
    {
        [$process, $pipes] = $this->spawn($this->serverArguments(['--transport=carrier-pigeon']));
        fclose($pipes[0]);

        $run = $this->finish($process, $pipes);

        self::assertSame(1, $run['status']);
        self::assertStringContainsString('Unsupported MCP transport', $run['stderr']);
    }

    public function test_sigterm_stops_the_server_with_exit_code_zero(): void
    {
        if (!function_exists('posix_kill') || !function_exists('pcntl_signal')) {
            self::markTestSkipped('pcntl/posix are required for signal handling.');
        }
        [$process, $pipes] = $this->spawn($this->serverArguments(['--transport=stdio']));
        // Wait until the server logs that it is listening before signalling it.
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + 20;
        $stderr = '';
        while (microtime(true) < $deadline && !str_contains($stderr, 'listening')) {
            $stderr .= (string) stream_get_contents($pipes[2]);
            usleep(50000);
        }
        self::assertStringContainsString('listening', $stderr);

        posix_kill(proc_get_status($process)['pid'], SIGTERM);
        $run = $this->finish($process, $pipes, 10);

        self::assertSame(0, $run['status'], $run['stderr']);
    }
}
```

- [ ] **Step 3: Run and verify RED**

Run: `vendor/bin/phpunit tests/Feature/Mcp/StdioServerCommandTest.php`
Expected: FAIL (`agent-kit:mcp` is not defined; the SDK client reports a connection error).

- [ ] **Step 4: Implement `StdioServerRunner`**

```php
<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport;

use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Transport\StdioTransport;
use Peralta\AgentKit\Refactoring\Analysis\Index\CachedCodebaseIndexer;
use Peralta\AgentKit\Refactoring\Mcp\McpProjectRoot;
use Peralta\AgentKit\Refactoring\Mcp\McpServerFactory;
use Psr\Log\LoggerInterface;

final class StdioServerRunner
{
    public function __construct(
        private readonly McpServerFactory $factory,
        private readonly CachedCodebaseIndexer $indexCache,
    ) {}

    /**
     * @param resource $input
     * @param resource $output
     */
    public function run(McpProjectRoot $root, LoggerInterface $logger, $input, $output): int
    {
        // One long-lived client per process: the session must never expire while idle,
        // and garbage collection has nothing to collect.
        $server = $this->factory->create($root, $logger, new InMemorySessionStore(PHP_INT_MAX), gcProbability: 0);
        $control = new StdioRunnerControl();
        $transport = new StdioTransport($input, $output, $logger, $control);
        $restoreSignals = $this->installSignalHandlers($control, $logger);

        try {
            $logger->info('MCP stdio server listening.', ['project_root' => $root->path]);

            return (int) $server->run($transport);
        } finally {
            $restoreSignals();
            $this->indexCache->clear();
        }
    }

    private function installSignalHandlers(StdioRunnerControl $control, LoggerInterface $logger): \Closure
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return static function (): void {};
        }

        pcntl_async_signals(true);
        $handler = static function (int $signal) use ($control, $logger): void {
            $logger->info('Stopping MCP stdio server on signal.', ['signal' => $signal]);
            $control->stop();
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);

        return static function (): void {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
        };
    }
}
```

- [ ] **Step 5: Implement `McpServeCommand` (stdio only for now)**

```php
<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Commands;

use Illuminate\Console\Command;
use Peralta\AgentKit\Refactoring\Mcp\McpConfigurationException;
use Peralta\AgentKit\Refactoring\Mcp\McpLoggerFactory;
use Peralta\AgentKit\Refactoring\Mcp\McpProjectRoot;
use Peralta\AgentKit\Refactoring\Mcp\Transport\StdioServerRunner;
use Psr\Log\LoggerInterface;

final class McpServeCommand extends Command
{
    protected $signature = 'agent-kit:mcp
        {--transport= : stdio or http; defaults to agent-kit.mcp.transport}
        {--path= : Project root to analyze; defaults to agent-kit.mcp.project_root or the Laravel base path}';

    protected $description = 'Serve the Agent Kit refactoring capabilities to MCP clients (stdio or Streamable HTTP)';

    public function handle(StdioServerRunner $stdio, McpLoggerFactory $loggers): int
    {
        $config = (array) config('agent-kit.mcp', []);
        if (!($config['enabled'] ?? true)) {
            return $this->refuse('The MCP server is disabled (AGENT_KIT_MCP_ENABLED=false).');
        }

        // stdout is the MCP wire on stdio; PHP notices and deprecations must not reach it.
        ini_set('display_errors', 'stderr');

        $transport = strtolower((string) ($this->option('transport') ?: ($config['transport'] ?? 'stdio')));

        try {
            $root = McpProjectRoot::fromPath((string) ($this->option('path') ?: ($config['project_root'] ?: base_path())));
            $logger = $loggers->create((array) ($config['logging'] ?? []));

            return match ($transport) {
                'stdio' => $this->serveStdio($stdio, $root, $logger),
                default => $this->refuse("Unsupported MCP transport: {$transport}. Use stdio or http."),
            };
        } catch (McpConfigurationException $exception) {
            return $this->refuse($exception->getMessage());
        }
    }

    private function serveStdio(StdioServerRunner $stdio, McpProjectRoot $root, LoggerInterface $logger): int
    {
        return $stdio->run($root, $logger, STDIN, STDOUT);
    }

    // Not named fail(): Laravel 11+ Command::fail() exists and throws.
    private function refuse(string $message): int
    {
        $this->output->getErrorStyle()->writeln('<error>' . $message . '</error>');

        return self::FAILURE;
    }
}
```

- [ ] **Step 6: Register the runner and the command**

In `registerMcp()` add:

```php
        $this->app->bind(StdioServerRunner::class, fn ($app) => new StdioServerRunner(
            $app->make(McpServerFactory::class),
            $app->make(CachedCodebaseIndexer::class),
        ));
```

and add `McpServeCommand::class` to the `$this->commands([...])` list in `boot()` (with the corresponding `use` statements).

- [ ] **Step 7: Run and verify GREEN**

Run: `vendor/bin/phpunit tests/Feature/Mcp/StdioServerCommandTest.php`
Expected: PASS, 6 tests (the SIGTERM test may be skipped where `pcntl`/`posix` are missing). If the SDK client times out during `connect()`, run `php vendor/bin/testbench agent-kit:mcp --path=tests/Fixtures/Refactoring/Ast < /dev/null` manually and inspect stderr.

- [ ] **Step 8: Run the whole suite**

Run: `vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add src/Refactoring/Mcp src/AgentKitServiceProvider.php tests/Feature/Mcp
git commit -m "feat: serve the refactoring MCP server over stdio

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

### Task 10: HTTP options, bearer authentication and bounded sessions

**Files:**
- Create: `src/Refactoring/Mcp/Transport/Http/HttpServerOptions.php`
- Create: `src/Refactoring/Mcp/Transport/Http/StaticBearerTokenValidator.php`
- Create: `src/Refactoring/Mcp/Transport/Http/BearerTokenAuthenticationMiddleware.php`
- Create: `src/Refactoring/Mcp/Transport/Http/BoundedInMemorySessionStore.php`
- Test: `tests/Unit/Refactoring/Mcp/Http/HttpServerOptionsTest.php`, `tests/Unit/Refactoring/Mcp/Http/StaticBearerTokenValidatorTest.php`, `tests/Unit/Refactoring/Mcp/Http/BearerTokenAuthenticationMiddlewareTest.php`, `tests/Unit/Refactoring/Mcp/Http/BoundedInMemorySessionStoreTest.php`

**Interfaces:**
- Consumes: `McpConfigurationException`; SDK `AuthorizationTokenValidatorInterface`, `AuthorizationResult`, `InMemorySessionStore`, `NativeClock`; PSR-7/15/17 (`GuzzleHttp\Psr7\HttpFactory`, `GuzzleHttp\Psr7\ServerRequest` in tests).
- Produces: `final readonly class HttpServerOptions { const MIN_TOKEN_LENGTH = 32; public string $host; public int $port; public string $path; public bool $allowRemote; public array $allowedHosts; public string $bearerToken; public int $maxBodyBytes; public int $idleTimeout; public int $maxConcurrentRequests; public int $sessionTtl; public int $maxSessions; static fromConfig(array $config, ?string $host = null, ?int $port = null, bool $allowRemote = false): self; bindUri(): string; static parseAllowedOrigins(string $origins): list<string> }`; `final class StaticBearerTokenValidator implements AuthorizationTokenValidatorInterface { __construct(string $expectedToken); validate(string $accessToken): AuthorizationResult }`; `final class BearerTokenAuthenticationMiddleware implements MiddlewareInterface { __construct(AuthorizationTokenValidatorInterface $validator, ResponseFactoryInterface $responses, StreamFactoryInterface $streams) }`; `final class BoundedInMemorySessionStore extends InMemorySessionStore { __construct(int $ttl, int $maxSessions, ClockInterface $clock = new NativeClock()); count(): int; clear(): void }`.

- [ ] **Step 1: Write the failing option tests**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp\Http;

use Peralta\AgentKit\Refactoring\Mcp\McpConfigurationException;
use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\HttpServerOptions;
use PHPUnit\Framework\TestCase;

final class HttpServerOptionsTest extends TestCase
{
    private const TOKEN = 'test-token-0123456789abcdef0123456789abcdef';

    public function test_defaults_bind_loopback_and_carry_the_configured_limits(): void
    {
        $options = HttpServerOptions::fromConfig($this->config());

        self::assertSame('127.0.0.1', $options->host);
        self::assertSame(8787, $options->port);
        self::assertSame('/mcp', $options->path);
        self::assertFalse($options->allowRemote);
        self::assertSame(['localhost', '127.0.0.1', '[::1]'], $options->allowedHosts);
        self::assertSame(self::TOKEN, $options->bearerToken);
        self::assertSame(1048576, $options->maxBodyBytes);
        self::assertSame(60, $options->idleTimeout);
        self::assertSame(4, $options->maxConcurrentRequests);
        self::assertSame(3600, $options->sessionTtl);
        self::assertSame(100, $options->maxSessions);
        self::assertSame('127.0.0.1:8787', $options->bindUri());
    }

    public function test_http_must_be_enabled_explicitly(): void
    {
        $this->expectException(McpConfigurationException::class);
        $this->expectExceptionMessage('AGENT_KIT_MCP_HTTP_ENABLED=true');
        HttpServerOptions::fromConfig($this->config(['enabled' => false]));
    }

    public function test_a_non_loopback_bind_requires_allow_remote(): void
    {
        $this->expectException(McpConfigurationException::class);
        $this->expectExceptionMessage('--allow-remote');
        HttpServerOptions::fromConfig($this->config(), host: '0.0.0.0');
    }

    public function test_allow_remote_adds_the_bind_host_to_the_allowlist_but_keeps_the_token_mandatory(): void
    {
        $options = HttpServerOptions::fromConfig($this->config(), host: '192.168.1.10', allowRemote: true);
        self::assertTrue($options->allowRemote);
        self::assertContains('192.168.1.10', $options->allowedHosts);

        $this->expectException(McpConfigurationException::class);
        $this->expectExceptionMessage('AGENT_KIT_MCP_BEARER_TOKEN');
        HttpServerOptions::fromConfig($this->config(['bearer_token' => null]), host: '0.0.0.0', allowRemote: true);
    }

    public function test_short_tokens_are_rejected(): void
    {
        $this->expectException(McpConfigurationException::class);
        $this->expectExceptionMessage('at least 32 characters');
        HttpServerOptions::fromConfig($this->config(['bearer_token' => 'too-short']));
    }

    public function test_ports_and_limits_are_validated(): void
    {
        foreach ([
            ['port' => 0],
            ['port' => 70000],
            ['max_body_bytes' => 0],
            ['idle_timeout' => 0],
            ['max_concurrent_requests' => 0],
            ['session_ttl' => 0],
            ['max_sessions' => 0],
        ] as $override) {
            try {
                HttpServerOptions::fromConfig($this->config($override));
                self::fail('Expected rejection for ' . json_encode($override));
            } catch (McpConfigurationException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_allowed_origins_are_reduced_to_lower_case_hosts(): void
    {
        self::assertSame(
            ['localhost', 'mcp.internal', '[::1]', 'claude.example'],
            HttpServerOptions::parseAllowedOrigins(' http://LOCALHOST:6274 , mcp.internal:9000, [::1]:8787,, https://claude.example/ '),
        );

        $options = HttpServerOptions::fromConfig($this->config(['allowed_origins' => 'http://localhost:6274,tools.internal']));
        self::assertSame(['localhost', '127.0.0.1', '[::1]', 'tools.internal'], $options->allowedHosts);
    }

    public function test_ipv6_binds_are_bracketed_in_the_bind_uri(): void
    {
        self::assertSame('[::1]:8787', HttpServerOptions::fromConfig($this->config(), host: '::1')->bindUri());
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'host' => '127.0.0.1',
            'port' => 8787,
            'path' => '/mcp',
            'allow_remote' => false,
            'allowed_origins' => '',
            'bearer_token' => self::TOKEN,
            'max_body_bytes' => 1048576,
            'idle_timeout' => 60,
            'max_concurrent_requests' => 4,
            'session_ttl' => 3600,
            'max_sessions' => 100,
        ], $overrides);
    }
}
```

- [ ] **Step 2: Write the failing validator, middleware and session-store tests**

`StaticBearerTokenValidatorTest.php`:

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp\Http;

use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\StaticBearerTokenValidator;
use PHPUnit\Framework\TestCase;

final class StaticBearerTokenValidatorTest extends TestCase
{
    private const TOKEN = 'test-token-0123456789abcdef0123456789abcdef';

    public function test_the_exact_token_is_allowed(): void
    {
        self::assertTrue((new StaticBearerTokenValidator(self::TOKEN))->validate(self::TOKEN)->isAllowed());
    }

    public function test_other_tokens_are_unauthorized_without_echoing_them(): void
    {
        $result = (new StaticBearerTokenValidator(self::TOKEN))->validate(self::TOKEN . 'x');

        self::assertFalse($result->isAllowed());
        self::assertSame(401, $result->getStatusCode());
        self::assertSame('invalid_token', $result->getError());
        self::assertStringNotContainsString(self::TOKEN, (string) $result->getErrorDescription());
    }

    public function test_an_empty_expected_token_is_rejected_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StaticBearerTokenValidator('');
    }
}
```

`BearerTokenAuthenticationMiddlewareTest.php`:

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp\Http;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\BearerTokenAuthenticationMiddleware;
use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\StaticBearerTokenValidator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class BearerTokenAuthenticationMiddlewareTest extends TestCase
{
    private const TOKEN = 'test-token-0123456789abcdef0123456789abcdef';

    public function test_a_valid_bearer_token_reaches_the_handler(): void
    {
        $response = $this->process(['Authorization' => 'Bearer ' . self::TOKEN]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('handled', (string) $response->getBody());
    }

    public function test_a_missing_header_is_401_with_a_bearer_challenge(): void
    {
        $response = $this->process([]);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringStartsWith('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('unauthorized', json_decode((string) $response->getBody(), true)['error']);
    }

    public function test_an_invalid_token_is_401_and_never_echoed(): void
    {
        $response = $this->process(['Authorization' => 'Bearer wrong-' . self::TOKEN]);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('error="invalid_token"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertStringNotContainsString(self::TOKEN, (string) $response->getBody());
        self::assertStringNotContainsString(self::TOKEN, $response->getHeaderLine('WWW-Authenticate'));
    }

    public function test_a_malformed_scheme_is_400(): void
    {
        self::assertSame(400, $this->process(['Authorization' => 'Basic abc'])->getStatusCode());
        self::assertSame(400, $this->process(['Authorization' => 'Bearer'])->getStatusCode());
    }

    public function test_a_token_in_the_query_string_is_ignored(): void
    {
        $response = $this->process([], 'http://127.0.0.1:8787/mcp?access_token=' . self::TOKEN);

        self::assertSame(401, $response->getStatusCode());
    }

    private function process(array $headers, string $uri = 'http://127.0.0.1:8787/mcp'): ResponseInterface
    {
        $factory = new HttpFactory();
        $middleware = new BearerTokenAuthenticationMiddleware(new StaticBearerTokenValidator(self::TOKEN), $factory, $factory);
        $handler = new class($factory) implements RequestHandlerInterface {
            public function __construct(private readonly HttpFactory $factory) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200)->withBody($this->factory->createStream('handled'));
            }
        };

        return $middleware->process(new ServerRequest('POST', $uri, $headers), $handler);
    }
}
```

`BoundedInMemorySessionStoreTest.php`:

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp\Http;

use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\BoundedInMemorySessionStore;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class BoundedInMemorySessionStoreTest extends TestCase
{
    public function test_it_evicts_the_oldest_session_when_the_bound_is_reached(): void
    {
        $clock = $this->clock();
        $store = new BoundedInMemorySessionStore(3600, 2, $clock);
        [$a, $b, $c] = [Uuid::v4(), Uuid::v4(), Uuid::v4()];

        $store->write($a, 'a');
        $clock->now = $clock->now->modify('+1 second');
        $store->write($b, 'b');
        $clock->now = $clock->now->modify('+1 second');
        $store->write($c, 'c');

        self::assertSame(2, $store->count());
        self::assertFalse($store->exists($a));
        self::assertTrue($store->exists($b));
        self::assertTrue($store->exists($c));
    }

    public function test_updating_an_existing_session_never_evicts(): void
    {
        $store = new BoundedInMemorySessionStore(3600, 1, $this->clock());
        $id = Uuid::v4();

        $store->write($id, 'first');
        $store->write($id, 'second');

        self::assertSame('second', $store->read($id));
        self::assertSame(1, $store->count());
    }

    public function test_expired_sessions_are_garbage_collected_and_clear_empties_the_store(): void
    {
        $clock = $this->clock();
        $store = new BoundedInMemorySessionStore(10, 5, $clock);
        $id = Uuid::v4();
        $store->write($id, 'data');

        $clock->now = $clock->now->modify('+11 seconds');
        self::assertCount(1, $store->gc());
        self::assertFalse($store->exists($id));

        $store->write(Uuid::v4(), 'x');
        $store->clear();
        self::assertSame(0, $store->count());
    }

    public function test_a_zero_bound_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BoundedInMemorySessionStore(10, 0);
    }

    /** @return ClockInterface&object{now: \DateTimeImmutable} */
    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public \DateTimeImmutable $now;

            public function __construct()
            {
                $this->now = new \DateTimeImmutable('2026-09-16 12:00:00');
            }

            public function now(): \DateTimeImmutable
            {
                return $this->now;
            }
        };
    }
}
```

- [ ] **Step 3: Run and verify RED**

Run: `vendor/bin/phpunit tests/Unit/Refactoring/Mcp/Http`
Expected: FAIL (classes missing).

- [ ] **Step 4: Implement `HttpServerOptions`**

```php
<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport\Http;

use Peralta\AgentKit\Refactoring\Mcp\McpConfigurationException;

final readonly class HttpServerOptions
{
    public const MIN_TOKEN_LENGTH = 32;

    private const LOOPBACK_HOSTS = ['127.0.0.1', '::1', '[::1]', 'localhost'];
    private const DEFAULT_ALLOWED_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /** @param list<string> $allowedHosts */
    private function __construct(
        public string $host,
        public int $port,
        public string $path,
        public bool $allowRemote,
        public array $allowedHosts,
        public string $bearerToken,
        public int $maxBodyBytes,
        public int $idleTimeout,
        public int $maxConcurrentRequests,
        public int $sessionTtl,
        public int $maxSessions,
    ) {}

    /** @param array<string, mixed> $config the agent-kit.mcp.http array */
    public static function fromConfig(array $config, ?string $host = null, ?int $port = null, bool $allowRemote = false): self
    {
        if (!($config['enabled'] ?? false)) {
            throw new McpConfigurationException('The MCP HTTP transport is disabled. Set AGENT_KIT_MCP_HTTP_ENABLED=true to enable it.');
        }

        $host = self::hostOf((string) ($host ?? $config['host'] ?? '127.0.0.1'));
        $allowRemote = $allowRemote || (bool) ($config['allow_remote'] ?? false);
        if (!in_array($host, self::LOOPBACK_HOSTS, true) && !$allowRemote) {
            throw new McpConfigurationException(
                "Refusing to bind the MCP HTTP transport to {$host}: pass --allow-remote (or set AGENT_KIT_MCP_ALLOW_REMOTE=true) to expose it beyond loopback.",
            );
        }

        $token = (string) ($config['bearer_token'] ?? '');
        if (strlen($token) < self::MIN_TOKEN_LENGTH) {
            throw new McpConfigurationException(
                'The MCP HTTP transport requires AGENT_KIT_MCP_BEARER_TOKEN with at least 32 characters. Generate one with: php -r \'echo bin2hex(random_bytes(32));\'',
            );
        }

        $port = $port ?? (int) ($config['port'] ?? 8787);
        if ($port < 1 || $port > 65535) {
            throw new McpConfigurationException("The MCP HTTP port must be between 1 and 65535, got {$port}.");
        }

        $allowedHosts = array_values(array_unique(array_merge(
            self::DEFAULT_ALLOWED_HOSTS,
            self::parseAllowedOrigins((string) ($config['allowed_origins'] ?? '')),
            $allowRemote ? [$host] : [],
        )));

        return new self(
            host: $host,
            port: $port,
            path: '/' . ltrim((string) ($config['path'] ?? '/mcp'), '/'),
            allowRemote: $allowRemote,
            allowedHosts: $allowedHosts,
            bearerToken: $token,
            maxBodyBytes: self::positive($config, 'max_body_bytes', 'AGENT_KIT_MCP_HTTP_MAX_BODY_BYTES'),
            idleTimeout: self::positive($config, 'idle_timeout', 'AGENT_KIT_MCP_HTTP_IDLE_TIMEOUT'),
            maxConcurrentRequests: self::positive($config, 'max_concurrent_requests', 'AGENT_KIT_MCP_HTTP_MAX_CONCURRENT'),
            sessionTtl: self::positive($config, 'session_ttl', 'AGENT_KIT_MCP_HTTP_SESSION_TTL'),
            maxSessions: self::positive($config, 'max_sessions', 'AGENT_KIT_MCP_HTTP_MAX_SESSIONS'),
        );
    }

    public function bindUri(): string
    {
        $host = str_contains($this->host, ':') && !str_starts_with($this->host, '[') ? "[{$this->host}]" : $this->host;

        return $host . ':' . $this->port;
    }

    /** @return list<string> lower-cased hosts, ports and schemes stripped, IPv6 kept bracketed */
    public static function parseAllowedOrigins(string $origins): array
    {
        $hosts = [];
        foreach (explode(',', $origins) as $origin) {
            $host = self::hostOf(trim($origin));
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    private static function hostOf(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        if (str_contains($value, '://')) {
            $host = parse_url($value, PHP_URL_HOST);

            return is_string($host) ? $host : '';
        }
        if (str_starts_with($value, '[')) {
            $closing = strpos($value, ']');

            return $closing === false ? '' : substr($value, 0, $closing + 1);
        }
        // A bare IPv6 literal (::1, fe80::1) has several colons and no brackets; keep it whole, bracketed.
        if (substr_count($value, ':') > 1) {
            return '[' . $value . ']';
        }

        return explode(':', $value, 2)[0];
    }

    private static function positive(array $config, string $key, string $variable): int
    {
        $value = (int) ($config[$key] ?? 0);
        if ($value < 1) {
            throw new McpConfigurationException("{$variable} must be a positive integer, got {$value}.");
        }

        return $value;
    }
}
```

- [ ] **Step 5: Implement the validator and the middleware**

`StaticBearerTokenValidator.php`:

```php
<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport\Http;

use InvalidArgumentException;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;

final class StaticBearerTokenValidator implements AuthorizationTokenValidatorInterface
{
    public function __construct(private readonly string $expectedToken)
    {
        if ($expectedToken === '') {
            throw new InvalidArgumentException('The expected bearer token must not be empty.');
        }
    }

    public function validate(string $accessToken): AuthorizationResult
    {
        if (!hash_equals($this->expectedToken, $accessToken)) {
            return AuthorizationResult::unauthorized('invalid_token', 'The bearer token is not valid.');
        }

        return AuthorizationResult::allow(['auth.scheme' => 'bearer']);
    }
}
```

`BearerTokenAuthenticationMiddleware.php`:

```php
<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport\Http;

use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class BearerTokenAuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthorizationTokenValidatorInterface $validator,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only the Authorization header is consulted; query strings and cookies are never read.
        $authorization = trim($request->getHeaderLine('Authorization'));
        if ($authorization === '') {
            return $this->deny(AuthorizationResult::unauthorized(null, 'Bearer token required.'));
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches) !== 1) {
            return $this->deny(AuthorizationResult::badRequest('invalid_request', 'Malformed Authorization header.'));
        }

        $result = $this->validator->validate($matches[1]);
        if (!$result->isAllowed()) {
            return $this->deny($result);
        }

        foreach ($result->getAttributes() as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $handler->handle($request);
    }

    private function deny(AuthorizationResult $result): ResponseInterface
    {
        $challenge = 'Bearer';
        if ($result->getError() !== null) {
            $challenge .= ' error="' . $result->getError() . '"';
        }

        $body = json_encode([
            'error' => $result->getError() ?? 'unauthorized',
            'message' => $result->getErrorDescription() ?? 'Authentication required.',
        ], JSON_THROW_ON_ERROR);

        return $this->responses->createResponse($result->getStatusCode())
            ->withHeader('WWW-Authenticate', $challenge)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream($body));
    }
}
```

- [ ] **Step 6: Implement `BoundedInMemorySessionStore`**

```php
<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport\Http;

use InvalidArgumentException;
use Mcp\Server\NativeClock;
use Mcp\Server\Session\InMemorySessionStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class BoundedInMemorySessionStore extends InMemorySessionStore
{
    public function __construct(
        int $ttl,
        private readonly int $maxSessions,
        ClockInterface $clock = new NativeClock(),
    ) {
        if ($maxSessions < 1) {
            throw new InvalidArgumentException('The session store must allow at least one session.');
        }
        parent::__construct($ttl, $clock);
    }

    public function write(Uuid $id, string $data): bool
    {
        if (!isset($this->store[$id->toRfc4122()]) && count($this->store) >= $this->maxSessions) {
            $this->evictOldest();
        }

        return parent::write($id, $data);
    }

    public function count(): int
    {
        return count($this->store);
    }

    public function clear(): void
    {
        $this->store = [];
    }

    private function evictOldest(): void
    {
        $oldestKey = null;
        $oldestTimestamp = PHP_INT_MAX;
        foreach ($this->store as $key => $session) {
            if ($session['timestamp'] < $oldestTimestamp) {
                $oldestTimestamp = $session['timestamp'];
                $oldestKey = $key;
            }
        }
        if ($oldestKey !== null) {
            unset($this->store[$oldestKey]);
        }
    }
}
```

- [ ] **Step 7: Run and verify GREEN**

Run: `vendor/bin/phpunit tests/Unit/Refactoring/Mcp/Http`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Refactoring/Mcp/Transport/Http tests/Unit/Refactoring/Mcp/Http
git commit -m "feat: add MCP HTTP options, bearer auth and bounded sessions

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

### Task 11: HTTP transport factory and the PSR-15 pipeline status matrix

**Files:**
- Create: `src/Refactoring/Mcp/Transport/Http/HttpTransportFactory.php`
- Test: `tests/Feature/Mcp/HttpTransportPipelineTest.php`

**Interfaces:**
- Consumes: `HttpServerOptions`, `BearerTokenAuthenticationMiddleware`, `StaticBearerTokenValidator`, `BoundedInMemorySessionStore`, `McpServerFactory`; SDK `StreamableHttpTransport`, `CorsMiddleware`, `DnsRebindingProtectionMiddleware`, `Server`.
- Produces: `final class HttpTransportFactory { static fromOptions(HttpServerOptions $options): self; middleware(): list<MiddlewareInterface>; create(ServerRequestInterface $request, LoggerInterface $logger): StreamableHttpTransport; handle(Server $server, ServerRequestInterface $request, LoggerInterface $logger): ResponseInterface }`.

- [ ] **Step 1: Write the failing pipeline tests**

```php
<?php

namespace Peralta\AgentKit\Tests\Feature\Mcp;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\ServerRequest;
use Mcp\Server;
use Peralta\AgentKit\Refactoring\Application\RefactoringCapabilities;
use Peralta\AgentKit\Refactoring\Mcp\McpProjectRoot;
use Peralta\AgentKit\Refactoring\Mcp\McpServerFactory;
use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\BoundedInMemorySessionStore;
use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\HttpServerOptions;
use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\HttpTransportFactory;
use Peralta\AgentKit\Tests\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

final class HttpTransportPipelineTest extends TestCase
{
    private const TOKEN = 'test-token-0123456789abcdef0123456789abcdef';
    private const ENDPOINT = 'http://127.0.0.1:8787/mcp';

    private Server $server;
    private HttpTransportFactory $factory;
    private BoundedInMemorySessionStore $sessions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessions = new BoundedInMemorySessionStore(3600, 100);
        $this->server = $this->app->make(McpServerFactory::class)->create(
            McpProjectRoot::fromPath(dirname(__DIR__, 2) . '/Fixtures/Refactoring/Ast'),
            new NullLogger(),
            $this->sessions,
        );
        $this->factory = HttpTransportFactory::fromOptions($this->options());
    }

    public function test_options_preflight_is_answered_without_authentication(): void
    {
        self::assertSame(204, $this->send('OPTIONS', [])->getStatusCode());
    }

    public function test_requests_without_a_bearer_token_are_401_before_any_mcp_processing(): void
    {
        $response = $this->send('POST', [], $this->initialize());

        self::assertSame(401, $response->getStatusCode());
        self::assertStringStartsWith('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        self::assertFalse($response->hasHeader('Mcp-Session-Id'));
        $this->assertNoSecrets($response);
    }

    public function test_wrong_tokens_and_query_string_tokens_are_401(): void
    {
        self::assertSame(401, $this->send('POST', ['Authorization' => 'Bearer nope-' . self::TOKEN], $this->initialize())->getStatusCode());
        self::assertSame(401, $this->send('POST', [], $this->initialize(), self::ENDPOINT . '?access_token=' . self::TOKEN)->getStatusCode());
    }

    public function test_a_disallowed_origin_is_403_even_without_credentials(): void
    {
        $response = $this->send('POST', ['Origin' => 'http://evil.example'], $this->initialize());

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_a_disallowed_host_without_origin_is_403(): void
    {
        self::assertSame(403, $this->send('POST', ['Host' => 'evil.example'], $this->initialize())->getStatusCode());
    }

    public function test_allowlisted_origins_and_absent_origins_from_loopback_are_accepted(): void
    {
        self::assertSame(200, $this->send('POST', $this->auth(['Origin' => 'http://localhost:6274']), $this->initialize())->getStatusCode());
        self::assertSame(200, $this->send('POST', $this->auth(), $this->initialize())->getStatusCode());
    }

    public function test_get_is_405_with_an_allow_header(): void
    {
        $response = $this->send('GET', $this->auth());

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST, DELETE, OPTIONS', $response->getHeaderLine('Allow'));
    }

    public function test_initialize_opens_a_session_and_tools_call_returns_the_capability_envelope(): void
    {
        $initialize = $this->send('POST', $this->auth(), $this->initialize());
        self::assertSame(200, $initialize->getStatusCode());
        self::assertSame('application/json', $initialize->getHeaderLine('Content-Type'));
        $session = $initialize->getHeaderLine('Mcp-Session-Id');
        self::assertNotSame('', $session);
        $body = json_decode((string) $initialize->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('2025-11-25', $body['result']['protocolVersion']);
        self::assertSame('agent-kit-refactoring', $body['result']['serverInfo']['name']);

        self::assertSame(202, $this->send('POST', $this->auth(['Mcp-Session-Id' => $session]), $this->notification('notifications/initialized'))->getStatusCode());

        $call = $this->send('POST', $this->auth(['Mcp-Session-Id' => $session, 'MCP-Protocol-Version' => '2025-11-25']), $this->request(2, 'tools/call', [
            'name' => 'refactoring_impact',
            'arguments' => ['target' => 'Fixtures\\Payments\\PaymentService::charge'],
        ]));
        self::assertSame(200, $call->getStatusCode());
        $result = json_decode((string) $call->getBody(), true, flags: JSON_THROW_ON_ERROR)['result'];
        $expected = $this->app->make(RefactoringCapabilities::class)
            ->impact(McpProjectRoot::fromPath(dirname(__DIR__, 2) . '/Fixtures/Refactoring/Ast')->path, 'Fixtures\\Payments\\PaymentService::charge')
            ->toArray();
        self::assertSame($expected, $result['structuredContent']);
    }

    public function test_session_header_rules_follow_the_sdk(): void
    {
        $session = $this->openSession();

        self::assertSame(400, $this->send('POST', $this->auth(), $this->request(2, 'tools/list'))->getStatusCode(), 'missing session');
        self::assertSame(400, $this->send('POST', $this->auth(['Mcp-Session-Id' => 'not-a-uuid']), $this->request(2, 'tools/list'))->getStatusCode(), 'malformed session');
        self::assertSame(404, $this->send('POST', $this->auth(['Mcp-Session-Id' => '0f6c9b2e-4e3d-4a0e-9a4b-3c8f1e2d5a6b']), $this->request(2, 'tools/list'))->getStatusCode(), 'unknown session');
        self::assertSame(400, $this->send('POST', $this->auth(['Mcp-Session-Id' => $session, 'MCP-Protocol-Version' => '1999-01-01']), $this->request(2, 'tools/list'))->getStatusCode(), 'unsupported protocol version header');
        self::assertSame(200, $this->send('POST', $this->auth(['Mcp-Session-Id' => $session]), $this->request(2, 'tools/list'))->getStatusCode(), 'valid session');
    }

    public function test_delete_ends_the_session_and_requires_a_session_header(): void
    {
        $session = $this->openSession();

        self::assertSame(400, $this->send('DELETE', $this->auth())->getStatusCode());
        self::assertSame(200, $this->send('DELETE', $this->auth(['Mcp-Session-Id' => $session]))->getStatusCode());
        self::assertSame(404, $this->send('POST', $this->auth(['Mcp-Session-Id' => $session]), $this->request(2, 'tools/list'))->getStatusCode());
        self::assertSame(0, $this->sessions->count());
    }

    public function test_invalid_json_is_a_parse_error_and_batches_are_answered_in_one_body(): void
    {
        $response = $this->send('POST', $this->auth(), '{not json');

        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(-32700, json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
    }

    public function test_bodies_over_the_limit_are_413(): void
    {
        $factory = HttpTransportFactory::fromOptions($this->options(['max_body_bytes' => 64]));
        $response = $factory->handle($this->server, new ServerRequest('POST', self::ENDPOINT, $this->auth(), str_repeat('{"jsonrpc":"2.0"}', 10)), new NullLogger());

        self::assertSame(413, $response->getStatusCode());
    }

    public function test_unrouted_paths_are_404(): void
    {
        self::assertSame(404, $this->send('POST', $this->auth(), $this->initialize(), 'http://127.0.0.1:8787/other')->getStatusCode());
    }

    public function test_unhandled_transport_failures_are_500_without_stack_traces(): void
    {
        $body = FnStream::decorate(\GuzzleHttp\Psr7\Utils::streamFor('{}'), [
            'read' => static fn () => throw new \RuntimeException('disk exploded at /secret/path'),
            'getContents' => static fn () => throw new \RuntimeException('disk exploded at /secret/path'),
            'getSize' => static fn () => null,
        ]);

        $response = $this->factory->handle($this->server, new ServerRequest('POST', self::ENDPOINT, $this->auth(), $body), new NullLogger());

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['error' => 'internal_error', 'message' => 'The MCP server could not process the request.'], json_decode((string) $response->getBody(), true));
        self::assertStringNotContainsString('/secret/path', (string) $response->getBody());
    }

    private function openSession(): string
    {
        $response = $this->send('POST', $this->auth(), $this->initialize());
        self::assertSame(200, $response->getStatusCode());
        $this->send('POST', $this->auth(['Mcp-Session-Id' => $response->getHeaderLine('Mcp-Session-Id')]), $this->notification('notifications/initialized'));

        return $response->getHeaderLine('Mcp-Session-Id');
    }

    private function send(string $method, array $headers, ?string $body = null, string $uri = self::ENDPOINT): ResponseInterface
    {
        $headers += ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json'];
        $response = $this->factory->handle($this->server, new ServerRequest($method, $uri, $headers, $body), new NullLogger());
        $this->assertNoSecrets($response);

        return $response;
    }

    private function auth(array $headers = []): array
    {
        return $headers + ['Authorization' => 'Bearer ' . self::TOKEN];
    }

    private function initialize(): string
    {
        return $this->request(1, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'agent-kit-tests', 'version' => '1.0.0'],
        ]);
    }

    private function request(int $id, string $method, array $params = []): string
    {
        return json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params === [] ? (object) [] : $params], JSON_THROW_ON_ERROR);
    }

    private function notification(string $method): string
    {
        return json_encode(['jsonrpc' => '2.0', 'method' => $method], JSON_THROW_ON_ERROR);
    }

    private function assertNoSecrets(ResponseInterface $response): void
    {
        $body = (string) $response->getBody();
        $response->getBody()->rewind();
        self::assertStringNotContainsString(self::TOKEN, $body);
        self::assertStringNotContainsString('Stack trace', $body);
        self::assertStringNotContainsString('#0 ', $body);
    }

    private function options(array $overrides = []): HttpServerOptions
    {
        return HttpServerOptions::fromConfig(array_merge([
            'enabled' => true, 'host' => '127.0.0.1', 'port' => 8787, 'path' => '/mcp', 'allow_remote' => false,
            'allowed_origins' => '', 'bearer_token' => self::TOKEN, 'max_body_bytes' => 1048576, 'idle_timeout' => 60,
            'max_concurrent_requests' => 4, 'session_ttl' => 3600, 'max_sessions' => 100,
        ], $overrides));
    }
}
```

- [ ] **Step 2: Run and verify RED**

Run: `vendor/bin/phpunit tests/Feature/Mcp/HttpTransportPipelineTest.php`
Expected: FAIL (`HttpTransportFactory` missing).

- [ ] **Step 3: Implement `HttpTransportFactory`**

```php
<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport\Http;

use GuzzleHttp\Psr7\HttpFactory;
use Mcp\Server;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class HttpTransportFactory
{
    public function __construct(
        private readonly HttpServerOptions $options,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public static function fromOptions(HttpServerOptions $options): self
    {
        $factory = new HttpFactory();

        return new self($options, $factory, $factory);
    }

    /** @return list<MiddlewareInterface> outermost first: CORS, Origin/Host allowlist, bearer auth */
    public function middleware(): array
    {
        return [
            new CorsMiddleware(),
            new DnsRebindingProtectionMiddleware($this->options->allowedHosts, $this->responses, $this->streams),
            new BearerTokenAuthenticationMiddleware(
                new StaticBearerTokenValidator($this->options->bearerToken),
                $this->responses,
                $this->streams,
            ),
        ];
    }

    public function create(ServerRequestInterface $request, LoggerInterface $logger): StreamableHttpTransport
    {
        return new StreamableHttpTransport(
            $request,
            $this->responses,
            $this->streams,
            $logger,
            $this->middleware(),
            $this->options->maxBodyBytes,
        );
    }

    public function handle(Server $server, ServerRequestInterface $request, LoggerInterface $logger): ResponseInterface
    {
        if ($request->getUri()->getPath() !== $this->options->path) {
            return $this->json(404, ['error' => 'not_found', 'message' => 'The MCP endpoint is ' . $this->options->path . '.']);
        }

        try {
            $response = $server->run($this->create($request, $logger));
            if (!$response instanceof ResponseInterface) {
                throw new \UnexpectedValueException('The MCP transport did not produce a PSR-7 response.');
            }

            return $response;
        } catch (Throwable $exception) {
            $logger->error('Unhandled error while serving an MCP HTTP request.', ['exception' => $exception]);

            return $this->json(500, ['error' => 'internal_error', 'message' => 'The MCP server could not process the request.']);
        }
    }

    private function json(int $status, array $payload): ResponseInterface
    {
        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream(json_encode($payload, JSON_THROW_ON_ERROR)));
    }
}
```

- [ ] **Step 4: Run and verify GREEN**

Run: `vendor/bin/phpunit tests/Feature/Mcp/HttpTransportPipelineTest.php`
Expected: PASS, 14 tests. If the SDK answers a row of the matrix with a different status than asserted, read `vendor/mcp/sdk/src/Server/Transport/StreamableHttpTransport.php` and `Server/Protocol.php::resolveSession()` to confirm the SDK-defined code, then fix the assertion and note the observed code in `MCP_SERVER.md` (Task 14).

- [ ] **Step 5: Commit**

```bash
git add src/Refactoring/Mcp/Transport/Http/HttpTransportFactory.php tests/Feature/Mcp/HttpTransportPipelineTest.php
git commit -m "feat: compose the MCP Streamable HTTP pipeline

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

### Task 12: ReactPHP listener and `--transport=http`

**Files:**
- Create: `src/Refactoring/Mcp/Transport/Http/ReactHttpListener.php`
- Modify: `src/Refactoring/Mcp/Commands/McpServeCommand.php`
- Modify: `src/AgentKitServiceProvider.php` (`registerMcp()`)
- Test: `tests/Feature/Mcp/HttpListenerCommandTest.php`

**Interfaces:**
- Consumes: `HttpServerOptions`, `HttpTransportFactory`, `BoundedInMemorySessionStore`, `McpServerFactory`, `CachedCodebaseIndexer`; `react/http` (`HttpServer`, `Message\Response`, middlewares), `react/socket` (`SocketServer`, `ConnectionInterface`), `react/event-loop` (`Loop`); SDK client `HttpTransport`.
- Produces: `final class ReactHttpListener { __construct(McpServerFactory $factory, CachedCodebaseIndexer $indexCache); listen(HttpServerOptions $options, McpProjectRoot $root, LoggerInterface $logger): int }`; command options `--host=`, `--port=`, `--allow-remote`.

- [ ] **Step 1: Write the failing listener tests**

```php
<?php

namespace Peralta\AgentKit\Tests\Feature\Mcp;

use GuzzleHttp\Client as HttpClient;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Peralta\AgentKit\Refactoring\Mcp\RefactoringToolCatalog;
use Peralta\AgentKit\Tests\Feature\Mcp\Concerns\SpawnsMcpServer;
use PHPUnit\Framework\TestCase;

final class HttpListenerCommandTest extends TestCase
{
    use SpawnsMcpServer;

    private const TOKEN = 'test-token-0123456789abcdef0123456789abcdef';

    /** @var resource|null */
    private $process = null;
    private array $pipes = [];

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 15);
            $this->finish($this->process, $this->pipes, 10);
        }
        parent::tearDown();
    }

    public function test_the_sdk_client_completes_the_lifecycle_over_http_and_delete_closes_the_session(): void
    {
        $port = $this->startListener();
        $client = Client::builder()->setClientInfo('agent-kit-tests', '1.0.0')->setInitTimeout(20)->setRequestTimeout(60)->setMaxRetries(0)->build();
        $client->connect(new HttpTransport("http://127.0.0.1:{$port}/mcp", ['Authorization' => 'Bearer ' . self::TOKEN], new HttpClient()));

        try {
            self::assertSame((new RefactoringToolCatalog())->names(), array_map(fn ($tool) => $tool->name, $client->listTools()->tools));
            $impact = $client->callTool('refactoring_impact', ['target' => 'Fixtures\\Payments\\PaymentService::charge']);
            self::assertFalse($impact->isError);
            self::assertSame('charge', $impact->structuredContent['data']['method']);
            self::assertTrue($client->callTool('refactoring_impact', ['target' => 'Missing\\Service'])->isError);
            self::assertSame(RefactoringToolCatalog::RESOURCE_URI, $client->listResources()->resources[0]->uri);
        } finally {
            $client->disconnect();
        }
    }

    public function test_plain_http_clients_get_the_documented_status_codes(): void
    {
        $port = $this->startListener();
        $http = new HttpClient(['base_uri' => "http://127.0.0.1:{$port}", 'http_errors' => false]);

        self::assertSame(401, $http->post('/mcp', ['body' => '{}'])->getStatusCode());
        self::assertSame(405, $http->get('/mcp', ['headers' => ['Authorization' => 'Bearer ' . self::TOKEN]])->getStatusCode());
        self::assertSame(404, $http->post('/elsewhere', ['headers' => ['Authorization' => 'Bearer ' . self::TOKEN]])->getStatusCode());
        self::assertSame(403, $http->post('/mcp', ['headers' => ['Origin' => 'http://evil.example']])->getStatusCode());
        self::assertSame(413, $http->post('/mcp', [
            'headers' => ['Authorization' => 'Bearer ' . self::TOKEN, 'Content-Type' => 'application/json'],
            'body' => str_repeat('{"jsonrpc":"2.0"}', 200),
        ])->getStatusCode());
    }

    public function test_idle_connections_are_closed_after_the_configured_timeout(): void
    {
        $port = $this->startListener();
        $socket = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 5);
        self::assertIsResource($socket, $error);
        stream_set_timeout($socket, 6);

        $started = microtime(true);
        $read = fread($socket, 1);
        $elapsed = microtime(true) - $started;
        $meta = stream_get_meta_data($socket);

        self::assertFalse($meta['timed_out'], 'The server kept an idle connection open past the idle timeout.');
        self::assertTrue($read === '' || $read === false);
        self::assertGreaterThan(0.5, $elapsed);
        fclose($socket);
    }

    public function test_remote_binds_are_refused_without_opt_in(): void
    {
        [$process, $pipes] = $this->spawn($this->serverArguments(['--transport=http', '--host=0.0.0.0', '--port=' . $this->freePort()]), $this->httpEnvironment());
        fclose($pipes[0]);
        $run = $this->finish($process, $pipes);

        self::assertSame(1, $run['status']);
        self::assertSame('', $run['stdout']);
        self::assertStringContainsString('--allow-remote', $run['stderr']);
    }

    public function test_http_refuses_to_start_without_a_token_or_when_disabled(): void
    {
        [$process, $pipes] = $this->spawn($this->serverArguments(['--transport=http', '--port=' . $this->freePort()]), $this->httpEnvironment(['AGENT_KIT_MCP_BEARER_TOKEN' => '']));
        fclose($pipes[0]);
        $run = $this->finish($process, $pipes);
        self::assertSame(1, $run['status']);
        self::assertStringContainsString('AGENT_KIT_MCP_BEARER_TOKEN', $run['stderr']);

        [$process, $pipes] = $this->spawn($this->serverArguments(['--transport=http', '--port=' . $this->freePort()]), $this->httpEnvironment(['AGENT_KIT_MCP_HTTP_ENABLED' => 'false']));
        fclose($pipes[0]);
        $run = $this->finish($process, $pipes);
        self::assertSame(1, $run['status']);
        self::assertStringContainsString('AGENT_KIT_MCP_HTTP_ENABLED', $run['stderr']);
    }

    private function startListener(): int
    {
        $port = $this->freePort();
        [$this->process, $this->pipes] = $this->spawn(
            $this->serverArguments(['--transport=http', '--host=127.0.0.1', '--port=' . $port]),
            $this->httpEnvironment(),
        );
        fclose($this->pipes[0]);

        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            $probe = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.2);
            if (is_resource($probe)) {
                fclose($probe);

                return $port;
            }
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                stream_set_blocking($this->pipes[2], false);
                self::fail('The HTTP listener exited early: ' . stream_get_contents($this->pipes[2]));
            }
            usleep(100000);
        }

        self::fail('The HTTP listener did not accept connections within 20s.');
    }

    private function httpEnvironment(array $overrides = []): array
    {
        return array_merge([
            'AGENT_KIT_MCP_HTTP_ENABLED' => 'true',
            'AGENT_KIT_MCP_BEARER_TOKEN' => self::TOKEN,
            'AGENT_KIT_MCP_HTTP_IDLE_TIMEOUT' => '1',
            'AGENT_KIT_MCP_HTTP_MAX_BODY_BYTES' => '1024',
        ], $overrides);
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, $error);
        $port = (int) explode(':', stream_socket_get_name($socket, false))[1];
        fclose($socket);

        return $port;
    }
}
```

- [ ] **Step 2: Run and verify RED**

Run: `vendor/bin/phpunit tests/Feature/Mcp/HttpListenerCommandTest.php`
Expected: FAIL (`Unsupported MCP transport: http`).

- [ ] **Step 3: Implement `ReactHttpListener`**

```php
<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport\Http;

use Peralta\AgentKit\Refactoring\Analysis\Index\CachedCodebaseIndexer;
use Peralta\AgentKit\Refactoring\Mcp\McpConfigurationException;
use Peralta\AgentKit\Refactoring\Mcp\McpProjectRoot;
use Peralta\AgentKit\Refactoring\Mcp\McpServerFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use RuntimeException;
use Throwable;

final class ReactHttpListener
{
    public function __construct(
        private readonly McpServerFactory $factory,
        private readonly CachedCodebaseIndexer $indexCache,
    ) {}

    public function listen(HttpServerOptions $options, McpProjectRoot $root, LoggerInterface $logger): int
    {
        if (!class_exists(HttpServer::class)) {
            throw new McpConfigurationException('The MCP HTTP transport requires react/http. Install it with: composer require react/http');
        }

        $sessions = new BoundedInMemorySessionStore($options->sessionTtl, $options->maxSessions);
        $server = $this->factory->create($root, $logger, $sessions);
        $transport = HttpTransportFactory::fromOptions($options);
        $loop = Loop::get();

        $http = new HttpServer(
            $loop,
            new StreamingRequestMiddleware(),
            new LimitConcurrentRequestsMiddleware($options->maxConcurrentRequests),
            new RequestBodyBufferMiddleware($options->maxBodyBytes),
            static fn (ServerRequestInterface $request): ResponseInterface => $transport->handle($server, $request, $logger),
        );

        try {
            $socket = new SocketServer($options->bindUri(), [], $loop);
        } catch (RuntimeException $exception) {
            throw new McpConfigurationException('Could not bind the MCP HTTP transport to ' . $options->bindUri() . ': ' . $exception->getMessage());
        }

        $this->closeIdleConnections($socket, $options->idleTimeout, $loop);
        $http->on('error', static fn (Throwable $error) => $logger->error('MCP HTTP server error.', ['exception' => $error]));
        $http->listen($socket);

        $stop = function (int $signal) use ($loop, $socket, $sessions, $logger): void {
            $logger->info('Stopping MCP HTTP server.', ['signal' => $signal]);
            $socket->close();
            $sessions->clear();
            $this->indexCache->clear();
            $loop->stop();
        };
        if (function_exists('pcntl_signal')) {
            $loop->addSignal(SIGINT, $stop);
            $loop->addSignal(SIGTERM, $stop);
        }

        $logger->info('MCP HTTP server listening.', [
            'endpoint' => 'http://' . $options->bindUri() . $options->path,
            'project_root' => $root->path,
            'allowed_hosts' => $options->allowedHosts,
        ]);
        $loop->run();

        return 0;
    }

    private function closeIdleConnections(SocketServer $socket, int $idleTimeout, LoopInterface $loop): void
    {
        $socket->on('connection', static function (ConnectionInterface $connection) use ($idleTimeout, $loop): void {
            $timer = null;
            $arm = static function () use (&$timer, $connection, $idleTimeout, $loop): void {
                if ($timer !== null) {
                    $loop->cancelTimer($timer);
                }
                $timer = $loop->addTimer($idleTimeout, static fn () => $connection->close());
            };
            $arm();
            $connection->on('data', $arm);
            $connection->on('close', static function () use (&$timer, $loop): void {
                if ($timer !== null) {
                    $loop->cancelTimer($timer);
                }
            });
        });
    }
}
```

- [ ] **Step 4: Add the HTTP branch and options to the command**

Replace the signature and `handle()` of `McpServeCommand` with:

```php
    protected $signature = 'agent-kit:mcp
        {--transport= : stdio or http; defaults to agent-kit.mcp.transport}
        {--path= : Project root to analyze; defaults to agent-kit.mcp.project_root or the Laravel base path}
        {--host= : HTTP bind host; defaults to agent-kit.mcp.http.host (127.0.0.1)}
        {--port= : HTTP bind port; defaults to agent-kit.mcp.http.port (8787)}
        {--allow-remote : Allow the HTTP transport to bind a non-loopback interface (a bearer token is still required)}';

    public function handle(StdioServerRunner $stdio, ReactHttpListener $http, McpLoggerFactory $loggers): int
    {
        $config = (array) config('agent-kit.mcp', []);
        if (!($config['enabled'] ?? true)) {
            return $this->refuse('The MCP server is disabled (AGENT_KIT_MCP_ENABLED=false).');
        }

        // stdout is the MCP wire on stdio; PHP notices and deprecations must not reach it.
        ini_set('display_errors', 'stderr');

        $transport = strtolower((string) ($this->option('transport') ?: ($config['transport'] ?? 'stdio')));

        try {
            $root = McpProjectRoot::fromPath((string) ($this->option('path') ?: ($config['project_root'] ?: base_path())));
            $logger = $loggers->create((array) ($config['logging'] ?? []));

            return match ($transport) {
                'stdio' => $this->serveStdio($stdio, $root, $logger),
                'http' => $http->listen($this->httpOptions((array) ($config['http'] ?? [])), $root, $logger),
                default => $this->refuse("Unsupported MCP transport: {$transport}. Use stdio or http."),
            };
        } catch (McpConfigurationException $exception) {
            return $this->refuse($exception->getMessage());
        }
    }

    private function httpOptions(array $config): HttpServerOptions
    {
        $port = $this->option('port');

        return HttpServerOptions::fromConfig(
            $config,
            host: $this->option('host') ?: null,
            port: is_numeric($port) ? (int) $port : null,
            allowRemote: (bool) $this->option('allow-remote'),
        );
    }
```

(add `use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\HttpServerOptions;` and `use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\ReactHttpListener;`). In `registerMcp()` add:

```php
        $this->app->bind(ReactHttpListener::class, fn ($app) => new ReactHttpListener(
            $app->make(McpServerFactory::class),
            $app->make(CachedCodebaseIndexer::class),
        ));
```

- [ ] **Step 5: Run and verify GREEN**

Run: `vendor/bin/phpunit tests/Feature/Mcp/HttpListenerCommandTest.php tests/Feature/Mcp/StdioServerCommandTest.php`
Expected: PASS. If `test_idle_connections_are_closed_after_the_configured_timeout` is flaky, raise `stream_set_timeout` to `idle_timeout + 5`; never remove the assertion.

- [ ] **Step 6: Run the whole suite**

Run: `vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Refactoring/Mcp src/AgentKitServiceProvider.php tests/Feature/Mcp/HttpListenerCommandTest.php
git commit -m "feat: serve the refactoring MCP server over Streamable HTTP

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

### Task 13: Coding-agent templates name the real MCP tools

**Files:**
- Modify: `resources/agents/refactoring/instructions.md`
- Modify: `resources/agents/refactoring/commands/{audit,analyze,callers,dependencies,impact,plan}.md`
- Modify: golden fixtures under `tests/Fixtures/Refactoring/Agents/Expected/{cursor,claude}/` (regenerated)
- Test: `tests/Unit/Refactoring/Agents/AgentCommandRepositoryTest.php` (new test method)

**Interfaces:**
- Consumes: `AgentCommandRepository`, `AgentTemplateRenderer`, `CursorAgentAdapter`, `ClaudeCodeAgentAdapter` (existing).
- Produces: every installed skill names its `refactoring_*` tool; existing assertions (`MCP tools`, `agent-kit:refactor-capabilities --json`, `{{cli_audit}} --json`, return sentences) keep holding.

- [ ] **Step 1: Add the failing template test**

Append to `AgentCommandRepositoryTest`:

```php
    public function test_every_command_names_its_read_only_mcp_tool_and_never_an_apply_tool(): void
    {
        $repository = new AgentCommandRepository(dirname(__DIR__, 4) . '/resources/agents/refactoring');
        $tools = [
            'audit' => 'refactoring_audit',
            'analyze' => 'refactoring_analyze',
            'callers' => 'refactoring_callers',
            'dependencies' => 'refactoring_dependencies',
            'impact' => 'refactoring_impact',
            'plan' => 'refactoring_impact',
        ];

        foreach ($tools as $name => $tool) {
            $content = $repository->command($name);
            self::assertStringContainsString("`{$tool}`", $content, $name);
            self::assertStringContainsString('refactoring_capabilities', $content, $name);
            self::assertStringNotContainsString('refactoring_apply', $content, $name);
            self::assertStringNotContainsString('does not exist yet', $content, $name);
        }
    }
```

- [ ] **Step 2: Run and verify RED**

Run: `vendor/bin/phpunit tests/Unit/Refactoring/Agents/AgentCommandRepositoryTest.php`
Expected: FAIL (tool names absent).

- [ ] **Step 3: Rewrite the shared instructions**

`resources/agents/refactoring/instructions.md`:

```markdown
## Execution priority

ANALYZE != MODIFY. Do not modify source files.

Before analyzing relationships manually:
1. When the Agent Kit MCP server is connected, discover its MCP tools (`refactoring_capabilities` lists them; the resource `agent-kit://refactoring/capabilities` describes their schemas) and call the read-only tool named below. It accepts the same target grammar as the CLI and operates on the project root the server was started with.
2. Otherwise run `php artisan agent-kit:refactor-capabilities --json`, then use the Agent Kit CLI command below with `--json`.
3. Use repository search and source/test reading only to complement unresolved context.
4. Use LLM inference only for interpretation, never to invent structural relationships.

Separate the response into FACTS, INTERPRETATION, and RECOMMENDATIONS. Mark dynamic targets that static analysis cannot resolve as UNKNOWN or UNRESOLVED DYNAMIC REFERENCE.
```

- [ ] **Step 4: Rewrite the six command bodies**

`commands/audit.md`:

```markdown
# Refactoring Audit

Call the `refactoring_audit` MCP tool when the Agent Kit MCP server is available; otherwise run `{{cli_audit}} --json`. Do not modify source files.

Use dependency and impact analysis for high-risk areas. Never recommend a pattern before identifying the concrete problem.

Return Executive Summary, Architecture Score (label it as deterministic or interpretive), Critical Issues, High Priority Issues, Code Smells, Coupling Risks, High Impact Classes, Testing Risks, and Recommended Roadmap.
```

`commands/analyze.md`:

```markdown
# Analyze Refactoring Target

Analyze the target supplied with this invocation. If it is missing, ask for a file, class, or module. Call the `refactoring_analyze` MCP tool with the target when the Agent Kit MCP server is available; otherwise run `{{cli_analyze}} "<target>" --json`. Then retrieve dependencies, callers, impact when appropriate, source context, and tests.

Return Target, Responsibilities, Metrics, Code Smells, Dependencies, Direct Callers, Transitive Impact, Side Effects, Tests, Refactoring Opportunities, and Risk.
```

`commands/callers.md`:

```markdown
# Find Callers

Find callers for the class or `Class::method` supplied with this invocation. Call the `refactoring_callers` MCP tool when the Agent Kit MCP server is available; otherwise run `{{cli_callers}} "<target>" --json`. Do not infer callers solely by reading source code.

Return DIRECT CALLERS, STRUCTURAL DEPENDENCIES, TRANSITIVE DEPENDENTS, and UNRESOLVED/DYNAMIC REFERENCES.
```

`commands/dependencies.md`:

```markdown
# Analyze Dependencies

Analyze the class supplied with this invocation. Call the `refactoring_dependencies` MCP tool when the Agent Kit MCP server is available; otherwise run `{{cli_dependencies}} "<target>" --json`.

Return UPSTREAM DEPENDENCIES, DOWNSTREAM DEPENDENTS, RELATIONSHIP TYPES, confidence, unresolved references, and dependency paths when available.
```

`commands/impact.md`:

```markdown
# Analyze Change Impact

Answer: “If I change this, what can potentially be affected?” Call the `refactoring_impact` MCP tool when the Agent Kit MCP server is available; otherwise run `{{cli_impact}} "<target>" --json`. Inspect relevant tests, jobs, events, and integrations after deterministic analysis.

Return CHANGE IMPACT, Target, Risk, Direct Callers, Structural Dependencies, Transitive Dependents, Affected Files, Affected Modules, Jobs/Events, External Integrations, Relevant Tests, Potential Breakage Scenarios, and Recommended Verification. Say “potentially affected” or “should be verified”; dependency does not prove breakage.
```

`commands/plan.md`:

```markdown
# Build a Refactoring Plan

Do not modify code. For the target supplied with this invocation, complete Analyze -> Callers -> Impact -> Tests -> Plan using the `refactoring_analyze`, `refactoring_callers` and `refactoring_impact` MCP tools first and the documented CLI fallbacks second.

Return REFACTORING PLAN, Goal, Current Problem, Evidence, Affected Components, Risk, Preparation, small isolated numbered steps, Validation after each step, Rollback Considerations, and Definition of Done. Never propose a broad rewrite slogan in place of steps.
```

- [ ] **Step 5: Regenerate the golden fixtures**

Run from the package root:

```bash
php -r '
require "vendor/autoload.php";
use Peralta\AgentKit\Refactoring\Agents\AgentCommandRepository;
use Peralta\AgentKit\Refactoring\Agents\AgentTemplateRenderer;
use Peralta\AgentKit\Refactoring\Agents\ClaudeCodeAgentAdapter;
use Peralta\AgentKit\Refactoring\Agents\CursorAgentAdapter;
$root = getcwd();
$repository = new AgentCommandRepository($root . "/resources/agents/refactoring");
foreach (["cursor" => new CursorAgentAdapter(), "claude" => new ClaudeCodeAgentAdapter()] as $id => $adapter) {
    foreach ($adapter->generate($repository, new AgentTemplateRenderer()) as $file) {
        $target = $root . "/tests/Fixtures/Refactoring/Agents/Expected/{$id}/{$file->path}";
        if (!is_dir(dirname($target))) { mkdir(dirname($target), 0777, true); }
        file_put_contents($target, $file->content);
        echo "updated {$target}\n";
    }
}
'
git diff --stat tests/Fixtures/Refactoring/Agents/Expected
```

Expected: only the 12 `SKILL.md` files change (the two rule files are untouched). Read one regenerated skill and confirm it names its tool and still contains `## Execution priority`.

- [ ] **Step 6: Run the agent and installer tests**

Run: `vendor/bin/phpunit tests/Unit/Refactoring/Agents tests/Feature/Refactoring/InstallAgentsCommandTest.php`
Expected: PASS (golden files match; installer idempotency unaffected).

- [ ] **Step 7: Commit**

```bash
git add resources/agents/refactoring tests/Fixtures/Refactoring/Agents/Expected tests/Unit/Refactoring/Agents/AgentCommandRepositoryTest.php
git commit -m "feat: point coding-agent skills at the MCP tools

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

### Task 14: Documentation

**Files:**
- Create: `MCP_SERVER.md`
- Modify: `README.md:262-334`, `REFACTORING_AGENT.md:71-101,280-284`, `SETUP.md` (Passo 2 env block, Troubleshooting), `CHANGELOG.md` (`[Não Lançado]`)
- Test: `tests/Feature/Mcp/DocumentationTest.php`

**Interfaces:**
- Consumes: the tool names from `RefactoringToolCatalog`, the command `agent-kit:mcp`, the env variables from Task 1.

- [ ] **Step 1: Write the failing documentation test**

```php
<?php

namespace Peralta\AgentKit\Tests\Feature\Mcp;

use Peralta\AgentKit\Refactoring\Mcp\RefactoringToolCatalog;
use PHPUnit\Framework\TestCase;

final class DocumentationTest extends TestCase
{
    public function test_docs_name_the_real_tools_command_and_variables_and_no_longer_call_mcp_future_work(): void
    {
        $root = dirname(__DIR__, 3);
        $mcp = (string) file_get_contents($root . '/MCP_SERVER.md');
        $readme = (string) file_get_contents($root . '/README.md');
        $refactoring = (string) file_get_contents($root . '/REFACTORING_AGENT.md');
        $setup = (string) file_get_contents($root . '/SETUP.md');
        $changelog = (string) file_get_contents($root . '/CHANGELOG.md');
        $env = (string) file_get_contents($root . '/.env.example');

        foreach ((new RefactoringToolCatalog())->names() as $tool) {
            self::assertStringContainsString($tool, $mcp, $tool);
            self::assertStringContainsString($tool, $refactoring, $tool);
        }
        foreach ([$mcp, $readme, $refactoring, $setup] as $document) {
            self::assertStringContainsString('agent-kit:mcp', $document);
        }
        foreach (['AGENT_KIT_MCP_ENABLED', 'AGENT_KIT_MCP_TRANSPORT', 'AGENT_KIT_MCP_PROJECT_ROOT', 'AGENT_KIT_MCP_HTTP_HOST', 'AGENT_KIT_MCP_HTTP_PORT', 'AGENT_KIT_MCP_HTTP_PATH', 'AGENT_KIT_MCP_ALLOW_REMOTE', 'AGENT_KIT_MCP_ALLOWED_ORIGINS', 'AGENT_KIT_MCP_BEARER_TOKEN'] as $variable) {
            self::assertStringContainsString($variable, $mcp, $variable);
            self::assertStringContainsString($variable, $env, $variable);
        }
        self::assertStringContainsString('agent-kit://refactoring/capabilities', $mcp);
        self::assertStringContainsString('@modelcontextprotocol/inspector', $mcp);
        self::assertStringContainsString('MCP_SERVER.md', $readme);
        self::assertStringContainsString('agent-kit:mcp', $changelog);

        foreach (['does not exist yet', 'is a future adapter', 'ainda não está disponível', 'integração futura', 'futuro servidor MCP', 'Future MCP'] as $stale) {
            self::assertStringNotContainsString($stale, $readme, $stale);
            self::assertStringNotContainsString($stale, $refactoring, $stale);
        }
        self::assertDoesNotMatchRegularExpression('/AGENT_KIT_MCP_BEARER_TOKEN=\S+/', $env, 'No token value may be committed.');
        self::assertDoesNotMatchRegularExpression('/Bearer [0-9a-f]{32,}/', $mcp, 'Docs must use placeholders, never real-looking tokens.');
    }
}
```

- [ ] **Step 2: Run and verify RED**

Run: `vendor/bin/phpunit tests/Feature/Mcp/DocumentationTest.php`
Expected: FAIL (`MCP_SERVER.md` missing).

- [ ] **Step 3: Write `MCP_SERVER.md`**

```markdown
# Agent Kit MCP Server (Refactoring Agent)

Agent Kit ships an MCP server that exposes the deterministic refactoring
capabilities to MCP clients such as Claude Code, Cursor, Codex, the MCP
Inspector and any client that speaks stdio or Streamable HTTP. The server is
built on the official `mcp/sdk` PHP package and is **read-only**: it audits,
analyzes and explains; it never edits files, runs shell commands or reads
outside the project root it was started with.

```text
MCP client → transport (stdio | Streamable HTTP) → mcp/sdk Server
          → Refactoring\Mcp adapter → RefactoringCapabilities → AST index
```

The CLI (`php artisan agent-kit:refactor-*`) and the MCP server are independent
adapters over the same `RefactoringCapabilities` contract; the JSON envelopes
are identical.

## Prerequisites

- PHP 8.2+ with `ext-fileinfo`; Laravel 10, 11 or 12 with Agent Kit installed.
- `mcp/sdk` is installed with the package (pinned to `^0.8.1`, see
  [Upgrading the SDK](#upgrading-the-sdk)).
- Streamable HTTP additionally needs `react/http`:
  `composer require react/http`.

## Configuration

`config/agent-kit.php` → `mcp` (publish with `php artisan vendor:publish --tag=agent-kit-config`):

| Variable | Default | Meaning |
|----------|---------|---------|
| `AGENT_KIT_MCP_ENABLED` | `true` | `false` makes `agent-kit:mcp` refuse to start |
| `AGENT_KIT_MCP_TRANSPORT` | `stdio` | default transport when `--transport` is omitted |
| `AGENT_KIT_MCP_PROJECT_ROOT` | *(empty = base path)* | project root when `--path` is omitted |
| `AGENT_KIT_MCP_HTTP_ENABLED` | `false` | opt-in for Streamable HTTP |
| `AGENT_KIT_MCP_HTTP_HOST` | `127.0.0.1` | bind host (`--host` overrides) |
| `AGENT_KIT_MCP_HTTP_PORT` | `8787` | bind port (`--port` overrides) |
| `AGENT_KIT_MCP_HTTP_PATH` | `/mcp` | the single MCP endpoint |
| `AGENT_KIT_MCP_ALLOW_REMOTE` | `false` | allow non-loopback binds (`--allow-remote` overrides) |
| `AGENT_KIT_MCP_ALLOWED_ORIGINS` | *(empty)* | extra allowed hosts/origins, comma-separated |
| `AGENT_KIT_MCP_BEARER_TOKEN` | *(empty)* | required for HTTP, 32+ characters |
| `AGENT_KIT_MCP_HTTP_MAX_BODY_BYTES` … `AGENT_KIT_MCP_HTTP_MAX_SESSIONS` | see [Limits](#limits-and-lifecycle) | request and session bounds |
| `AGENT_KIT_MCP_INDEX_CACHE_MAX_ENTRIES` | `1` | cached roots per process |
| `AGENT_KIT_MCP_LOG_LEVEL` / `AGENT_KIT_MCP_LOG_CHANNEL` | `info` / *(stderr)* | logging |

Command options: `--transport=stdio|http`, `--path=`, `--host=`, `--port=`,
`--allow-remote`.

## Tools

| Tool | Capability | Arguments | Returns |
|------|------------|-----------|---------|
| `refactoring_capabilities` | `describeCapabilities()` | none | capability descriptors incl. `mcp_tool` and `cli_fallback` |
| `refactoring_audit` | `audit(root)` | none | metrics, smells and issue counts for the whole root |
| `refactoring_analyze` | `analyze(root, target)` | `target` | metrics, dependencies, callers, transitive impact, risk |
| `refactoring_callers` | `findCallers(root, target)` | `target` | direct callers, structural and transitive dependents |
| `refactoring_dependencies` | `dependencies(root, target)` | `target` | upstream, downstream and transitive dependencies |
| `refactoring_impact` | `impact(root, target)` | `target` | dependent counts, risk, affected files, records |

`target` is a project-relative or absolute in-project PHP file (analyze only),
a fully qualified class, or `Class::method` where the capability supports
method scope. Every tool is annotated `readOnlyHint: true`,
`destructiveHint: false`. There is no `refactoring_apply`; `ANALYZE != MODIFY`.

### Result envelope

Successful calls return `structuredContent` identical to the CLI `--json`
output (the same JSON is repeated as text content):

```json
{
  "schema_version": "1.0",
  "capability": "impact",
  "incomplete": false,
  "data": {},
  "diagnostics": [],
  "unresolved": []
}
```

Domain failures are tool results with `isError: true` and the stable error
envelope in `structuredContent`:

```json
{"schema_version": "1.0", "error": {"code": "TARGET_NOT_FOUND", "message": "Class not found: App\\Missing"}}
```

Error codes: `INVALID_TARGET`, `TARGET_NOT_FOUND`, `AMBIGUOUS_TARGET`,
`UNSUPPORTED_TARGET`, `TARGET_OUTSIDE_PROJECT`, `PROJECT_ROOT_NOT_FOUND`.
Arguments that violate the input schema (missing, empty, wrong type, unknown
keys) are JSON-RPC `-32602` errors; unknown tool names are `-32602` too. Each
tool declares an `outputSchema` accepting either envelope.

## Resource

`agent-kit://refactoring/capabilities` (`application/json`) returns the server
identity, the fixed project root, every tool with its input/output schema,
targets and CLI fallback, the result format, known limitations and
`"mutation": {"supported": false}`. It is derived from the same catalog that
`tools/list` uses.

## Project root

The root is fixed when the server starts: `--path` > `AGENT_KIT_MCP_PROJECT_ROOT`
> the Laravel base path. It is canonicalized with `realpath` and must be a
directory. Tools never take a root argument; file targets that resolve outside
the root (including through symlinks) are rejected with
`TARGET_OUTSIDE_PROJECT`. Serve several projects with several server instances.

## stdio

```bash
php artisan agent-kit:mcp --path=/absolute/path/to/project
php artisan agent-kit:mcp --transport=stdio --path=/absolute/path/to/project
```

stdout carries only JSON-RPC lines; logs, warnings and diagnostics go to stderr
(`display_errors` is forced to `stderr`). `SIGINT`/`SIGTERM` stop the loop
cleanly (exit `0`); start-up problems exit `1` with a message on stderr. The
single stdio session never expires.

## Streamable HTTP

HTTP is opt-in and secure by default:

```bash
export AGENT_KIT_MCP_HTTP_ENABLED=true
export AGENT_KIT_MCP_BEARER_TOKEN="$(php -r 'echo bin2hex(random_bytes(32));')"
php artisan agent-kit:mcp --transport=http --path=/absolute/path/to/project --host=127.0.0.1 --port=8787
# endpoint: http://127.0.0.1:8787/mcp
```

- One endpoint (`AGENT_KIT_MCP_HTTP_PATH`, default `/mcp`) serving `POST`,
  `DELETE` and `OPTIONS`; `GET` answers `405` (this server never initiates
  messages, so there is no standalone SSE stream).
- Binds `127.0.0.1` by default. Any other host requires `--allow-remote` (or
  `AGENT_KIT_MCP_ALLOW_REMOTE=true`); the bearer token stays mandatory.
- A persistent single-threaded process built on ReactPHP: the AST index is
  reused across calls and invalidated by content fingerprint. Tool execution
  blocks the loop, so keep `AGENT_KIT_MCP_HTTP_MAX_CONCURRENT` small.
- No TLS: expose it remotely only behind a reverse proxy that terminates TLS.

### Authentication

Bearer token only, read from `AGENT_KIT_MCP_BEARER_TOKEN` (32+ characters).
Requests must send `Authorization: Bearer <token>`; missing or invalid tokens
get `401` with `WWW-Authenticate: Bearer`, malformed headers get `400`. The
query string is never read and the token is never logged or echoed. Rotate by
changing the variable and restarting the listener (sessions are in memory).
The validator implements the SDK `AuthorizationTokenValidatorInterface`, so a
JWT/OAuth resource-server validator can replace it in a future release.

### Allowed origins and DNS rebinding

The SDK `DnsRebindingProtectionMiddleware` enforces a host allowlist:
`localhost`, `127.0.0.1`, `[::1]`, plus `AGENT_KIT_MCP_ALLOWED_ORIGINS`
(comma-separated hosts or origins, reduced to their host) and the bind host when
`--allow-remote` is used. A request with an `Origin` whose host is not listed is
`403`; without `Origin`, the `Host` header must be listed. Remote clients must
therefore address the server through an allowlisted hostname.

### Limits and lifecycle

| Variable | Default | Effect |
|----------|---------|--------|
| `AGENT_KIT_MCP_HTTP_MAX_BODY_BYTES` | `1048576` | `413` above this size (SDK and ReactPHP caps agree) |
| `AGENT_KIT_MCP_HTTP_MAX_CONCURRENT` | `4` | queued requests beyond this wait |
| `AGENT_KIT_MCP_HTTP_IDLE_TIMEOUT` | `60` | idle connections are closed |
| `AGENT_KIT_MCP_HTTP_SESSION_TTL` | `3600` | sessions expire after idle seconds |
| `AGENT_KIT_MCP_HTTP_MAX_SESSIONS` | `100` | oldest session evicted beyond this |

`SIGINT`/`SIGTERM` close the socket, destroy sessions, clear the index cache and
exit `0`. Execution timeouts cannot interrupt synchronous PHP analysis; keep
projects and concurrency bounded instead.

### Status codes

| Request | Status |
|---------|--------|
| `OPTIONS` | `204` |
| Disallowed `Origin`/`Host` | `403` |
| Missing/invalid bearer token | `401` |
| Malformed `Authorization` header | `400` |
| `GET` | `405` (`Allow: POST, DELETE, OPTIONS`) |
| Body over the limit | `413` |
| Invalid JSON | JSON-RPC `-32700` in the body |
| Unsupported `MCP-Protocol-Version` | `400` |
| Missing or malformed `Mcp-Session-Id` | `400` |
| Unknown or expired session | `404` |
| `DELETE` with a session | `200`; without | `400` |
| Path other than the endpoint | `404` |
| Internal failure | `500` with a fixed JSON body, details only on stderr |

## Client configuration

Use absolute paths; clients do not run from your project directory.

### Claude Code (stdio)

`.mcp.json` in the project, or `claude mcp add`:

```json
{
  "mcpServers": {
    "agent-kit-refactoring": {
      "command": "php",
      "args": ["/absolute/path/to/laravel-app/artisan", "agent-kit:mcp", "--path=/absolute/path/to/project"]
    }
  }
}
```

### Cursor (stdio)

`.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "agent-kit-refactoring": {
      "command": "php",
      "args": ["/absolute/path/to/laravel-app/artisan", "agent-kit:mcp", "--path=/absolute/path/to/project"]
    }
  }
}
```

### Codex (stdio)

`~/.codex/config.toml` (format per the OpenAI Codex CLI documentation):

```toml
[mcp_servers.agent-kit-refactoring]
command = "php"
args = ["/absolute/path/to/laravel-app/artisan", "agent-kit:mcp", "--path=/absolute/path/to/project"]
```

### MCP Inspector

```bash
npx @modelcontextprotocol/inspector php /absolute/path/to/laravel-app/artisan agent-kit:mcp --path=/absolute/path/to/project
# Streamable HTTP (listener already running):
npx @modelcontextprotocol/inspector http://127.0.0.1:8787/mcp
```

For HTTP, add the header `Authorization: Bearer <token>` in the Inspector UI.
The Inspector is a development tool, not a dependency of this package.

### Generic Streamable HTTP client

```bash
curl -s http://127.0.0.1:8787/mcp \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"curl","version":"1.0"}}}' -i
```

Reuse the `Mcp-Session-Id` response header on every following request, send
`MCP-Protocol-Version: 2025-11-25`, and `DELETE` the endpoint with the session
header when done.

The installed Cursor/Claude Code skills (`php artisan agent-kit:agents:install`)
already prefer these tools and fall back to the `--json` CLI.

## Index cache

The server keeps the AST index in memory per project root. Before every call it
computes a content fingerprint (`xxh128` of every included PHP file) and rebuilds
the index when any file was created, modified or removed. Nothing is written to
disk; the cache is cleared on shutdown. `AGENT_KIT_MCP_INDEX_CACHE_MAX_ENTRIES`
bounds the number of roots kept (default `1`).

## Logging and diagnostics

Logs go to stderr at `AGENT_KIT_MCP_LOG_LEVEL` (default `info`). Set
`AGENT_KIT_MCP_LOG_CHANNEL` to route them to a channel from `config/logging.php`
instead; never pick a channel that writes to stdout when using stdio. Set the
level to `debug` to see tool arguments in the log; production should keep
`info`.

Troubleshooting:

- *Client hangs on connect (stdio)*: run the command manually with
  `< /dev/null` and read stderr; a start-up error exits `1`.
- *`401` on HTTP*: the header must be exactly `Authorization: Bearer <token>`;
  tokens in the URL are ignored.
- *`403` on HTTP*: add the client's origin host to
  `AGENT_KIT_MCP_ALLOWED_ORIGINS`.
- *`Refusing to bind ... --allow-remote`*: non-loopback binds are opt-in.
- *`requires react/http`*: `composer require react/http`.

## Security model

Threats considered: path traversal and symlink escape (fixed root, existing
containment checks), DNS rebinding and cross-origin browser calls (host
allowlist, no CORS origin by default), accidental public exposure (loopback bind,
explicit opt-in, mandatory token), credential timing attacks (`hash_equals`),
secret leakage (token only from environment, never logged), oversized payloads
(body and batch caps), abandoned sessions (TTL, GC, bound), exception leakage
(generic errors on the wire, traces on stderr). The server performs no outbound
HTTP and no shell execution.

## Limitations

- Static analysis only; dynamic PHP stays `unresolved`.
- One project root per process.
- No standalone `GET` SSE stream, no server-initiated messages, no prompts.
- HTTP listener is single-threaded and has no TLS.
- Execution timeouts cannot preempt a running analysis.

## Upgrading the SDK

`mcp/sdk` is pre-1.0 and its minor releases contain breaking changes; the
package pins `^0.8.1` (`>=0.8.1 <0.9.0`). Before moving to a new minor, re-check
the constructor signatures used here: `Mcp\Server\Builder::add()`,
`Mcp\Schema\Tool`, `Mcp\Schema\Result\CallToolResult`,
`Mcp\Server\Transport\StdioTransport`, `Mcp\Server\Transport\StreamableHttpTransport`,
`Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware`,
`Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface`, and run
`tests/Feature/Mcp`.
```

- [ ] **Step 4: Update `README.md`**

Replace the paragraph starting `As skills tentam obter fatos na ordem` through the architecture diagram (lines 313–333) with:

```markdown
As skills tentam obter fatos na ordem: tools MCP do Agent Kit (`refactoring_audit`,
`refactoring_analyze`, `refactoring_callers`, `refactoring_dependencies`,
`refactoring_impact`), CLI `agent-kit:refactor-* --json`, leitura/pesquisa no
repositório e, por último, interpretação do LLM. Elas separam `FACTS`,
`INTERPRETATION` e `RECOMMENDATIONS`, sinalizam comportamento dinâmico não
resolvido e mantêm `ANALYZE != MODIFY`.

O Refactoring Core é compartilhado pela CLI, pelos coding agents e pelo servidor
MCP. No `/refactor-plan`, a saída é somente um plano. No `/refactor-audit` e nos
demais comandos, a saída é somente análise. Nenhum comando aplica mudanças
automaticamente e nenhum `/refactor-apply` é gerado.

```text
               Refactoring Core
                     |
      +--------------+--------------+
      v              v              v
     CLI        Coding Agents       MCP
```

## Servidor MCP

O pacote inclui um servidor MCP (`mcp/sdk` oficial) que expõe as seis tools
somente-leitura acima a Claude Code, Cursor, Codex, MCP Inspector e clientes
Streamable HTTP:

```bash
# stdio (padrão) — use em .mcp.json / .cursor/mcp.json / ~/.codex/config.toml
php artisan agent-kit:mcp --path=/caminho/absoluto/do/projeto

# Streamable HTTP — opt-in, bind em 127.0.0.1, bearer token obrigatório
AGENT_KIT_MCP_HTTP_ENABLED=true AGENT_KIT_MCP_BEARER_TOKEN=... \
php artisan agent-kit:mcp --transport=http --path=/caminho/absoluto/do/projeto --port=8787
```

Configuração em `config/agent-kit.php` (`mcp`) e variáveis `AGENT_KIT_MCP_*`
no `.env.example`. Veja [MCP_SERVER.md](MCP_SERVER.md) para transporte,
autenticação, origins permitidas, cache do índice, exemplos por cliente,
diagnóstico e limitações.
```

- [ ] **Step 5: Update `REFACTORING_AGENT.md`**

Replace lines 71–101 (from `The generated instructions gather evidence` through the diagram) with:

```markdown
The generated instructions gather evidence in this order:

1. The Agent Kit MCP tools when the server is connected: `refactoring_capabilities`,
   `refactoring_audit`, `refactoring_analyze`, `refactoring_callers`,
   `refactoring_dependencies`, `refactoring_impact`.
2. The corresponding `php artisan agent-kit:refactor-* --json` command.
3. Repository search plus source and test reading for missing context.
4. LLM inference for interpretation only, never for invented relationships.

Start the MCP server with `php artisan agent-kit:mcp --path=/project` (stdio)
or `--transport=http` for Streamable HTTP; see [MCP_SERVER.md](MCP_SERVER.md).
The direct CLI remains the deterministic fallback, for example:

```bash
php artisan agent-kit:refactor-impact "App\Services\PaymentService::charge" --json --path=/project
```

Responses separate `FACTS`, `INTERPRETATION`, and `RECOMMENDATIONS`. Dynamic
behavior that static analysis cannot resolve remains explicitly unknown or
unresolved. `ANALYZE != MODIFY`: the six skills audit, explain, or plan only.
No `/refactor-apply` command is generated and no `refactoring_apply` tool exists.

The architecture stays deliberately small: one Refactoring Core provides the
capabilities shared by the direct CLI, the Cursor/Claude Code adapters, and the
MCP server. Agent-specific adapters render native skill and rule files from the
same canonical command repository instead of duplicating analysis logic.

```text
               Refactoring Core
                     |
      +--------------+--------------+
      v              v              v
     CLI        Coding Agents       MCP
```
```

Replace the `## Roadmap` paragraph with:

```markdown
## Roadmap

Next iterations can add method-level cyclomatic complexity, duplicate detection,
architecture constraints, baseline comparison, an OAuth resource-server mode for
the MCP HTTP transport, and serving the MCP endpoint from the host application's
own web server.
```

Append to the `## Performance and diagnostics` section of `REFACTORING_AGENT.md`:

```markdown
The MCP server keeps the index in memory between calls and rebuilds it only when
a content fingerprint of the included PHP files changes; separate CLI invocations
still build their own index.
```

- [ ] **Step 6: Update `SETUP.md` and `CHANGELOG.md`**

In `SETUP.md` Passo 2 env block add after the Analytics lines:

```dotenv
# Servidor MCP (opcional - Claude Code, Cursor, Codex, Inspector)
AGENT_KIT_MCP_ENABLED=true
AGENT_KIT_MCP_HTTP_ENABLED=false
# AGENT_KIT_MCP_BEARER_TOKEN=   # obrigatório só para --transport=http (32+ chars)
```

In `SETUP.md` Troubleshooting add:

```markdown
### Servidor MCP não conecta?

- stdio: rode `php artisan agent-kit:mcp --path=/projeto < /dev/null` e leia o stderr; erros de inicialização retornam código 1.
- HTTP: confira `AGENT_KIT_MCP_HTTP_ENABLED=true`, `AGENT_KIT_MCP_BEARER_TOKEN` com 32+ caracteres e o header `Authorization: Bearer <token>`; `403` indica origin/host fora de `AGENT_KIT_MCP_ALLOWED_ORIGINS`.
- Detalhes em [MCP_SERVER.md](MCP_SERVER.md).
```

In `CHANGELOG.md` under `## [Não Lançado]` → `### Adicionado` add:

```markdown
- Servidor MCP para o Refactoring Agent (`php artisan agent-kit:mcp`): seis tools somente-leitura (`refactoring_capabilities`, `refactoring_audit`, `refactoring_analyze`, `refactoring_callers`, `refactoring_dependencies`, `refactoring_impact`), resource `agent-kit://refactoring/capabilities`, transporte stdio e Streamable HTTP (opt-in, bind em loopback, bearer token, allowlist de origins, limites de corpo/concorrência/sessões), cache do índice AST por fingerprint de conteúdo e configuração `agent-kit.mcp`.
- Dependência `mcp/sdk ^0.8.1`; `react/http` sugerido para o transporte HTTP.
```

and under `### Alterado`:

```markdown
- `describeCapabilities()` passa a informar `mcp_tool` em cada descritor; `agent-kit:refactor-capabilities` exibe a coluna MCP tool.
- Skills de Cursor/Claude Code passam a nomear as tools MCP reais antes do fallback de CLI.
```

- [ ] **Step 7: Run the documentation test and the suite**

Run: `vendor/bin/phpunit tests/Feature/Mcp/DocumentationTest.php && vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add MCP_SERVER.md README.md REFACTORING_AGENT.md SETUP.md CHANGELOG.md tests/Feature/Mcp/DocumentationTest.php
git commit -m "docs: document the refactoring MCP server

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

### Task 15: Final verification, Inspector smoke test and pull request

**Files:** none new.

- [ ] **Step 1: Run the MCP-specific and full suites**

```bash
vendor/bin/phpunit tests/Unit/Refactoring/Mcp tests/Unit/Refactoring/CachedCodebaseIndexerTest.php tests/Feature/Mcp
vendor/bin/phpunit
```

Expected: PASS, no skipped tests other than signal tests on hosts without `pcntl`.

- [ ] **Step 2: Static checks**

```bash
composer validate --strict
git diff --name-only origin/main -- '*.php' | xargs -n1 php -l
composer install --dry-run
git status --porcelain   # must be empty
git log --oneline origin/main..HEAD
```

Expected: `composer.json is valid`; every file `No syntax errors detected`; dry-run reports nothing to install; clean tree; only this feature's commits.

- [ ] **Step 3: Secrets and stdout purity audit**

```bash
git grep -nE 'AGENT_KIT_MCP_BEARER_TOKEN=[0-9A-Za-z_-]{16,}' -- . ; echo "exit $?"   # expect no match (exit 1); docs only use placeholders or $(php -r ...)
git grep -nE 'Bearer [0-9a-f]{40,}' -- . ':!tests' ; echo "exit $?"           # expect no match
php vendor/bin/testbench agent-kit:mcp --path=tests/Fixtures/Refactoring/Ast < /dev/null 1>/tmp/mcp-stdout.txt 2>/tmp/mcp-stderr.txt; echo "exit $?"
wc -c /tmp/mcp-stdout.txt   # 0 bytes: nothing but JSON-RPC replies ever reach stdout, and EOF produced none
```

- [ ] **Step 4: MCP Inspector smoke test (manual, if `npx` is available)**

```bash
npx @modelcontextprotocol/inspector --cli php vendor/bin/testbench agent-kit:mcp --path=$(pwd)/tests/Fixtures/Refactoring/Ast --method tools/list
npx @modelcontextprotocol/inspector --cli php vendor/bin/testbench agent-kit:mcp --path=$(pwd)/tests/Fixtures/Refactoring/Ast --method tools/call --tool-name refactoring_impact --tool-arg 'target=Fixtures\Payments\PaymentService::charge'
```

Expected: six tools listed; the impact call returns `structuredContent.capability = "impact"`. Record the output summary in the PR description. Skip (and say so in the PR) when `npx` is unavailable.

- [ ] **Step 5: Diff review against `origin/main`**

Run `git diff origin/main --stat` and read the full diff once, checking: contract divergence (envelopes unchanged, `mcp_tool` additive only), security controls present (no query-string token, `hash_equals`, loopback default, `--allow-remote` gate, 403/401 order), no SDK API used outside the verified signatures, no duplicated analysis logic, tests exercising real transports, docs matching names, no CLI regression.

- [ ] **Step 6: Push and open the pull request**

```bash
git push -u origin codex/refactoring-mcp-server
gh pr create --base main --title "feat: add MCP server for refactoring capabilities" --body-file /tmp/pr-body.md
```

`/tmp/pr-body.md` must contain these sections with real content from the run: Architecture; Tools exposed; Resource exposed; Transports; Authentication; HTTP protections; Cache strategy; Configuration; Documented clients; Tests executed (commands and pass counts); Known limitations; Possible next steps; and end with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.

## Self-review

- **Spec coverage:** D1–D10 → Tasks 1, 12, 6, 4, 3, 10, 11, 2, 4, 11; §6 tools → Tasks 4, 6, 8; §7 resource → Tasks 7, 8; §8 root → Tasks 5, 8; §9 stdio → Task 9; §10 HTTP → Tasks 10–12; §11 auth → Task 10; §12 cache → Task 2; §13 config → Task 1; §14 templates/docs → Tasks 13–14; §15 tests → every task plus Task 15; §16 compatibility → Tasks 1–3, 15; §17 security → Tasks 5, 6, 10, 11, 12, 15.
- **Placeholders:** none; every step carries code or an exact command. Two SDK-defined behaviours (`Accept` handling, parse-error HTTP status) are asserted by content-type and JSON-RPC code rather than by HTTP status, as the spec allows.
- **Type consistency:** `CodebaseIndexBuilder::build(string): CodebaseIndex`; `CachedCodebaseIndexer::clear()/count()`; `ToolDefinition->{name,capability,title,description,inputSchema,outputSchema}`; `RefactoringToolCatalog::{tools,names,tool,resourceDefinition}`; `RefactoringToolHandler::{execute,call}` + `JSON_FLAGS`; `CapabilitiesResourceHandler::{read,readDocument,describe}`; `McpServerFactory::create(root, logger, sessions, gcProbability)` + `version()`; `McpLoggerFactory::create(array)`; `StdioRunnerControl::{getState,stop}`; `StdioServerRunner::run(root, logger, input, output)`; `HttpServerOptions::{fromConfig,bindUri,parseAllowedOrigins}` + public readonly fields; `BearerTokenAuthenticationMiddleware(validator, responses, streams)`; `BoundedInMemorySessionStore(ttl, maxSessions, clock)` + `count()/clear()`; `HttpTransportFactory::{fromOptions,middleware,create,handle}`; `ReactHttpListener::listen(options, root, logger)`; command options `--transport --path --host --port --allow-remote` — used identically across tasks.
