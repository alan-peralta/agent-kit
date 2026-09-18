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

Streamable HTTP is not a separate listener process: enabling it registers a
route (`agent-kit.mcp`, `McpHttpController`) in the host Laravel application,
served by whatever already serves the rest of the app (`php artisan serve`,
PHP-FPM, Octane).

The CLI (`php artisan agent-kit:refactor-*`) and the MCP server are independent
adapters over the same `RefactoringCapabilities` contract; the JSON envelopes
are identical.

## Prerequisites

- PHP 8.2+ (8.3+ on Laravel 13); Laravel 10, 11, 12 or 13 with Agent Kit installed.
- `mcp/sdk` and `nikic/php-parser`, which Agent Kit only suggests. Install them
  in development: `composer require --dev mcp/sdk nikic/php-parser`. The SDK
  needs `ext-fileinfo`, and Agent Kit's `conflict` rule keeps it on `^0.8.1`
  (see [Upgrading the SDK](#upgrading-the-sdk)).
- The SDK brings the `php-http/discovery` Composer plugin. Agent Kit passes its
  PSR-17 factories explicitly and does not need it; to keep it from running,
  run `composer config allow-plugins.php-http/discovery false` before the
  `require` (the default Laravel skeleton allows it).
- Streamable HTTP needs no extra package: it runs on the application's own
  Laravel HTTP stack and the PSR-7 bridge Agent Kit already ships.

## Configuration

`config/agent-kit.php` → `mcp` (publish with `php artisan vendor:publish --tag=agent-kit-config`):

| Variable | Default | Meaning |
|----------|---------|---------|
| `AGENT_KIT_MCP_ENABLED` | `true` | `false` makes `agent-kit:mcp` refuse to start and hides the HTTP route |
| `AGENT_KIT_MCP_TRANSPORT` | `stdio` | default transport when `--transport` is omitted; `http` exits `1` (see [Streamable HTTP](#streamable-http)) |
| `AGENT_KIT_MCP_PROJECT_ROOT` | *(empty = base path)* | project root when `--path` is omitted, and the root the HTTP route analyzes |
| `AGENT_KIT_MCP_HTTP_ENABLED` | `false` | opt-in; registers the `agent-kit.mcp` route |
| `AGENT_KIT_MCP_HTTP_PATH` | `/mcp` | the single MCP endpoint, appended to the application's URL |
| `AGENT_KIT_MCP_ALLOW_REMOTE` | `false` | accept non-loopback client IPs |
| `AGENT_KIT_MCP_ALLOWED_ORIGINS` | *(empty)* | comma-separated extra allowed hosts; entries with a scheme also enable CORS for that exact origin |
| `AGENT_KIT_MCP_BEARER_TOKEN` | *(empty)* | required for HTTP, 32+ characters |
| `AGENT_KIT_MCP_HTTP_MAX_BODY_BYTES` / `_SESSION_TTL` / `_CACHE_STORE` / `_TIME_LIMIT` | see [Limits and lifecycle](#limits-and-lifecycle) | request bound, session lifetime, cache store and PHP time limit |
| `AGENT_KIT_MCP_INDEX_CACHE_MAX_ENTRIES` | `1` | in-memory cached roots per process |
| `AGENT_KIT_MCP_INDEX_CACHE_PATH` | *(empty in `.env.example`, disabled)* | on-disk AST index snapshot shared across per-request processes; unset falls back to `storage_path('framework/cache/agent-kit/index')` (see [Index cache](#index-cache)) |
| `AGENT_KIT_MCP_LOG_LEVEL` / `AGENT_KIT_MCP_LOG_CHANNEL` | `info` / *(stderr for stdio)* | logging |

Command options: `--transport=stdio|http`, `--path=`.

## Tools

| Tool | Capability | Arguments | Returns |
|------|------------|-----------|---------|
| `refactoring_capabilities` | `describeCapabilities()` | none | capability descriptors incl. `mcp_tool` and `cli_fallback` |
| `refactoring_audit` | `audit(root)` | none | metrics, smells and issue counts for the whole root |
| `refactoring_analyze` | `analyze(root, target)` | `target` | metrics, dependencies, callers, transitive impact, risk |
| `refactoring_callers` | `findCallers(root, target)` | `target` | direct callers, structural and transitive dependents |
| `refactoring_dependencies` | `dependencies(root, target)` | `target` | upstream, downstream and transitive dependencies |
| `refactoring_impact` | `impact(root, target)` | `target` | dependent counts, risk, affected files, records |

`target` is a project-relative or absolute in-project PHP file, a fully qualified
class, or `Class::method` where the capability supports method scope. A script path
(`routes/web.php`) is accepted by every tool that accepts a class, and
`file.php::function` is accepted where method scope is supported; function targets
answer with `risk: UNKNOWN` plus a diagnostic, because calls to user-defined
functions are not indexed. Every tool is annotated `readOnlyHint: true`,
`destructiveHint: false`. There is no `refactoring_apply`; `ANALYZE != MODIFY`.

`targets` in `refactoring_capabilities` lists the primary target kinds; script
paths ride on the `class` form.

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
`UNSUPPORTED_TARGET`, `TARGET_OUTSIDE_PROJECT`, `PROJECT_ROOT_NOT_FOUND`, and
`DEPENDENCY_MISSING` (the analyze, callers, dependencies and impact tools need
`nikic/php-parser`; the message says how to install it).
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

HTTP is opt-in and secure by default. It is not a listener process: enabling it
registers a route (`agent-kit.mcp`, `McpHttpController`) in the host Laravel
application, served by whatever already serves the rest of the app.
`agent-kit:mcp` itself serves stdio only; `--transport=http` (or
`AGENT_KIT_MCP_TRANSPORT=http`) exits `1` with a message pointing here instead
of trying to listen.

```bash
# .env — generate the token with: php -r 'echo bin2hex(random_bytes(32));'
AGENT_KIT_MCP_HTTP_ENABLED=true
AGENT_KIT_MCP_BEARER_TOKEN=<32+ characters>
```

```bash
php artisan serve
# endpoint: <APP_URL><AGENT_KIT_MCP_HTTP_PATH>, e.g. http://127.0.0.1:8000/mcp
```

- The endpoint is `config('app.url')` (`APP_URL`) plus `AGENT_KIT_MCP_HTTP_PATH`
  (default `/mcp`), serving `POST`, `DELETE` and `OPTIONS`; `GET` answers `405`
  (this server never initiates messages, so there is no standalone SSE stream).
- `php artisan serve` is a single worker by default: it handles one request at
  a time, so a second MCP call waits for the first one to finish. Under
  PHP-FPM or Laravel Octane the application serves several requests
  concurrently, one worker per request; concurrency, request timeouts and TLS
  are then the web server's job, not this package's.
- Loopback-only by default: a request whose client IP (`$request->ip()`) is
  not `127.0.0.0/8` or `::1` gets `403` unless `AGENT_KIT_MCP_ALLOW_REMOTE=true`.
  Behind a reverse proxy, configure Laravel's `TrustProxies` so `$request->ip()`
  reports the real client address rather than the proxy's.
- Enabling this on a deployed environment exposes read-only source analysis to
  anyone who holds the token and can reach the application (and, with
  `AGENT_KIT_MCP_ALLOW_REMOTE=true`, from any IP): keep it off in production
  and enable it only on development machines.
- The route is registered from `agent-kit.mcp.enabled` and
  `agent-kit.mcp.http.enabled` when the service provider boots, which respects
  `php artisan route:cache`; run `route:cache` again after enabling HTTP or
  changing `AGENT_KIT_MCP_HTTP_PATH` on an application with a cached route
  table, or the change will not take effect.
- Sessions live in the Laravel cache (`Cache::store()`, driven by
  `AGENT_KIT_MCP_HTTP_CACHE_STORE`, empty = the application's default store),
  prefixed `agent-kit-mcp-session-` and expiring after
  `AGENT_KIT_MCP_HTTP_SESSION_TTL` idle seconds. The `array` store does not
  survive between requests, so on a per-request process (`php artisan serve`,
  PHP-FPM) every session is lost as soon as the response that created it is
  sent; point `AGENT_KIT_MCP_HTTP_CACHE_STORE` at `file`, `redis`, `database`
  or another persistent store for real use.
- `AGENT_KIT_MCP_HTTP_TIME_LIMIT` (default `120`) raises PHP's execution time
  limit for the call (`set_time_limit`), because PHP-FPM stops scripts after
  30 s by default; `0` leaves PHP's own limit alone.
- Per-request processes lose the in-memory AST index after every call; an
  on-disk snapshot (`AGENT_KIT_MCP_INDEX_CACHE_PATH`, empty disables it) lets
  the next call reuse it instead of rebuilding — see [Index cache](#index-cache).
- No TLS: expose it remotely only behind a reverse proxy that terminates TLS.

### Authentication

Bearer token only, read from `AGENT_KIT_MCP_BEARER_TOKEN` (32+ characters).
Requests must send `Authorization: Bearer <token>`; missing or invalid tokens
get `401` with `WWW-Authenticate: Bearer`, malformed headers get `400`. The
query string is never read and the token is never logged or echoed. The one
exception is `OPTIONS` (CORS preflight): browsers never attach `Authorization`
to a preflight request, so it is answered `204` without checking the token —
it still passes the CORS and Origin/Host allowlist checks below; every other
method requires the token. Rotate by changing the variable: there is no
listener to restart, the next request simply reads the new value from config.
Sessions live in the Laravel cache, not in the token validator, so rotating
the token does not by itself invalidate open sessions. The validator
implements the SDK `AuthorizationTokenValidatorInterface`, so a JWT/OAuth
resource-server validator can replace it in a future release.

### Allowed origins and DNS rebinding

The SDK `DnsRebindingProtectionMiddleware` enforces a host allowlist:
`localhost`, `127.0.0.1`, `[::1]`, the host of `APP_URL`, plus
`AGENT_KIT_MCP_ALLOWED_ORIGINS` (comma-separated hosts or origins, reduced to
their host). A request with an `Origin` whose host is not listed is `403`;
without `Origin`, the `Host` header must be listed. `OPTIONS` requests pass
through this same allowlist before being answered. A remote client (with
`AGENT_KIT_MCP_ALLOW_REMOTE=true`) must therefore address the application
through an allowlisted hostname.

An entry of `AGENT_KIT_MCP_ALLOWED_ORIGINS` does two different things depending
on whether it carries a scheme:

- `http://localhost:6274` (a full origin) extends the host allowlist **and**
  enables CORS for that exact origin: a matching request gets
  `Access-Control-Allow-Origin: http://localhost:6274`, which is what browser
  clients such as the MCP Inspector need.
- `mcp.internal` (a bare host) only extends the host allowlist; no CORS header
  is ever emitted for it.

With no origins configured — the default — the server never sends
`Access-Control-Allow-Origin`, so a browser cannot read a response even when the
request itself is allowlisted. Scheme, host and port must match exactly;
`http://localhost:6274` does not cover `https://localhost:6274` or
`http://127.0.0.1:6274`.

### Limits and lifecycle

| Variable | Default | Effect |
|----------|---------|--------|
| `AGENT_KIT_MCP_HTTP_MAX_BODY_BYTES` | `1048576` | `413` above this size |
| `AGENT_KIT_MCP_HTTP_SESSION_TTL` | `3600` | sessions expire after idle seconds |

The SDK transport reads the `POST` body itself with a bound of
`AGENT_KIT_MCP_HTTP_MAX_BODY_BYTES`, so a body over the limit is rejected with
`413` the same way whether `Content-Length` was declared or the body is
chunked or of unknown size.

Concurrency, connection timeouts and TLS are the web server's job now
(`php artisan serve`, PHP-FPM, Octane, or a reverse proxy in front of them),
not a setting of this package — see [Streamable HTTP](#streamable-http).
Execution timeouts still cannot interrupt synchronous PHP analysis; use
`AGENT_KIT_MCP_HTTP_TIME_LIMIT` and keep projects bounded instead.

### Status codes

| Request | Status |
|---------|--------|
| `OPTIONS` (no bearer check; still passes CORS/Origin/Host allowlist) | `204` |
| Disallowed `Origin`/`Host` | `403` |
| Non-loopback client IP, `AGENT_KIT_MCP_ALLOW_REMOTE` not set | `403` |
| Misconfigured (for example a bearer token under 32 characters) | `503` |
| Missing/invalid bearer token | `401` |
| Malformed `Authorization` header | `400` |
| `GET` | `405` (`Allow: POST, DELETE, OPTIONS`) |
| `POST` body over `AGENT_KIT_MCP_HTTP_MAX_BODY_BYTES`, declared or chunked | `413` |
| Invalid JSON | JSON-RPC `-32700` in the body |
| Unsupported `MCP-Protocol-Version` | `400` |
| Missing or malformed `Mcp-Session-Id` | `400` |
| Unknown or expired session | `404` |
| `DELETE` with a session | `200`; without | `400` |
| Path other than the endpoint | `404` (the route simply does not match) |
| Internal failure | `500` with a fixed JSON body, details only in the application log |
| `Accept` header | not validated by `mcp/sdk` 0.8.x on the handshake transport; send `Accept: application/json, text/event-stream` anyway (the SDK client does) |

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

The Inspector CLI treats `--…` arguments after the server command as its own
options, so an inline `--path=…` is silently dropped and the server falls back
to the Laravel base path. Pass the project root through the environment, or
describe the server in a config file with the same shape as `.mcp.json`:

```bash
# Environment variable (place -e after the server command in --cli mode)
npx @modelcontextprotocol/inspector --cli php /absolute/path/to/laravel-app/artisan agent-kit:mcp \
  -e AGENT_KIT_MCP_PROJECT_ROOT=/absolute/path/to/project --method tools/list

# Config file: reuse the Claude Code / Cursor JSON above
npx @modelcontextprotocol/inspector --cli --config .mcp.json --server agent-kit-refactoring \
  --method tools/call --tool-name refactoring_impact --tool-arg 'target=App\Services\PaymentService::charge'

# Interactive UI
npx @modelcontextprotocol/inspector --config .mcp.json --server agent-kit-refactoring

# Streamable HTTP (route already served, e.g. `php artisan serve`):
npx @modelcontextprotocol/inspector http://127.0.0.1:8000/mcp
```

For HTTP, add the header `Authorization: Bearer <token>` in the Inspector UI.
The Inspector is a development tool, not a dependency of this package.

### Generic Streamable HTTP client

```bash
curl -s http://127.0.0.1:8000/mcp \
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
the index when any file was created, modified or removed.
`AGENT_KIT_MCP_INDEX_CACHE_MAX_ENTRIES` bounds the number of roots kept in
memory (default `1`); this cache is cleared on shutdown.

A per-request process (`php artisan serve`, PHP-FPM) starts with an empty
in-memory cache on every call, so building the index from scratch (seconds, on
a real application) would run again and again. `AGENT_KIT_MCP_INDEX_CACHE_PATH`
points at a directory where the indexer keeps one file per project root
(`<sha1(root)>.idx`, the fingerprint plus the serialized index). Leaving the
variable out of `.env` entirely defaults it to
`storage_path('framework/cache/agent-kit/index')`; an empty value — which is
what the shipped `.env.example` sets, so the snapshot is off until a path is
configured — disables it. On a memory miss the indexer reads this file: a
matching fingerprint returns the index without rebuilding, and a missing,
unreadable, corrupt or stale file falls back to a rebuild, which then
overwrites the snapshot. Writes go to a temporary file that is renamed into
place, so a concurrent reader never sees a partial snapshot. The CLI commands
and the HTTP route both benefit from this; the stdio server keeps its
long-lived in-memory cache and only reads the snapshot on its first call.

## Logging and diagnostics

On stdio, logs go to stderr at `AGENT_KIT_MCP_LOG_LEVEL` (default `info`). Set
`AGENT_KIT_MCP_LOG_CHANNEL` to route them to a channel from `config/logging.php`
instead; never pick a channel that writes to stdout when using stdio. Set the
level to `debug` to see tool arguments in the log; production should keep
`info`. The HTTP route always logs through the application's own log (the
configured channel, or the application's default channel when none is set),
governed by that channel's level in `config/logging.php` rather than
`AGENT_KIT_MCP_LOG_LEVEL`.

Troubleshooting:

- *Client hangs on connect (stdio)*: run the command manually with
  `< /dev/null` and read stderr; a start-up error exits `1`.
- *`401` on HTTP*: the header must be exactly `Authorization: Bearer <token>`;
  tokens in the URL are ignored.
- *`403` on HTTP, disallowed `Origin`/`Host`*: add the client's origin host to
  `AGENT_KIT_MCP_ALLOWED_ORIGINS`. A browser client that reaches the server but
  cannot read the response needs the **full** origin (`http://host:port`) there,
  not just the host, so CORS answers with `Access-Control-Allow-Origin`.
- *`403` on HTTP, "only accepts loopback clients"*: the request's client IP is
  not loopback; set `AGENT_KIT_MCP_ALLOW_REMOTE=true` to accept it, and behind
  a reverse proxy configure Laravel's `TrustProxies` so `$request->ip()` sees
  the real client instead of the proxy.
- *`503` on HTTP, "misconfigured"*: the reason (for example a bearer token
  under 32 characters) is written to the application log, never to the
  response; check `storage/logs/laravel.log` or the configured log channel.
- *`419` (CSRF token mismatch) on HTTP*: the `agent-kit.mcp` route must not run
  inside the `web` middleware group, and it does not by default; if a
  customized `bootstrap/app.php` (Laravel 11+) or `RouteServiceProvider`
  (Laravel 10) applies `web` globally, exclude this route from it.
- *`The MCP server requires mcp/sdk`*: `composer require --dev mcp/sdk nikic/php-parser`.
- *`DEPENDENCY_MISSING` from a tool*: `composer require --dev nikic/php-parser`.

## Security model

Threats considered: path traversal and symlink escape (fixed root, existing
containment checks), DNS rebinding and cross-origin browser calls (host
allowlist, no CORS origin by default), accidental public exposure (loopback-only
by default, explicit opt-in for remote clients, mandatory token), credential
timing attacks (`hash_equals`), secret leakage (token only from environment,
never logged), oversized payloads (body and batch caps), abandoned sessions (TTL),
exception leakage (generic errors on the wire, details only in the application log). The
server performs no outbound HTTP and no shell execution. Because the route runs
inside the host application, enabling it on a deployed environment exposes
read-only source analysis to anyone who holds the token and can reach that
application; keep it off, loopback-only and token-protected outside development.

## Limitations

- Static analysis only; dynamic PHP stays `unresolved`.
- One project root per process.
- No standalone `GET` SSE stream, no server-initiated messages, no prompts.
- No TLS of its own; concurrency and connection handling are the web server's
  (`php artisan serve` serializes calls with its single default worker;
  PHP-FPM and Octane parallelize them across workers).
- Execution timeouts cannot preempt a running analysis.

## Upgrading the SDK

`mcp/sdk` is pre-1.0 and its minor releases contain breaking changes. Agent Kit
tests against `^0.8.1` (`require-dev`) and, because applications install the
SDK themselves, holds them to the same range with
`"conflict": {"mcp/sdk": "<0.8.1 || >=0.9"}`; move both constraints together.
Before moving to a new minor, re-check
the constructor signatures used here: `Mcp\Server\Builder::add()`,
`Mcp\Schema\Tool`, `Mcp\Schema\Result\CallToolResult`,
`Mcp\Server\Transport\StdioTransport`, `Mcp\Server\Transport\StreamableHttpTransport`,
`Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware`,
`Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface`, and run
`tests/Feature/Mcp`.
