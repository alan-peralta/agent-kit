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
| `AGENT_KIT_MCP_ALLOWED_ORIGINS` | *(empty)* | comma-separated extra allowed hosts; entries with a scheme also enable CORS for that exact origin |
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
- Binds `127.0.0.1` by default; `--host=localhost` also binds `127.0.0.1`
  (ReactPHP's `SocketServer` binds IP literals only, never hostnames). Any
  other host requires `--allow-remote` (or `AGENT_KIT_MCP_ALLOW_REMOTE=true`)
  and must be an IP literal — a non-loopback hostname is refused at start-up
  with `Could not bind the MCP HTTP transport … Use an IP literal`; the bearer
  token stays mandatory either way.
- A persistent single-threaded process built on ReactPHP: the AST index is
  reused across calls and invalidated by content fingerprint. Tool execution
  blocks the loop, so keep `AGENT_KIT_MCP_HTTP_MAX_CONCURRENT` small.
- No TLS: expose it remotely only behind a reverse proxy that terminates TLS.

### Authentication

Bearer token only, read from `AGENT_KIT_MCP_BEARER_TOKEN` (32+ characters).
Requests must send `Authorization: Bearer <token>`; missing or invalid tokens
get `401` with `WWW-Authenticate: Bearer`, malformed headers get `400`. The
query string is never read and the token is never logged or echoed. The one
exception is `OPTIONS` (CORS preflight): browsers never attach `Authorization`
to a preflight request, so it is answered `204` without checking the token —
it still passes the CORS and Origin/Host allowlist checks below; every other
method requires the token. Rotate by changing the variable and restarting the
listener (sessions are in memory). The validator implements the SDK
`AuthorizationTokenValidatorInterface`, so a JWT/OAuth resource-server
validator can replace it in a future release.

### Allowed origins and DNS rebinding

The SDK `DnsRebindingProtectionMiddleware` enforces a host allowlist:
`localhost`, `127.0.0.1`, `[::1]`, plus `AGENT_KIT_MCP_ALLOWED_ORIGINS`
(comma-separated hosts or origins, reduced to their host) and the bind host when
`--allow-remote` is used. A request with an `Origin` whose host is not listed is
`403`; without `Origin`, the `Host` header must be listed. `OPTIONS` requests
pass through this same allowlist before being answered. Remote clients must
therefore address the server through an allowlisted hostname.

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
| `AGENT_KIT_MCP_HTTP_MAX_BODY_BYTES` | `1048576` | `413` above this size (SDK and ReactPHP caps agree) |
| `AGENT_KIT_MCP_HTTP_MAX_CONCURRENT` | `4` | queued requests beyond this wait |
| `AGENT_KIT_MCP_HTTP_IDLE_TIMEOUT` | `60` | idle connections are closed |
| `AGENT_KIT_MCP_HTTP_SESSION_TTL` | `3600` | sessions expire after idle seconds |
| `AGENT_KIT_MCP_HTTP_MAX_SESSIONS` | `100` | oldest session evicted beyond this |

A `POST` whose declared `Content-Length` exceeds
`AGENT_KIT_MCP_HTTP_MAX_BODY_BYTES` is rejected with `413` before any
buffering. A chunked or unknown-size body that exceeds the limit has no
`Content-Length` to check up front; ReactPHP discards it once the limit is
crossed (memory stays bounded) and the server sees an empty message, which is
answered with a JSON-RPC error rather than `413`.

`SIGINT`/`SIGTERM` close the socket, destroy sessions, clear the index cache and
exit `0`. Execution timeouts cannot interrupt synchronous PHP analysis; keep
projects and concurrency bounded instead.

### Status codes

| Request | Status |
|---------|--------|
| `OPTIONS` (no bearer check; still passes CORS/Origin/Host allowlist) | `204` |
| Disallowed `Origin`/`Host` | `403` |
| Missing/invalid bearer token | `401` |
| Malformed `Authorization` header | `400` |
| `GET` | `405` (`Allow: POST, DELETE, OPTIONS`) |
| `POST` with declared `Content-Length` over the limit | `413` |
| Chunked/unknown-size body over the limit | discarded by ReactPHP; answered as an empty JSON-RPC message, not `413` |
| Invalid JSON | JSON-RPC `-32700` in the body |
| Unsupported `MCP-Protocol-Version` | `400` |
| Missing or malformed `Mcp-Session-Id` | `400` |
| Unknown or expired session | `404` |
| `DELETE` with a session | `200`; without | `400` |
| Path other than the endpoint | `404` |
| Internal failure | `500` with a fixed JSON body, details only on stderr |
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
  `AGENT_KIT_MCP_ALLOWED_ORIGINS`. A browser client that reaches the server but
  cannot read the response needs the **full** origin (`http://host:port`) there,
  not just the host, so CORS answers with `Access-Control-Allow-Origin`.
- *`Refusing to bind ... --allow-remote`*: non-loopback binds are opt-in.
- *`Could not bind the MCP HTTP transport ... Use an IP literal`*: `--host`
  resolved to a hostname other than `localhost`; pass an IP literal instead.
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
