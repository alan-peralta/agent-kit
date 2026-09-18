# Guzzle 8 and Application-Served MCP HTTP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Accept Guzzle 8 (`^7.0|^8.0`), and serve the MCP Streamable HTTP transport through an application route instead of ReactPHP, with a file-backed AST index snapshot for per-request processes.

**Architecture:** The error classifier and `QdrantStore` stop relying on Guzzle-7-only APIs. The ReactPHP listener goes away; `agent-kit:mcp` serves stdio only. A route (`routes/mcp.php`, loaded only when HTTP is enabled) hands the Laravel request, converted to PSR-7, to the existing `HttpTransportFactory` pipeline with a `Psr16SessionStore` over the Laravel cache, and converts the PSR-7 response back. `CachedCodebaseIndexer` gains an optional `IndexSnapshotStore` on disk.

**Tech Stack:** PHP ^8.2, Laravel 10–13, Guzzle 7/8, mcp/sdk ^0.8.1, PHPUnit 11 (`vendor/bin/phpunit --no-coverage`), Orchestra Testbench.

**Spec:** `docs/superpowers/specs/2026-09-18-guzzle-8-and-app-served-mcp-http-design.md`

## Global Constraints

- Work only in `/Users/alanperalta/www/agent-kit/.worktrees/guzzle-8-support` (branch `feat/guzzle-8-and-app-served-mcp-http`, stacked on `feat/laravel-13-support`); run every command from there.
- PHP `^8.2` and Laravel 10–13 support stay unchanged. Never reference a class that exists only in Guzzle 8 or only in Guzzle 7 in `src/`.
- The lock stays on Laravel 12 + Guzzle 7. Never hand-edit `composer.lock`.
- Messages, verbatim:
  - misconfigured route: HTTP `503`, body `{"error":"misconfigured","message":"The MCP HTTP transport is not configured correctly; see the application log."}`
  - non-loopback client: HTTP `403`, body `{"error":"forbidden","message":"The MCP HTTP transport only accepts loopback clients. Set AGENT_KIT_MCP_ALLOW_REMOTE=true to accept others."}`
  - `agent-kit:mcp --transport=http`: `The Streamable HTTP transport is served by your application at <path> when AGENT_KIT_MCP_HTTP_ENABLED=true; start it with php artisan serve or your web server. See MCP_SERVER.md.` where `<path>` is the configured path, normalised (`/mcp` by default).
- Session store prefix: `agent-kit-mcp-session-`. Route name: `agent-kit.mcp`. Default `time_limit`: `120`. Default snapshot directory: `storage_path('framework/cache/agent-kit/index')`.
- Code, comments, `MCP_SERVER.md`, `REFACTORING_AGENT.md` and commit messages in English; `README.md`, `SETUP.md`, `CHANGELOG.md` and `.env.example` comments in Brazilian Portuguese with full diacritics.
- Match the surrounding style: 4-space indentation, `final` classes, constructor property promotion, comments only for non-obvious reasons.
- PHPUnit rewrites the tracked `.phpunit.cache/test-results`: run `git checkout -- .phpunit.cache/test-results` before every commit and never commit it.
- Every commit message ends with a blank line and the implementer's own attribution trailer, `Co-Authored-By: <your model name> <noreply@anthropic.com>` (the repository records which model wrote each commit).
- Baseline: `vendor/bin/phpunit --no-coverage` is OK with 2 skipped `database`-group tests and 10 pre-existing PHPUnit deprecations.

---

### Task 1: Portable error classification

**Files:**
- Modify: `src/ErrorRecovery/Classifiers/DefaultErrorClassifier.php`
- Modify: `tests/Unit/ErrorRecovery/DefaultErrorClassifierTest.php`, `tests/Unit/ErrorRecovery/RetryMiddlewareTest.php` (line ~97), `tests/Feature/ErrorRecovery/FallbackMiddlewareTest.php` (line ~137)

**Interfaces:** Produces `DefaultErrorClassifier::classify()` with unchanged categories, working on Guzzle 7 and 8.

- [ ] **Step 1: Tests first.** In the three test files, replace every `new RequestException($message, $request, $response)` / `new \GuzzleHttp\Exception\RequestException(...)` that passes a response with `\GuzzleHttp\Exception\RequestException::create($request, $response)` (it exists on Guzzle 7 and 8 and returns `ClientException`/`ServerException`). Add these tests to `DefaultErrorClassifierTest` (wrap each throwable as the previous of a `ProviderException` the same way the existing tests do):
  - a request failure without a response, `new RequestException('reset', $request)`, is `NETWORK_TIMEOUT`;
  - an object implementing `Psr\Http\Client\NetworkExceptionInterface` that is not a Guzzle class (anonymous class extending `RuntimeException`, `getRequest()` returning a `GuzzleHttp\Psr7\Request`) is `NETWORK_TIMEOUT`;
  - a `RuntimeException` subclass whose `getResponse()` returns a `Response(429)` is `RATE_LIMIT` (duck typing, no Guzzle class involved);
  - a `RuntimeException` subclass whose `getResponse()` throws is `SERVER_ERROR`.
  Run `vendor/bin/phpunit --no-coverage tests/Unit/ErrorRecovery tests/Feature/ErrorRecovery`: the duck-typing and non-Guzzle network tests fail today.

- [ ] **Step 2: Implement.** Replace the body of `DefaultErrorClassifier` with:

```php
<?php

namespace Peralta\AgentKit\ErrorRecovery\Classifiers;

use GuzzleHttp\Exception\RequestException;
use Peralta\AgentKit\ErrorRecovery\Contracts\ErrorClassifier;
use Peralta\AgentKit\ErrorRecovery\Enums\ErrorType;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class DefaultErrorClassifier implements ErrorClassifier
{
    public function classify(Throwable $error): ErrorType
    {
        $previous = $error->getPrevious();

        // Guzzle 7's ConnectException and Guzzle 8's NetworkException family: no response arrived.
        if ($previous instanceof NetworkExceptionInterface) {
            return ErrorType::NETWORK_TIMEOUT;
        }

        $response = $previous === null ? null : $this->responseOf($previous);
        if ($response !== null) {
            return $this->classifyStatusCode($response->getStatusCode());
        }

        if ($previous instanceof RequestException) {
            // Sem response = timeout de conexão ou erro de rede
            return ErrorType::NETWORK_TIMEOUT;
        }

        return ErrorType::SERVER_ERROR;
    }

    /**
     * Guzzle 7 exposes the response on RequestException (null when there is none) and Guzzle 8
     * only on ResponseException, so ask the exception itself instead of naming either class.
     */
    private function responseOf(Throwable $exception): ?ResponseInterface
    {
        if (!method_exists($exception, 'getResponse')) {
            return null;
        }

        try {
            $response = $exception->getResponse();
        } catch (Throwable) {
            return null;
        }

        return $response instanceof ResponseInterface ? $response : null;
    }

    private function classifyStatusCode(int $status): ErrorType
    {
        return match (true) {
            $status === 429 => ErrorType::RATE_LIMIT,
            $status === 400 => ErrorType::INVALID_REQUEST,
            $status === 401, $status === 403 => ErrorType::AUTH_ERROR,
            $status >= 500 => ErrorType::SERVER_ERROR,
            default => ErrorType::SERVER_ERROR,
        };
    }
}
```

- [ ] **Step 3: Verify.** `vendor/bin/phpunit --no-coverage tests/Unit/ErrorRecovery tests/Feature/ErrorRecovery tests/Unit/Providers` → OK; then the full suite → OK.
- [ ] **Step 4: Commit** `fix: classify provider errors the same way on Guzzle 7 and 8`.

---

### Task 2: `QdrantStore` without empty-array headers

**Files:** Modify `src/Knowledge/Stores/QdrantStore.php` (`request()`); tests in `tests/Unit/Knowledge/QdrantStoreTest.php` only if an assertion depends on how the request was built (their header/body assertions must keep passing unchanged).

**Interfaces:** Produces the same public behaviour; requests go through `ClientInterface::send()`.

- [ ] **Step 1: Test first.** Add a test that injects a client whose default headers include `Authorization: Bearer inherited`, `api-key: inherited` and `X-Trace: inherited` (a `GuzzleHttp\Client` with a `MockHandler` + `Middleware::history`), runs one store call with no API key, and asserts the recorded request has none of the three headers and has `Accept: application/json` and `Content-Type: application/json`. It passes today on Guzzle 7 (the `[]` trick); it is the regression guard for the rewrite. Name it after what it proves (default client headers never reach Qdrant).
- [ ] **Step 2: Implement.** In `request()`, build the headers without the `[]` entries (`Accept`, `Content-Type`, and `api-key` only when a key is set), encode the body with `json_encode($json, JSON_THROW_ON_ERROR)` when `$json !== []` (else `null`), and send `new \GuzzleHttp\Psr7\Request($method, $uri, $headers, $body)` through `$this->client->send($request, ['headers' => null, 'http_errors' => false, 'timeout' => $this->timeout])`. Move the encoding inside the existing `try` and catch `GuzzleException|\JsonException` with the same `KnowledgeStoreException('Qdrant transport request failed: ' . $this->sanitize(...))` wrapping. Replace the old comment with: `// 'headers' => null drops every default header of an injected client (Guzzle 7 and 8), so its Authorization or api-key never reaches Qdrant; the request carries its own.` Keep the retry loop unchanged.
- [ ] **Step 3: Verify.** `vendor/bin/phpunit --no-coverage tests/Unit/Knowledge/QdrantStoreTest.php tests/Feature/QdrantStoreBindingTest.php` → OK; full suite → OK.
- [ ] **Step 4: Commit** `fix: drop inherited Qdrant headers without Guzzle-7-only empty arrays`.

---

### Task 3: AST index snapshot on disk

**Files:**
- Create: `src/Refactoring/Analysis/Index/IndexSnapshotStore.php`, `tests/Unit/Refactoring/IndexSnapshotStoreTest.php`
- Modify: `src/Refactoring/Analysis/Index/CachedCodebaseIndexer.php`, `tests/Unit/Refactoring/CachedCodebaseIndexerTest.php`, `src/AgentKitServiceProvider.php` (`CachedCodebaseIndexer` singleton), `config/agent-kit.php` (`mcp.index_cache.path`), `tests/TestCase.php` (disable snapshots by default in tests), `tests/Feature/Mcp/McpConfigurationTest.php` (default)

**Interfaces:**
- Produces `final class IndexSnapshotStore { __construct(string $directory); read(string $root, string $fingerprint): ?CodebaseIndex; write(string $root, string $fingerprint, CodebaseIndex $index): void }`.
- `CachedCodebaseIndexer::__construct(CodebaseIndexBuilder $inner, ProjectFingerprint $fingerprint, int $maxEntries = 1, ?IndexSnapshotStore $snapshots = null)`.

- [ ] **Step 1: Tests first.** `IndexSnapshotStoreTest` (plain PHPUnit, temp directory per test): a written snapshot is read back equal (`assertEquals`) for the same root and fingerprint; a different fingerprint reads `null`; a different root reads `null`; a corrupt file (overwrite its payload with garbage after the header) reads `null` without a warning; a missing directory is created on write; a write into an unwritable directory (chmod 0555, skipped as root) does not throw. `CachedCodebaseIndexerTest`: with a store, a second indexer instance (fresh memory) returns the snapshot and does not call the inner builder; a changed file (new fingerprint) rebuilds and overwrites the snapshot; without a store the behaviour is unchanged. Use a counting fake `CodebaseIndexBuilder` like the existing tests and the real `ProjectFingerprint` over a temp project.
- [ ] **Step 2: Implement `IndexSnapshotStore`:**

```php
<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Index;

use Peralta\AgentKit\Refactoring\Mcp\McpServerFactory;

/**
 * One file per project root holding the latest index, so a per-request process (PHP-FPM,
 * php artisan serve, each CLI command) does not rebuild it on every call. The file lives in
 * the application's own storage and is unserialized at the same trust level as Laravel's file cache.
 */
final class IndexSnapshotStore
{
    // Bump when the shape of CodebaseIndex or anything it holds changes.
    private const FORMAT = 1;

    public function __construct(private readonly string $directory) {}

    public function read(string $root, string $fingerprint): ?CodebaseIndex
    {
        $contents = @file_get_contents($this->file($root));
        if ($contents === false) {
            return null;
        }

        $header = $this->header($fingerprint);
        if (!str_starts_with($contents, $header)) {
            return null;
        }

        $index = @unserialize(substr($contents, strlen($header)));

        return $index instanceof CodebaseIndex ? $index : null;
    }

    public function write(string $root, string $fingerprint, CodebaseIndex $index): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return;
        }

        $temporary = @tempnam($this->directory, 'index-');
        if ($temporary === false) {
            return;
        }

        // Rename is atomic within a directory: a concurrent reader sees the old or the new snapshot, never half of one.
        if (@file_put_contents($temporary, $this->header($fingerprint) . serialize($index)) === false
            || !@rename($temporary, $this->file($root))) {
            @unlink($temporary);
        }
    }

    private function header(string $fingerprint): string
    {
        return 'agent-kit-index:' . self::FORMAT . ':' . McpServerFactory::version() . ':' . $fingerprint . "\n";
    }

    private function file(string $root): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . sha1($root) . '.idx';
    }
}
```

   In `CachedCodebaseIndexer::build()`, on a memory miss: `$index = $this->snapshots?->read($key, $fingerprint);` and only when that is `null` call `$this->inner->build($key)` and `$this->snapshots?->write($key, $fingerprint, $index);`. Everything else (LRU, `clear()`, `count()`) stays.

- [ ] **Step 3: Wire it.** `config/agent-kit.php` → `mcp.index_cache` gains:

```php
            // Snapshot em disco do índice AST, reaproveitado entre processos (CLI, rota HTTP).
            // Vazio desliga; null = storage/framework/cache/agent-kit/index
            'path' => env('AGENT_KIT_MCP_INDEX_CACHE_PATH'),
```

   In `AgentKitServiceProvider`, pass a fourth argument to the `CachedCodebaseIndexer` singleton: `null` when `config('agent-kit.mcp.index_cache.path') === ''`, otherwise `new IndexSnapshotStore($path ?? storage_path('framework/cache/agent-kit/index'))` (a published config without the key reads `null`, which means the default directory). In `tests/TestCase.php` `getEnvironmentSetUp()`, set `agent-kit.mcp.index_cache.path` to `''` so tests never share snapshots (a fake parser in one test must not feed another); tests that need snapshots pass a temp directory explicitly. `McpConfigurationTest` asserts the package default (`null`) by reading `require config/agent-kit.php` values or by checking the key exists with `null` before the TestCase override (pick one and say which in the report).
- [ ] **Step 4: Verify.** Focused tests, then the full suite → OK.
- [ ] **Step 5: Commit** `feat: keep an on-disk snapshot of the AST index between processes`.

---

### Task 4: Remove the ReactPHP listener

**Files:**
- Delete: `src/Refactoring/Mcp/Transport/Http/ReactHttpListener.php`, `src/Refactoring/Mcp/Transport/Http/BoundedInMemorySessionStore.php`, `tests/Unit/Refactoring/Mcp/Http/BoundedInMemorySessionStoreTest.php`, `tests/Feature/Mcp/HttpListenerCommandTest.php`
- Modify: `src/Refactoring/Mcp/Commands/McpServeCommand.php`, `src/AgentKitServiceProvider.php` (drop the `ReactHttpListener` import and binding), `tests/Feature/Mcp/HttpTransportPipelineTest.php` (use `Mcp\Server\Session\InMemorySessionStore` instead of `BoundedInMemorySessionStore`; `$this->sessions->count()` → assert the session is gone through the SDK API or by a follow-up `404`), `tests/Feature/Mcp/McpConfigurationTest.php` (drop the `react/http` assertion), `tests/Feature/Mcp/StdioServerCommandTest.php` if it covers `--transport=http`, `composer.json` (remove `react/http` from `require-dev` and `suggest`), `composer.lock` (via Composer)

**Interfaces:** Produces `agent-kit:mcp` with signature `agent-kit:mcp {--transport=} {--path=}` serving stdio only.

- [ ] **Step 1: Test first.** A test (in `StdioServerCommandTest` or a new `McpServeCommandTest`, in-process with `Artisan::call`) asserts `agent-kit:mcp --transport=http` exits `1` and its output contains the verbatim message from the Global Constraints with `/mcp`, and the same with `agent-kit.mcp.transport` set to `http` and no option. It fails today (the listener starts or refuses for another reason).
- [ ] **Step 2: Implement.** `McpServeCommand`: remove `--host`, `--port`, `--allow-remote`, the `ReactHttpListener` parameter and `httpOptions()`; `'http' => $this->refuse(<message>)` using `'/' . trim((string) ($config['http']['path'] ?? '/mcp'), '/')` for `<path>`; keep the SDK check, the enabled check, stdio unchanged. Delete the listed files. `composer remove --dev react/http --no-interaction` (it also rewrites `require-dev`); then delete the `react/http` line from `suggest` by hand and run `composer update --lock --no-interaction`. Verify with a section/version diff of the lock (as in PR #12's plan) that only `react/*`, `evenement/evenement` and packages required solely by them left, and no remaining version changed.
- [ ] **Step 3: Verify.** `vendor/bin/phpunit --no-coverage tests/Feature/Mcp tests/Unit/Refactoring/Mcp` → OK; full suite → OK; `grep -rn "React\\\\\|react/http\|ReactHttpListener\|BoundedInMemorySessionStore" src tests composer.json` → nothing.
- [ ] **Step 4: Commit** `refactor!: drop the ReactPHP HTTP listener from agent-kit:mcp`.

---

### Task 5: `HttpTransportOptions` and configuration

**Files:**
- Create: `src/Refactoring/Mcp/Transport/Http/HttpTransportOptions.php`, `tests/Unit/Refactoring/Mcp/Http/HttpTransportOptionsTest.php`
- Delete: `src/Refactoring/Mcp/Transport/Http/HttpServerOptions.php`, `tests/Unit/Refactoring/Mcp/Http/HttpServerOptionsTest.php`
- Modify: `src/Refactoring/Mcp/Transport/Http/HttpTransportFactory.php`, `tests/Feature/Mcp/HttpTransportPipelineTest.php`, `config/agent-kit.php` (`mcp.http`), `.env.example` (MCP block), `tests/Feature/Mcp/McpConfigurationTest.php`, `tests/Feature/Mcp/DocumentationTest.php` (drop `AGENT_KIT_MCP_HTTP_HOST` and `AGENT_KIT_MCP_HTTP_PORT` from its variable list)

**Interfaces:** Produces:

```php
final readonly class HttpTransportOptions
{
    public const MIN_TOKEN_LENGTH = 32;
    public string $path; public bool $allowRemote; /** @var list<string> */ public array $allowedHosts;
    /** @var list<string> */ public array $allowedOrigins; public string $bearerToken; public int $maxBodyBytes;
    public int $sessionTtl; public ?string $cacheStore; public int $timeLimit;
    public static function fromConfig(array $config, string $appUrl = ''): self; // throws McpConfigurationException
    public static function normalizePath(string $path): string;                // '/' . trim($path, '/')
    public static function parseAllowedOrigins(string $origins): array;
    public static function parseOrigins(string $origins): array;
}
```

   `HttpTransportFactory::fromOptions(HttpTransportOptions $options)` and its constructor take `HttpTransportOptions`; `handle()` loses the path check (routing matches the path now).

- [ ] **Step 1: Tests first.** Port `HttpServerOptionsTest` to `HttpTransportOptionsTest`: keep the token, origin/host parsing, IPv6 and positive-integer cases; drop host/port/bind/idle/concurrency/max-sessions cases; add: `app.url`'s host is allowed (`https://myapp.test` → `myapp.test` in `allowedHosts`, and not in `allowedOrigins`); `cache_store` `''` and `null` both mean `null`, `'file'` stays `'file'`; `time_limit` defaults to `120`, `0` is allowed, a negative value throws `McpConfigurationException` naming `AGENT_KIT_MCP_HTTP_TIME_LIMIT`; `fromConfig` no longer checks `enabled`. In `HttpTransportPipelineTest`, switch to `HttpTransportOptions` and delete `test_unrouted_paths_are_404`.
- [ ] **Step 2: Implement.** Move `parseAllowedOrigins()`, `parseOrigins()`, `hostOf()` and `positive()` unchanged from `HttpServerOptions`. `fromConfig`: token check (same message as today); `allowedHosts` = `localhost`, `127.0.0.1`, `[::1]`, `parseAllowedOrigins($appUrl)`, `parseAllowedOrigins($config['allowed_origins'])`, unique; `allowedOrigins` = `parseOrigins($config['allowed_origins'])`; `maxBodyBytes`/`sessionTtl` via `positive()`; `cacheStore` trimmed or `null`; `timeLimit` = `(int) ($config['time_limit'] ?? 120)`, rejecting negatives with `AGENT_KIT_MCP_HTTP_TIME_LIMIT must be zero or a positive integer, got {$value}.`; `path` via `normalizePath()`. Config `mcp.http` becomes:

```php
        'http' => [
            'enabled' => env('AGENT_KIT_MCP_HTTP_ENABLED', false),
            // Rota servida pela própria aplicação (php artisan serve, PHP-FPM, Octane)
            'path' => env('AGENT_KIT_MCP_HTTP_PATH', '/mcp'),
            // Aceitar clientes fora de loopback exige opt-in explícito; o token continua obrigatório
            'allow_remote' => (bool) env('AGENT_KIT_MCP_ALLOW_REMOTE', false),
            // Hosts/origins adicionais permitidos (separados por vírgula); loopback e o host de APP_URL já são permitidos
            'allowed_origins' => env('AGENT_KIT_MCP_ALLOWED_ORIGINS', ''),
            // Mínimo de 32 caracteres. Gere com: php -r 'echo bin2hex(random_bytes(32));'
            'bearer_token' => env('AGENT_KIT_MCP_BEARER_TOKEN'),
            'max_body_bytes' => (int) env('AGENT_KIT_MCP_HTTP_MAX_BODY_BYTES', 1048576),
            'session_ttl' => (int) env('AGENT_KIT_MCP_HTTP_SESSION_TTL', 3600),
            // Store do cache do Laravel para as sessões MCP; vazio = o store padrão da aplicação
            'cache_store' => env('AGENT_KIT_MCP_HTTP_CACHE_STORE'),
            // Limite de tempo de cada chamada, em segundos (0 = não mexe no limite do PHP)
            'time_limit' => (int) env('AGENT_KIT_MCP_HTTP_TIME_LIMIT', 120),
        ],
```

   `.env.example`: drop `AGENT_KIT_MCP_HTTP_HOST`, `_PORT`, `_IDLE_TIMEOUT`, `_MAX_CONCURRENT`, `_MAX_SESSIONS`; add `AGENT_KIT_MCP_HTTP_CACHE_STORE=`, `AGENT_KIT_MCP_HTTP_TIME_LIMIT=120` and `AGENT_KIT_MCP_INDEX_CACHE_PATH=` with one-line pt-BR comments; the `AGENT_KIT_MCP_TRANSPORT` comment says stdio is the only transport the command serves.
- [ ] **Step 3: Verify.** `vendor/bin/phpunit --no-coverage tests/Unit/Refactoring/Mcp tests/Feature/Mcp` → OK; full suite → OK; `grep -rn "HttpServerOptions\|idle_timeout\|max_concurrent_requests\|max_sessions\|'host'\|'port'" src config tests` → nothing MCP-related.
- [ ] **Step 4: Commit** `refactor!: replace the listener options with HttpTransportOptions`.

---

### Task 6: The MCP route

**Files:**
- Create: `routes/mcp.php`, `src/Refactoring/Mcp/Transport/Http/LaravelPsrBridge.php`, `src/Refactoring/Mcp/Transport/Http/McpHttpController.php`, `tests/Feature/Mcp/HttpRouteTest.php`, `tests/Unit/Refactoring/Mcp/Http/LaravelPsrBridgeTest.php`
- Modify: `src/AgentKitServiceProvider.php` (`boot()`)

**Interfaces:**
- Consumes `HttpTransportOptions`, `HttpTransportFactory::fromOptions()->handle(Server, ServerRequestInterface, LoggerInterface): ResponseInterface`, `McpServerFactory::create(McpProjectRoot, LoggerInterface, SessionStoreInterface, int $gcProbability = 1): Server`, `McpLoggerFactory`, `McpProjectRoot::fromPath()`.
- Produces `LaravelPsrBridge::toPsrRequest(Illuminate\Http\Request): ServerRequestInterface` and `LaravelPsrBridge::toLaravelResponse(ResponseInterface): Symfony\Component\HttpFoundation\Response`; route `agent-kit.mcp`.

- [ ] **Step 1: Tests first.** `LaravelPsrBridgeTest`: method, full URI with query, headers, protocol version (`1.1`, not `HTTP/1.1`), server params and the raw body survive `toPsrRequest`; `toLaravelResponse` keeps status, every header value and the body; a body with a known size becomes a plain `Response`; a body with an unknown size (a `GuzzleHttp\Psr7\PumpStream` or `FnStream` returning `null` from `getSize()`) becomes a `StreamedResponse` whose `sendContent()` output (captured with `ob_start`) is the whole body. `HttpRouteTest` (Testbench, `defineEnvironment()` enabling HTTP with a 64-character token and the AST fixture root as `agent-kit.mcp.project_root`, cache `array`), each case asserting status and, for JSON errors, the exact body:
  - the route does not exist when `agent-kit.mcp.http.enabled` is false, and when `agent-kit.mcp.enabled` is false (separate test class or `defineEnvironment` variants; assert `404` and `Route::has('agent-kit.mcp')` is false);
  - `OPTIONS` → `204` without a token; no token → `401` with `WWW-Authenticate: Bearer`; wrong token → `401`; `Host: evil.test` → `403`; `GET` → `405`;
  - `REMOTE_ADDR=10.0.0.5` (`$this->withServerVariables([...])`) → `403` with the verbatim forbidden body; the same with `allow_remote` true → reaches MCP (`initialize` → `200`);
  - a 20-character token → `503` with the verbatim misconfigured body, and the body does not contain the token or the word `AGENT_KIT_MCP_BEARER_TOKEN`;
  - `max_body_bytes` 64 and a larger body → `413`;
  - full flow: `initialize` (`200`, `Mcp-Session-Id` present, `protocolVersion` `2025-11-25`), `notifications/initialized` (`202`), `tools/list` (`200`, lists `refactoring_audit`), `tools/call` `refactoring_audit` (`200`, `structuredContent.capability` is `audit`), `DELETE` (`200`) then `tools/list` with the same session → `404`;
  - sessions survive a new application instance: with cache store `file` pointed at a temp directory (`cache.stores.file.path`), open a session, call `$this->refreshApplication()` (re-applying the same environment), then `tools/list` with the session → `200`.
  Reuse the JSON-RPC helpers of `HttpTransportPipelineTest` (copy the small helpers; do not make one test extend the other).
- [ ] **Step 2: Implement `routes/mcp.php`:**

```php
<?php

use Illuminate\Support\Facades\Route;
use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\HttpTransportOptions;
use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\McpHttpController;

Route::match(
    ['GET', 'POST', 'DELETE', 'OPTIONS'],
    HttpTransportOptions::normalizePath((string) config('agent-kit.mcp.http.path', '/mcp')),
    McpHttpController::class,
)->name('agent-kit.mcp');
```

   In `AgentKitServiceProvider::boot()`, outside the `runningInConsole()` block:

```php
        // The MCP HTTP transport is a route of the host application, registered only when enabled.
        if (config('agent-kit.mcp.enabled', true) && config('agent-kit.mcp.http.enabled', false)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/mcp.php');
        }
```

   `LaravelPsrBridge`:

```php
<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport\Http;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LaravelPsrBridge
{
    private const CHUNK_BYTES = 8192;

    public static function toPsrRequest(Request $request): ServerRequestInterface
    {
        return (new ServerRequest(
            $request->getMethod(),
            $request->getUri(),
            $request->headers->all(),
            Utils::streamFor($request->getContent(true)),
            str_replace('HTTP/', '', (string) $request->getProtocolVersion()) ?: '1.1',
            $request->server->all(),
        ))->withQueryParams($request->query->all());
    }

    public static function toLaravelResponse(ResponseInterface $response): Response
    {
        $body = $response->getBody();
        if ($body->getSize() !== null) {
            return new Response((string) $body, $response->getStatusCode(), $response->getHeaders());
        }

        // An unknown size means a stream the SDK is still writing (SSE); pass it through as it comes.
        return new StreamedResponse(static function () use ($body): void {
            while (!$body->eof()) {
                $chunk = $body->read(self::CHUNK_BYTES);
                if ($chunk === '') {
                    break;
                }
                echo $chunk;
                flush();
            }
        }, $response->getStatusCode(), $response->getHeaders());
    }
}
```

   `McpHttpController`:

```php
<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport\Http;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Mcp\Server\Session\Psr16SessionStore;
use Peralta\AgentKit\Refactoring\Mcp\McpConfigurationException;
use Peralta\AgentKit\Refactoring\Mcp\McpProjectRoot;
use Peralta\AgentKit\Refactoring\Mcp\McpServerFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

final class McpHttpController
{
    public function __construct(
        private readonly McpServerFactory $servers,
        private readonly CacheFactory $cache,
        private readonly LogManager $log,
    ) {}

    public function __invoke(Request $request): Response
    {
        $config = (array) config('agent-kit.mcp', []);
        $logger = $this->logger((array) ($config['logging'] ?? []));

        try {
            $options = HttpTransportOptions::fromConfig((array) ($config['http'] ?? []), (string) config('app.url', ''));
            $root = McpProjectRoot::fromPath((string) (($config['project_root'] ?? null) ?: base_path()));
        } catch (McpConfigurationException $exception) {
            $logger->error('The MCP HTTP transport is misconfigured.', ['reason' => $exception->getMessage()]);

            return new JsonResponse(['error' => 'misconfigured', 'message' => 'The MCP HTTP transport is not configured correctly; see the application log.'], 503);
        }

        if (!$options->allowRemote && !self::isLoopback((string) $request->ip())) {
            return new JsonResponse(['error' => 'forbidden', 'message' => 'The MCP HTTP transport only accepts loopback clients. Set AGENT_KIT_MCP_ALLOW_REMOTE=true to accept others.'], 403);
        }

        if ($options->timeLimit > 0) {
            set_time_limit($options->timeLimit);
        }

        $sessions = new Psr16SessionStore($this->cache->store($options->cacheStore), 'agent-kit-mcp-session-', $options->sessionTtl);
        $server = $this->servers->create($root, $logger, $sessions);

        return LaravelPsrBridge::toLaravelResponse(
            HttpTransportFactory::fromOptions($options)->handle($server, LaravelPsrBridge::toPsrRequest($request), $logger),
        );
    }

    /** stdout is not the wire here, so without a configured channel the application's default log is the right place. */
    private function logger(array $config): LoggerInterface
    {
        $channel = $config['channel'] ?? null;

        return is_string($channel) && $channel !== '' ? $this->log->channel($channel) : $this->log->channel();
    }

    private static function isLoopback(string $ip): bool
    {
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $ip = substr($ip, 7);
        }

        return $ip === '::1' || (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($ip, '127.'));
    }
}
```

   If the SDK's `Psr16SessionStore` requires a `Psr\SimpleCache\CacheInterface` and `CacheFactory::store()` returns `Illuminate\Contracts\Cache\Repository`, confirm the concrete `Illuminate\Cache\Repository` implements PSR-16 (it does on Laravel 10–13) and add a `@var` note; do not add an adapter.
- [ ] **Step 3: Verify.** `vendor/bin/phpunit --no-coverage tests/Feature/Mcp tests/Unit/Refactoring/Mcp` → OK; full suite → OK.
- [ ] **Step 4: Commit** `feat!: serve the MCP Streamable HTTP transport as an application route`.

---

### Task 7: End-to-end test over a real HTTP server

**Files:** Create `tests/Feature/Mcp/HttpRouteServerTest.php` (and, only if needed, `tests/Fixtures/Mcp/router.php`). Modify `tests/Feature/Mcp/Concerns/SpawnsMcpServer.php` only to add a generic spawn helper if the existing one is stdio-specific.

- [ ] **Step 1:** Find a way to serve the Testbench application with this package on PHP's built-in server: first try `php vendor/bin/testbench serve --host=127.0.0.1 --port=<free port>` with the environment variables `AGENT_KIT_MCP_HTTP_ENABLED=true`, `AGENT_KIT_MCP_BEARER_TOKEN=<64 chars>`, `AGENT_KIT_MCP_PROJECT_ROOT=<AST fixture>`, `CACHE_STORE=file` (Laravel 11+; `CACHE_DRIVER=file` on 10) and `APP_URL=http://127.0.0.1:<port>`, and check `POST /mcp` answers `401` without a token. If the served application does not load the package, write `tests/Fixtures/Mcp/router.php` that boots `Orchestra\Testbench\Foundation\Application::create(basePath: <testbench skeleton>, options: ['extra' => ['providers' => [AgentKitServiceProvider::class]]])` and handles the request through the HTTP kernel, and serve it with `php -S 127.0.0.1:<port> tests/Fixtures/Mcp/router.php`. Record which approach works in the report. Always pre-place the skeleton `.env` and remove the skeleton vendor symlink exactly as `SpawnsMcpServer::spawn()` does, and stop the server in `tearDown()` (also on failure).
- [ ] **Step 2: Test.** With Guzzle (a plain `GuzzleHttp\Client`, `http_errors` false): wait until the port accepts connections (poll up to 10 s), then `initialize` → `notifications/initialized` → `tools/call` `refactoring_audit` → assert `200` and `structuredContent.capability === 'audit'`; then `DELETE` the session. Each request is a separate script run of the built-in server, so this proves sessions persist through the cache store.
- [ ] **Step 3: Verify.** Run the test three times in a row, then the full suite → OK, and `git status` shows no leftover skeleton `.env` or files outside the repository's tracked set.
- [ ] **Step 4: Commit** `test: drive the MCP route end to end on PHP's built-in server`.

---

### Task 8: Guzzle 8 in Composer and CI

**Files:** `composer.json`, `composer.lock` (Composer only), `.github/workflows/tests.yml`, `tests/Unit/ComposerManifestTest.php`

- [ ] **Step 1: Test first.** `ComposerManifestTest`: `require['guzzlehttp/guzzle']` is `^7.0|^8.0`; `react/http` is in none of `require`, `require-dev`, `suggest`.
- [ ] **Step 2: Implement.** `"guzzlehttp/guzzle": "^7.0|^8.0"`; `composer update --lock --no-interaction`; verify no version changed in the lock. In the workflow, rename the Laravel 13 matrix entry to `Laravel 13, Guzzle 8` and make its command `update --with laravel/framework:^13.0 --with orchestra/testbench:^11.0 --with guzzlehttp/guzzle:^8.0`; update its comment; add a step after "Show the resolved Laravel version" that prints `composer show guzzlehttp/guzzle | grep '^versions'`. Run actionlint (`docker run --rm -v "$PWD":/repo --workdir /repo rhysd/actionlint:latest -color=false`).
- [ ] **Step 3: Verify on Guzzle 8 locally.** Copy the worktree (`git archive HEAD` into a scratch directory under `$CLAUDE_JOB_DIR/tmp`, never inside the repository), run the CI command there, confirm Laravel 13 + Guzzle 8.x resolved, run the full suite there → OK. Record the resolved versions and the result in the report.
- [ ] **Step 4: Commit** `feat: accept Guzzle 8`.

---

### Task 9: Documentation and changelog

**Files:** `MCP_SERVER.md`, `README.md`, `SETUP.md`, `CHANGELOG.md`, `.env.example` (only if Task 5 missed something), `tests/Feature/Mcp/DocumentationTest.php`

- [ ] **Step 1: `DocumentationTest`.** Add `AGENT_KIT_MCP_HTTP_CACHE_STORE`, `AGENT_KIT_MCP_HTTP_TIME_LIMIT` and `AGENT_KIT_MCP_INDEX_CACHE_PATH` to the variables that must appear in `MCP_SERVER.md` and `.env.example`, and assert `MCP_SERVER.md` no longer mentions `ReactPHP`, `react/http`, `--allow-remote`, `--port` or `AGENT_KIT_MCP_HTTP_PORT`. It fails until the docs change.
- [ ] **Step 2: `MCP_SERVER.md`.** Rewrite for the new transport, keeping every heading that other sections link to (`Prerequisites`, `Upgrading the SDK`, `Streamable HTTP`, `Authentication`, `Allowed origins and DNS rebinding`, `Status codes`):
  - architecture line: the HTTP adapter is a route of the host application;
  - Prerequisites: drop `react/http`;
  - Streamable HTTP: enabling (`AGENT_KIT_MCP_HTTP_ENABLED=true`, token), the endpoint is `APP_URL` + `AGENT_KIT_MCP_HTTP_PATH`, local use with `php artisan serve` (single worker by default: one call at a time), PHP-FPM/Octane behaviour, loopback-only by default and `AGENT_KIT_MCP_ALLOW_REMOTE` (client IP, `TrustProxies` behind a proxy), the production warning, `php artisan route:cache` needs re-caching after enabling or changing the path, sessions in the Laravel cache (`AGENT_KIT_MCP_HTTP_CACHE_STORE`, `array` loses them between requests), `AGENT_KIT_MCP_HTTP_TIME_LIMIT`, the index snapshot (`AGENT_KIT_MCP_INDEX_CACHE_PATH`, empty disables it);
  - Authentication: "restarting the listener" becomes "the next request reads the new token"; sessions live in the cache;
  - Limits and lifecycle: body limit (`413` for declared and chunked bodies alike, the SDK reads the body with a bound), concurrency/timeouts/TLS are the web server's; remove the ReactPHP rows and the SIGTERM paragraph;
  - Status codes: add `403` non-loopback client and `503` misconfigured; remove the chunked-body ReactPHP row;
  - Client configuration: the HTTP examples use `http://127.0.0.1:8000/mcp` (the `php artisan serve` default);
  - Troubleshooting: remove the bind/host entries and `requires react/http`; add `403 … loopback clients`, `503 misconfigured` (see the application log) and `419`/CSRF (the route must not be inside the `web` group; it is not by default);
  - `agent-kit:mcp` is stdio only; `--transport=http` explains where HTTP went.
- [ ] **Step 3: README, SETUP, CHANGELOG.** README: remove the `-W`/Guzzle 7 note added by PR #13 and the same from SETUP; the "Servidor MCP" section shows the route (enable, `php artisan serve`, URL) instead of `agent-kit:mcp --transport=http`; "Atualizando" gains a "Vindo da v0.3.x" paragraph for HTTP users (enable the route, serve the app, point the client at the app URL, drop the removed variables `AGENT_KIT_MCP_HTTP_HOST`, `_PORT`, `_IDLE_TIMEOUT`, `_MAX_CONCURRENT`, `_MAX_SESSIONS`, and `react/http` is no longer needed). CHANGELOG `[Não Lançado]`: Adicionado — Guzzle 8 (`^7.0|^8.0`; a fresh Laravel 13 app installs without `-W`), the index snapshot; Alterado — **BREAKING** the HTTP transport is an application route (what changes for users, in one bullet); the Laravel 13 bullet from PR #13 loses its `-W` sentence; Removido — `react/http`, the `--host/--port/--allow-remote` options and the five variables; Corrigido — error classification on Guzzle 8 and `QdrantStore` on Guzzle 8.
- [ ] **Step 4: Verify.** `vendor/bin/phpunit --no-coverage tests/Feature/Mcp/DocumentationTest.php tests/Feature/Refactoring/InstallAgentsCommandTest.php` → OK; full suite → OK; `grep -rn "ReactPHP\|react/http\|--allow-remote\|AGENT_KIT_MCP_HTTP_PORT\|-W\b" README.md SETUP.md MCP_SERVER.md` → only intentional mentions (the CHANGELOG and README upgrade notes).
- [ ] **Step 5: Commit** `docs: document the application-served MCP HTTP transport and Guzzle 8`.

---

## Final verification (controller)

- [ ] Full suite on the lock (Laravel 12, Guzzle 7) and in a scratch copy on Laravel 13 + Guzzle 8.
- [ ] Consumer smoke test: a fresh `laravel/laravel` (13.x, Guzzle 8) app requiring the branch through a `path` repository installs **without** `-W`; `.env` gets `AGENT_KIT_MCP_HTTP_ENABLED=true` and a token; `composer require --dev mcp/sdk nikic/php-parser`; `php artisan serve --port=<free>`; an MCP `initialize` + `tools/call refactoring_audit` over HTTP succeed; a second `tools/call` reuses the index snapshot (faster); `php artisan agent-kit:mcp --transport=http` prints the new message.
- [ ] Whole-branch review, PR against `feat/laravel-13-support` (it retargets to `main` when #13 merges), CI green including "Suite (Laravel 13, Guzzle 8)".
