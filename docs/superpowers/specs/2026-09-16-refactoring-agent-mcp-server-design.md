# Refactoring Agent MCP Server — Design

**Date:** 2026-09-16

**Status:** Proposed — awaiting approval before implementation planning

**Scope:** Expose the existing `RefactoringCapabilities` contract to MCP clients (Claude Code, Cursor, Codex, MCP Inspector, generic clients) through the official MCP PHP SDK, over stdio and Streamable HTTP, without adding any mutation capability.

**Depends on:** PR #2 (`69ecf15`, merged into `main` on 2026-09-16), which introduced `RefactoringCapabilities`, `DefaultRefactoringCapabilities`, `CapabilityResult`, `CapabilityException`, the Artisan adapters, and the Cursor/Claude Code skill installer.

## 1. Context

The refactoring core is already a stable application boundary:

```text
RefactoringCapabilities
  describeCapabilities()                 -> capability_discovery
  audit(projectRoot)                     -> audit
  analyze(projectRoot, target)           -> analyze
  findCallers(projectRoot, target)       -> find_callers
  dependencies(projectRoot, target)      -> dependencies
  impact(projectRoot, target)            -> impact
```

Every operation returns a `CapabilityResult` (`schema_version`, `capability`, `incomplete`, `data`, `diagnostics`, `unresolved`) or throws a `CapabilityException` (`schema_version`, `error.code`, `error.message`). The Artisan commands are thin adapters over that contract. The installed Cursor/Claude Code skills already instruct agents to prefer an MCP capability and fall back to the JSON CLI, but the documentation states that no MCP server exists yet.

This design adds the MCP adapter promised by the previous two designs. It reuses the official SDK for everything protocol-related and keeps the adapter thin.

## 2. Goals

- Expose exactly six read-only MCP tools and one read-only MCP resource over the existing contract.
- Provide a stdio transport suitable for Claude Code, Cursor, Codex and MCP Inspector.
- Provide a Streamable HTTP transport that is opt-in, local-by-default, authenticated, Origin-validated and bounded.
- Reuse the codebase index across calls while the server process lives, with correctness guaranteed by content fingerprints.
- Keep the analysis core usable without MCP, and keep the CLI and MCP adapters independent.
- Use the SDK's own JSON-RPC, session, protocol negotiation and Streamable HTTP implementation; implement none of them by hand.
- Ship protocol-level tests that drive the server with the SDK's real client.

## 3. Non-goals

- Any tool that writes, applies, or executes: no `refactoring_apply`, no `/refactor-apply`, no shell execution, no file edits.
- Reading files outside the configured project root.
- Accepting a project root from tool arguments, or serving several roots from one process.
- Implementing OAuth authorization, token issuance, or dynamic client registration.
- MCP prompts (the installed skills already carry the semantic workflow; there is no approved use case).
- A configuration generator command that edits user files.
- Serving the MCP endpoint through the host application's own web server (Laravel route). Recorded as a possible follow-up (§20).

## 4. Decisions that need approval

| # | Decision | Recommendation | Why |
|---|----------|----------------|-----|
| D1 | SDK constraint | `mcp/sdk: ^0.8.1` (resolves to `>=0.8.1 <0.9.0`) as a runtime requirement | Pre-1.0: every minor so far shipped BC breaks (see SDK CHANGELOG 0.5→0.8). Patch releases are safe. |
| D2 | HTTP runtime | Long-lived listener built on `react/http ^1.11`, declared in `suggest` and `require-dev`, not in `require` | The SDK's HTTP transport is a per-request PSR-7 handler, not a server. A persistent process is what makes index reuse, bind rules, session cleanup and concurrency limits enforceable. ReactPHP hands us PSR-7 requests natively; the only lock change is `psr/http-message` 2.0→1.1, accepted by every current dependency. Keeping it optional means apps that never start HTTP install nothing extra. |
| D3 | Tool error signalling | `CapabilityException` → `CallToolResult` with `isError: true`, `structuredContent` = the existing error envelope, text content = the same JSON | Preserves the stable domain envelope for deterministic client handling while honouring the MCP tool-error convention. Verified: the SDK passes a handler-returned `CallToolResult` through unchanged. |
| D4 | Output schema shape | `{"type":"object","oneOf":[<success envelope>, <error envelope>]}` per tool | Strict clients validate `structuredContent` against `outputSchema`; a single success-only schema would make every error result invalid. |
| D5 | Capability descriptors gain `mcp_tool` | Add `'mcp_tool' => 'refactoring_audit'` (etc.) to each descriptor returned by `describeCapabilities()` | The descriptor already names its CLI fallback; naming its MCP tool makes the discovery envelope the single source of truth for both adapters and lets installed skills map capability → tool without guessing. Additive; `schema_version` stays `1.0`. |
| D6 | Authentication | Static bearer token via a package-owned PSR-15 middleware that reuses the SDK's `AuthorizationTokenValidatorInterface` / `AuthorizationResult`; OAuth resource-server mode is documented as future work | The SDK's `AuthorizationMiddleware` requires RFC 9728 `ProtectedResourceMetadata` with authorization servers, meaningless for a static token, and the SDK is Tier 3 / pre-1.0 with no BC promise on that API yet. Reusing the validator interface keeps a JWT validator a drop-in later. |
| D7 | Origin model | Reuse the SDK's `DnsRebindingProtectionMiddleware` with an allowlist of hosts: `Origin` present → its host must be allowlisted (else 403); `Origin` absent → `Host` must be allowlisted (else 403) | Matches the MCP transport spec's DNS-rebinding guidance, keeps non-browser clients (Inspector, curl, SDK clients) working, and blocks rebinding pages. |
| D8 | Index cache fingerprint | Content-based: `xxh128` of every included PHP file (path, size, digest), computed with the scanner's exclusions | Correctness over speed. mtime granularity can miss two edits within one second; hashing is far cheaper than parsing. |
| D9 | Tool names | Exactly as requested: `refactoring_capabilities`, `refactoring_audit`, `refactoring_analyze`, `refactoring_callers`, `refactoring_dependencies`, `refactoring_impact` | No rename is justified. `refactoring_callers` maps to capability `find_callers`; the mapping is explicit in the catalog. |
| D10 | Standalone `GET` stream | Not supported: the SDK answers `405` and this server never initiates messages | Documented limitation; `POST` and `DELETE` cover the full tool/resource lifecycle. |

## 5. Architecture

```text
MCP client (Claude Code / Cursor / Codex / Inspector / generic)
        |
        v
  MCP transport  ── stdio: Mcp\Server\Transport\StdioTransport
                 ── http:  ReactPHP listener -> PSR-15 pipeline -> Mcp\Server\Transport\StreamableHttpTransport
        |
        v
  Mcp\Server (official SDK: JSON-RPC, negotiation, sessions, schema validation)
        |
        v
  Peralta\AgentKit\Refactoring\Mcp  (thin adapter)
    RefactoringToolCatalog      tool + resource definitions, JSON schemas
    RefactoringToolHandler      tools/call -> RefactoringCapabilities, envelope conversion
    CapabilitiesResourceHandler resources/read -> catalog + describeCapabilities()
        |
        v
  RefactoringCapabilities (existing contract; unchanged signatures)
        |
        v
  ProjectScanner / CodebaseIndexBuilder (cached) / analyzers (existing)
```

Rules enforced by tests:

- The adapter depends on `RefactoringCapabilities`, never on `DefaultRefactoringCapabilities`, Artisan, or `Illuminate\Console`.
- No MCP class parses CLI output or invokes `Artisan::call`.
- The CLI keeps working with the MCP package classes never instantiated.
- Every MCP component is resolved from the Laravel container (`AgentKitServiceProvider::registerMcp()`).

### 5.1 Namespace layout

```text
src/Refactoring/Mcp/
├── RefactoringToolCatalog.php          tool/resource definitions (single source)
├── ToolDefinition.php                  value object: name, capability, schemas, description
├── RefactoringToolHandler.php          Mcp\Server\Handler\ToolHandlerInterface
├── CapabilitiesResourceHandler.php     Mcp\Server\Handler\ResourceHandlerInterface
├── McpProjectRoot.php                  canonical, validated, fixed root
├── McpServerFactory.php                builds Mcp\Server from the container + config
├── McpLogger.php                       PSR-3 logger bound to stderr (or a configured channel)
├── Transport/
│   ├── StdioServerRunner.php           StdioTransport wiring, signals, exit codes
│   └── Http/
│       ├── HttpServerOptions.php       validated bind/auth/limits (fails fast)
│       ├── HttpTransportFactory.php    per-request StreamableHttpTransport + middleware stack
│       ├── ReactHttpListener.php       react/http listener, idle timeout, shutdown
│       ├── BearerTokenAuthenticationMiddleware.php
│       ├── StaticBearerTokenValidator.php
│       └── BoundedInMemorySessionStore.php
└── Commands/
    └── McpServeCommand.php             php artisan agent-kit:mcp

src/Refactoring/Analysis/Index/
├── CodebaseIndexBuilder.php            interface implemented by CodebaseIndexer
├── CachedCodebaseIndexer.php           fingerprint-validated, bounded cache
└── ProjectFingerprint.php              content fingerprint over scanner-selected files
```

## 6. Tools

All six tools are registered explicitly with `Builder::add(new Tool(...), $handler)` so names, descriptions, input schemas, output schemas and annotations are declared data, not reflection. Annotations on every tool: `readOnlyHint: true`, `destructiveHint: false`, `idempotentHint: true`, `openWorldHint: false`.

| Tool | Capability method | Arguments |
|------|-------------------|-----------|
| `refactoring_capabilities` | `describeCapabilities()` | none |
| `refactoring_audit` | `audit($root)` | none |
| `refactoring_analyze` | `analyze($root, $target)` | `target` |
| `refactoring_callers` | `findCallers($root, $target)` | `target` |
| `refactoring_dependencies` | `dependencies($root, $target)` | `target` |
| `refactoring_impact` | `impact($root, $target)` | `target` |

`$root` is always the server's fixed root (§8). `target` follows the existing grammar: project-relative or absolute in-project PHP file (analyze only), fully qualified class, or `Class::method` where the capability supports method scope.

### 6.1 Input schemas

```json
{"type": "object", "properties": {}, "additionalProperties": false}
```

for `refactoring_capabilities` and `refactoring_audit`, and

```json
{
  "type": "object",
  "properties": {
    "target": {"type": "string", "minLength": 1, "description": "..."}
  },
  "required": ["target"],
  "additionalProperties": false
}
```

for the four target-based tools. The SDK validates arguments against the input schema before the handler runs; a missing, empty, mistyped or unknown argument is answered with JSON-RPC `-32602` (`Invalid params`) carrying `validation_errors`. The handler additionally trims `target`, and any domain rejection (`INVALID_TARGET`, `TARGET_NOT_FOUND`, `AMBIGUOUS_TARGET`, `UNSUPPORTED_TARGET`, `TARGET_OUTSIDE_PROJECT`, `PROJECT_ROOT_NOT_FOUND`) is a tool error (§6.3).

### 6.2 Output schemas and results

A successful call returns:

```text
CallToolResult(
  content:           [TextContent(<JSON of the envelope, pretty, unescaped slashes>)],
  isError:           false,
  structuredContent: <envelope array from CapabilityResult::toArray()>,
)
```

The envelope is byte-for-byte the same array the CLI prints with `--json`. Each tool declares an `outputSchema` of the form:

```json
{
  "type": "object",
  "oneOf": [
    {
      "type": "object",
      "properties": {
        "schema_version": {"type": "string"},
        "capability": {"const": "<capability name>"},
        "incomplete": {"type": "boolean"},
        "data": {"type": "object", "properties": {"<capability-specific keys>": {}}, "additionalProperties": true},
        "diagnostics": {"type": "array", "items": {"type": "object"}},
        "unresolved": {"type": "array", "items": {"type": "object"}}
      },
      "required": ["schema_version", "capability", "incomplete", "data", "diagnostics", "unresolved"]
    },
    {
      "type": "object",
      "properties": {
        "schema_version": {"type": "string"},
        "error": {
          "type": "object",
          "properties": {"code": {"type": "string"}, "message": {"type": "string"}},
          "required": ["code", "message"]
        }
      },
      "required": ["schema_version", "error"]
    }
  ]
}
```

The capability-specific `data` properties are taken from the existing payloads (`audit`: `project_root`, `summary`, `files`, …; `analyze`: `target`, `method`, `metrics`, `upstream_dependencies`, `direct_callers`, `structural_dependencies`, `transitive_impact`, `risk`; `find_callers`: `target`, `method`, `direct_callers`, `structural_dependencies`, `transitive_dependents`, `unresolved_scope`; `dependencies`: `target`, `upstream_dependencies`, `downstream_dependents`, `transitive_dependents`; `impact`: `target`, `method`, `risk`, counts, `direct`, `structural`, `transitive`, `affected_files`, …). The implementation plan pins the exact key lists from the DTOs' `toArray()` methods and freezes `tools/list` in a golden test. `additionalProperties: true` keeps future additive changes compatible.

### 6.3 Error mapping

| Situation | Response |
|-----------|----------|
| Argument violates input schema | JSON-RPC error `-32602` (SDK) |
| `CapabilityException` | `CallToolResult(isError: true, structuredContent: {schema_version, error:{code,message}}, content: [TextContent(same JSON)])` |
| Unknown tool name | JSON-RPC error `-32602` (SDK) |
| Any other throwable | JSON-RPC error `-32603` with the generic message `Error while executing tool` (SDK); the exception is logged to stderr with its stack trace, never sent to the client |

### 6.4 Server identity and instructions

`setServerInfo('agent-kit-refactoring', <package version>, description)` and `setInstructions()` with the deterministic-first discipline: the tools are read-only; results separate facts (`data`), parser diagnostics and unresolved dynamic references; `incomplete: true` means static analysis could not resolve everything; there is no apply tool.

## 7. Resource

`agent-kit://refactoring/capabilities` (`application/json`, read-only, static URI). The handler returns a `TextResourceContents` whose JSON is composed from `describeCapabilities()` and the catalog:

```json
{
  "schema_version": "1.0",
  "server": {"name": "agent-kit-refactoring", "version": "..."},
  "project_root": "/abs/path",
  "tools": [
    {
      "name": "refactoring_impact",
      "capability": "impact",
      "description": "...",
      "targets": ["class", "method"],
      "input_schema": {...},
      "output_schema": {...},
      "cli_fallback": "php artisan agent-kit:refactor-impact <class> --method=<method> --json"
    }
  ],
  "result_format": {"success": {...}, "error": {...}, "incomplete_semantics": "..."},
  "limitations": ["static analysis only", "no runtime container resolution", "..."],
  "mutation": {"supported": false, "note": "ANALYZE != MODIFY: no apply tool exists or is planned."}
}
```

A unit test asserts that every capability descriptor's `mcp_tool` names exactly one catalog tool and that the catalog's non-discovery tools cover exactly the descriptors, so no second list can drift.

## 8. Project root and isolation

- The root is fixed when the server starts: `--path` option > `agent-kit.mcp.project_root` > `base_path()`.
- `McpProjectRoot::resolve()` applies `realpath()`, requires an existing directory, rejects a regular file, and normalises with `ProjectRoot::normalize()`. Failure aborts start-up with exit code `1` and a stderr message.
- Tools never accept a root argument; the handler injects the fixed root into every capability call.
- In-root traversal and symlink escapes are already handled by `DefaultRefactoringCapabilities` (`TARGET_OUTSIDE_PROJECT`) and `ProjectScanner` (external symlinks are never indexed). MCP tests re-assert both through `tools/call`.
- Several projects are served by several server instances; clients configure one entry per project.

## 9. stdio transport

```bash
php artisan agent-kit:mcp --transport=stdio --path=/absolute/project
php artisan agent-kit:mcp                     # stdio is the default transport
```

Verified SDK facts used here: `new StdioTransport($input, $output, $logger, $runnerControl, $maxLineBytes)`; `Server::run()` returns `StdioTransport::listen(): int` (0 when the input stream closes); the loop checks `RunnerControl` state every 50 ms; one JSON-RPC message is written per line; oversized lines are discarded with a warning.

`StdioServerRunner`:

- validates the root and configuration **before** building the server or touching stdio;
- builds the `Mcp\Server` once (registry, container, logger, in-memory session store);
- sets `ini_set('display_errors', 'stderr')` and routes every log line to stderr through `McpLogger`; the command writes its own messages only to the console error stream;
- registers `SIGINT`/`SIGTERM` handlers when `pcntl` is available (`pcntl_async_signals(true)`) that flip `RunnerControl` to `STOP`, so the loop ends within 50 ms; without `pcntl`, EOF ends the loop;
- clears the index cache on exit; returns the transport status as the process exit code; start-up failures exit with `1`.

A subprocess test launches `php vendor/bin/testbench agent-kit:mcp --transport=stdio --path=<fixture>` with separate stdout/stderr pipes, forces a log line and a PHP notice, and fails if any stdout line is not a JSON-RPC message.

## 10. Streamable HTTP transport

```bash
AGENT_KIT_MCP_HTTP_ENABLED=true AGENT_KIT_MCP_BEARER_TOKEN=... \
php artisan agent-kit:mcp --transport=http --path=/absolute/project --host=127.0.0.1 --port=8787
```

### 10.1 Runtime

`ReactHttpListener` binds a `React\Socket\SocketServer` and a `React\Http\HttpServer` whose handler receives a PSR-7 `ServerRequestInterface`, runs it through `HttpTransportFactory` and returns the PSR-7 response produced by `Mcp\Server::run(new StreamableHttpTransport($request, ..., middleware: [...], maxBodyBytes: ...))`. One `Mcp\Server` instance lives for the whole process (persistent registry, in-memory sessions, cached index). Only the configured path (`/mcp` by default) is routed; anything else is `404`.

ReactPHP is optional: when `react/http` is not installed the command exits `1` with `composer require react/http` guidance. It is a `require-dev` dependency so the test-suite always exercises it.

Limitations stated in the docs: single-threaded (tool execution blocks the loop), no TLS termination (front it with a reverse proxy when exposing remotely), no standalone `GET` stream (`405`, D10). The PHP built-in server is never used.

### 10.2 Middleware stack (outermost first)

1. `Mcp\...\CorsMiddleware` — no `Access-Control-Allow-Origin` unless origins are configured; preflight `OPTIONS` → `204`.
2. `Mcp\...\DnsRebindingProtectionMiddleware(allowedHosts)` — D7; `403` on rejection.
3. `BearerTokenAuthenticationMiddleware(StaticBearerTokenValidator)` — §11; `401` before any MCP processing.
4. `StreamableHttpTransport` (SDK) — JSON-RPC, sessions, protocol-version header check, body cap.

`allowedHosts` = `localhost`, `127.0.0.1`, `[::1]` + `AGENT_KIT_MCP_ALLOWED_ORIGINS` entries (full origins are reduced to their host) + the bind host when `--allow-remote` is used.

### 10.3 Bind rules

`HttpServerOptions` fails start-up (exit `1`, stderr message, no socket opened) when:

- `agent-kit.mcp.http.enabled` is false;
- the host is not loopback (`127.0.0.1`, `::1`, `localhost`) and `--allow-remote` was not given;
- the bearer token is missing, empty, or shorter than 32 characters — with or without `--allow-remote`;
- the port is outside `1..65535`, `max_body_bytes < 1`, or timeouts/limits are non-positive.

### 10.4 Status matrix (verified against the SDK where marked)

| Request | Status |
|---------|--------|
| `OPTIONS` | `204` (SDK) |
| Any method, disallowed `Origin`/`Host` | `403` (SDK) |
| Any method, missing/invalid bearer | `401` + `WWW-Authenticate: Bearer error="invalid_token"` |
| Token passed only as a query parameter | `401` (query string is never read) |
| `GET` | `405` (SDK, D10) |
| `POST` body over `max_body_bytes` | `413` (SDK; ReactPHP's buffer limit is set to the same value) |
| `POST` invalid JSON / invalid JSON-RPC | `400` with JSON-RPC parse/invalid-request error (SDK) |
| `POST` without `Accept: application/json, text/event-stream` | SDK-defined (expected `406`); frozen by the protocol tests |
| `POST` with unsupported `MCP-Protocol-Version` | `400` (SDK) |
| `POST` non-initialize without `Mcp-Session-Id` | `400` (SDK) |
| `POST` with malformed or unknown session id | `400` / `404` (SDK) |
| `POST initialize` | `200`, JSON body, `Mcp-Session-Id` header (SDK) |
| `POST` notification only | `202` (SDK) |
| `DELETE` with valid session | `200` (SDK); session destroyed |
| `DELETE` without session | `400` (SDK) |
| Unrouted path | `404` (listener) |
| Unhandled exception | `500` with a fixed JSON body, no stack trace, logged to stderr |

### 10.5 Limits and lifecycle

- `max_body_bytes` (default 1 MiB), `max_concurrent_requests` (ReactPHP `LimitConcurrentRequestsMiddleware`, default 4), `idle_timeout` (connection closed after N seconds without a request, default 60), `session_ttl` (default 3600) and `max_sessions` (default 100, oldest evicted) via `BoundedInMemorySessionStore`.
- `SIGINT`/`SIGTERM` stop the loop, close the socket, destroy sessions and clear the index cache. Exit code `0`.
- Execution timeouts cannot interrupt synchronous PHP analysis; this is documented, and bounded by the index cache and the body/concurrency limits.

## 11. Authentication

- `StaticBearerTokenValidator::validate(string $accessToken): AuthorizationResult` compares with `hash_equals()` against the configured token and returns `AuthorizationResult::allow()` or `AuthorizationResult::unauthorized('invalid_token')`.
- `BearerTokenAuthenticationMiddleware` reads **only** the `Authorization: Bearer <token>` header; missing header → `401`; malformed header → `400` (`invalid_request`); invalid token → `401`. Responses carry `WWW-Authenticate: Bearer error="..."` and a fixed JSON body. The token never appears in logs, responses, or exceptions.
- The token comes from `AGENT_KIT_MCP_BEARER_TOKEN` (or a published config override). Docs cover generation (`php -r 'echo bin2hex(random_bytes(32));'`) and rotation (restart the listener; sessions are in memory).
- The SDK's OAuth resource-server stack (`AuthorizationMiddleware`, `JwtTokenValidator`, `JwksProvider`, `OidcDiscovery`, `ProtectedResourceMetadata`) was evaluated: it exists and server-side conformance is reported as complete, but it presumes an external authorization server, requires RFC 9728 metadata, and sits in a Tier 3, pre-1.0 SDK with no BC promise. It is therefore not wired in this iteration. Because our middleware consumes `AuthorizationTokenValidatorInterface`, a future `auth.driver = oauth` can plug `JwtTokenValidator` in without touching the transport code.

## 12. Index cache

`CodebaseIndexBuilder` is a new interface (`build(string $root): CodebaseIndex`) implemented by the existing `CodebaseIndexer`. `DefaultRefactoringCapabilities` is constructed with the interface; existing callers passing a `CodebaseIndexer` keep compiling. The service provider binds the interface to a singleton `CachedCodebaseIndexer` wrapping the real indexer.

`CachedCodebaseIndexer::build($root)`:

1. canonicalises the root (`ProjectRoot::normalize(realpath)`);
2. computes `ProjectFingerprint::compute($root)`: `xxh128` over `relative path | size | xxh128(contents)` of every file returned by `ProjectScanner::phpFiles($root)` (same exclusions as indexing);
3. returns the cached `CodebaseIndex` when root and fingerprint match; otherwise rebuilds, stores, and evicts the least recently used entry beyond `max_entries` (default 1 — one root per server);
4. `clear()` is called on shutdown.

Guarantees tested: two calls without changes reuse the index (parser call count does not grow); modifying, creating, or removing a PHP file invalidates it; results after invalidation reflect the current code; different roots never share entries; the bound evicts; a rebuilt index is equal to a fresh one. Nothing is persisted to disk. The CLI shares the same binding: within one process it behaves identically, and the fingerprint cost is negligible next to parsing.

## 13. Configuration

New `agent-kit.mcp` section:

```php
'mcp' => [
    'enabled' => env('AGENT_KIT_MCP_ENABLED', true),
    'transport' => env('AGENT_KIT_MCP_TRANSPORT', 'stdio'),
    'project_root' => env('AGENT_KIT_MCP_PROJECT_ROOT'), // null = base_path()
    'http' => [
        'enabled' => env('AGENT_KIT_MCP_HTTP_ENABLED', false),
        'host' => env('AGENT_KIT_MCP_HTTP_HOST', '127.0.0.1'),
        'port' => (int) env('AGENT_KIT_MCP_HTTP_PORT', 8787),
        'path' => env('AGENT_KIT_MCP_HTTP_PATH', '/mcp'),
        'allow_remote' => (bool) env('AGENT_KIT_MCP_ALLOW_REMOTE', false),
        'allowed_origins' => env('AGENT_KIT_MCP_ALLOWED_ORIGINS', ''), // comma-separated
        'bearer_token' => env('AGENT_KIT_MCP_BEARER_TOKEN'),
        'max_body_bytes' => (int) env('AGENT_KIT_MCP_HTTP_MAX_BODY_BYTES', 1048576),
        'idle_timeout' => (int) env('AGENT_KIT_MCP_HTTP_IDLE_TIMEOUT', 60),
        'max_concurrent_requests' => (int) env('AGENT_KIT_MCP_HTTP_MAX_CONCURRENT', 4),
        'session_ttl' => (int) env('AGENT_KIT_MCP_HTTP_SESSION_TTL', 3600),
        'max_sessions' => (int) env('AGENT_KIT_MCP_HTTP_MAX_SESSIONS', 100),
    ],
    'index_cache' => [
        'max_entries' => (int) env('AGENT_KIT_MCP_INDEX_CACHE_MAX_ENTRIES', 1),
    ],
    'logging' => [
        'level' => env('AGENT_KIT_MCP_LOG_LEVEL', 'info'),
        'channel' => env('AGENT_KIT_MCP_LOG_CHANNEL'), // null = stderr
    ],
],
```

Command options (`--path`, `--transport`, `--host`, `--port`, `--allow-remote`) override configuration. `.env.example` documents every variable with an empty token. No secret is committed anywhere.

## 14. Coding-agent templates and documentation

- `resources/agents/refactoring/instructions.md` and the six command bodies name the real tools (`refactoring_audit`, …) and keep the order: MCP tool → `--json` CLI → repository reading → interpretation. Golden fixtures are regenerated; a test asserts every skill mentions its tool and never an apply tool.
- `describeCapabilities()` descriptors gain `mcp_tool` (D5); `RefactorCapabilitiesCommand` prints it in the table.
- New `MCP_SERVER.md`: prerequisites, installation, stdio, HTTP, authentication, allowed origins, local bind vs `--allow-remote`, threat model, per-client configuration (Claude Code, Cursor, Codex `~/.codex/config.toml`, MCP Inspector, generic HTTP), diagnostics, stderr logging, schemas, cache/invalidation, limitations, SDK upgrade notes.
- `README.md`, `REFACTORING_AGENT.md`, `SETUP.md` (env + troubleshooting), `CHANGELOG.md` updated; every sentence saying MCP is future work is replaced.

## 15. Testing strategy

TDD throughout; failing test first for each unit.

**Unit** (`tests/Unit/Refactoring/Mcp`, `tests/Unit/Refactoring/CachedCodebaseIndexerTest.php`): exact registration of the six tools and absence of any apply/mutation tool; resource content; input/output schema validity and `tools/list` golden output; tool → capability method mapping and argument forwarding through a recording fake `RefactoringCapabilities`; `CapabilityResult`/`CapabilityException` conversion incl. `schema_version`, `incomplete`, `diagnostics`, `unresolved`; target validation; out-of-root rejection; adapter has no Artisan/Console dependency; root resolution; bearer validator timing-safe comparison; `HttpServerOptions` bind/token rules; bounded session store; cache behaviours listed in §12.

**stdio protocol** (`tests/Feature/Mcp/StdioServerTest.php`, SDK `Client` + `Client\Transport\StdioTransport` over `php vendor/bin/testbench agent-kit:mcp …`): initialize, `tools/list`, `tools/call` for each tool, `resources/list`, `resources/read`, domain error result, malformed JSON via raw `proc_open`, clean shutdown on EOF, stdout purity, logs only on stderr.

**Streamable HTTP** (`tests/Feature/Mcp/HttpTransportPipelineTest.php` in-process over the PSR-15 pipeline with real SDK transport; `tests/Feature/Mcp/HttpListenerTest.php` against a spawned listener using the SDK `Client\Transport\HttpTransport`): every row of §10.4, initialize/POST/DELETE lifecycle, valid vs invalid session, protocol-version header, `Accept` handling, body cap, idle timeout, missing/invalid/valid token, allowed/blocked `Origin`, remote bind refused without opt-in, start refused without token, absence of stack traces and of the token in any response.

**Compatibility**: full existing suite; CLI feature tests untouched except the additive `mcp_tool` assertion; installer idempotency tests with regenerated fixtures; `composer validate --strict`; `php -l` on changed files; `composer install --dry-run`; a manual MCP Inspector smoke test (`npx @modelcontextprotocol/inspector php artisan agent-kit:mcp --path=…`) recorded in the PR.

## 16. Compatibility

- Public contract: no signature changes. `DefaultRefactoringCapabilities` accepts the new `CodebaseIndexBuilder` interface (the concrete `CodebaseIndexer` still satisfies it). `describeCapabilities()` gains one additive key.
- CLI commands, JSON envelopes, `/refactor:*`-style skills, and installers keep their contracts; only template wording changes.
- PHP `^8.2` and Laravel 10–12 unchanged: `mcp/sdk` requires PHP `^8.1` and framework-agnostic PSR packages; `symfony/uid` resolves against both Symfony 6.4 and 7.x lines. PSR-17 factories come from the already-required `guzzlehttp/psr7 ^2` through `php-http/discovery`; no new PSR-7 implementation is needed.
- `mcp/sdk` becomes a runtime requirement (13 small packages); apps that never run `agent-kit:mcp` are otherwise unaffected. `react/http` stays optional.
- HTTP remains opt-in (`AGENT_KIT_MCP_HTTP_ENABLED=false` by default); no service starts unless the command is run.

## 17. Security review mapping

| Threat | Control |
|--------|---------|
| Path traversal / symlink escape | Fixed root; existing `TARGET_OUTSIDE_PROJECT` and scanner containment; MCP tests |
| Argument injection / indirect shell | Tools never spawn processes; arguments are validated strings passed to PHP code only |
| SSRF | No outbound HTTP anywhere in the MCP layer |
| DNS rebinding / Origin | SDK `DnsRebindingProtectionMiddleware` with host allowlist; loopback bind by default |
| Accidental public exposure | `--allow-remote` required for non-loopback binds; token mandatory regardless |
| Authentication / timing | `hash_equals`; minimum token length; 401 before any MCP processing |
| Secret leakage | Token read from env/config only; never logged, echoed, or written to files or fixtures |
| Log injection | Logs go through Monolog with context arrays; tool arguments logged only at debug level |
| Oversized payload / memory | SDK body cap + ReactPHP buffer cap (same value); batch cap 100 (SDK); bounded sessions and index cache |
| Abandoned sessions | TTL + GC + max sessions; destroyed on shutdown |
| Cache concurrency | Single-threaded process; immutable `CodebaseIndex`; fingerprint checked on every build |
| Exception leakage | SDK generic `-32603`; HTTP `500` fixed body; traces only on stderr |

## 18. Known limitations

- Static analysis limits from the existing core apply unchanged.
- HTTP listener is single-threaded and has no TLS; remote exposure requires a reverse proxy and `--allow-remote`.
- No standalone `GET` SSE stream; no server-initiated messages.
- Execution timeouts cannot preempt synchronous analysis.
- `mcp/sdk` is pre-1.0; upgrades beyond 0.8.x need a review of the constructor signatures listed in `MCP_SERVER.md`.
- One project root per server process.

## 19. Definition of done

1. `php artisan agent-kit:mcp` serves the six tools and the resource over stdio; MCP Inspector lists and calls them.
2. `--transport=http` serves the same over Streamable HTTP with bearer auth, Origin allowlist, loopback bind by default and the limits of §10.5.
3. Envelopes are identical to the CLI `--json` output; domain errors are `isError` results with the error envelope.
4. Index reuse and invalidation behave as §12 and are covered by tests.
5. Installed skills name the real tools and keep the CLI fallback.
6. Documentation matches the real commands, tools and variables; no secret is committed.
7. Full suite, strict Composer validation, lint and clean install pass; the diff against `origin/main` contains only this feature.

## 20. Alternatives considered

- **Laravel route (`Route::any('/mcp')`) served by the host app** — supported by the SDK docs and production-grade under FPM/Octane, but the package cannot enforce bind rules there and the in-memory index would not survive FPM requests. Candidate follow-up: reuse `HttpTransportFactory` from a controller once a persistent-index strategy for FPM exists.
- **PHP built-in server (`php -S`)** — no state between requests, development-only; rejected.
- **`amphp/http-server`** — modern, but no PSR-7 request objects; would need a bridge; ReactPHP integrates directly.
- **Hard `require` on `react/http`** — would force `psr/http-message` 1.x on every consumer; rejected in favour of `suggest` + `require-dev`.
- **SDK `AuthorizationMiddleware` for the static token** — requires OAuth protected-resource metadata; rejected (D6).
- **`ToolCallException` for domain errors** — drops the structured error envelope; rejected (D3).
- **mtime-based fingerprint** — cheaper but can miss same-second edits; rejected (D8).
- **Adding a `refresh` tool** — unnecessary with automatic invalidation; rejected.
