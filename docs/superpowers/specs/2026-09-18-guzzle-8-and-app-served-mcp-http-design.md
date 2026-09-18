# Guzzle 8 Support and an Application-Served MCP HTTP Transport

## Objective

Let Agent Kit install and run on a fresh Laravel 13 application, which ships
Guzzle 8, without downgrading Guzzle. Accepting Guzzle 8 means removing the
MCP server's ReactPHP dependency, because `react/http` 1.x cannot coexist
with Guzzle 8. Alan chose to serve the MCP Streamable HTTP transport through the
application's own Laravel HTTP stack instead (option (b) of the 2026-09-18
assessment), rather than accepting that the HTTP transport is unavailable on
Guzzle 8 applications.

## Background

Facts established on 2026-09-18 with Laravel 13.32.0, Guzzle 8.2.0,
guzzlehttp/psr7 3.1.0 and mcp/sdk 0.8.1:

- A fresh `laravel/laravel` (13.x) application installs Guzzle 8.2. Agent Kit
  requires `guzzlehttp/guzzle: ^7.0`, so `composer require peralta/agent-kit`
  fails. PR #13 documents `-W`, which downgrades Guzzle to 7.x (Laravel 13 accepts
  `^7.8.2 || ^8.0`).
- With `guzzlehttp/guzzle: ^7.0|^8.0` on Laravel 13 + Guzzle 8 and without
  `react/http`, 71 tests fail, for these causes:
  - **Error classification (production bug).** Guzzle 8 moved
    `getResponse()` from `RequestException` to the new `ResponseException`, and
    `RequestException` no longer has `hasResponse()`. No-response network
    failures are now `NetworkException` subclasses (`ConnectException`,
    `ConnectTimeoutException`, `NetworkTimeoutException`), which are not
    `RequestException`s. `DefaultErrorClassifier` calls `hasResponse()`, so a
    4xx/5xx would throw `Error`, and a Guzzle 8 network timeout would be
    classified `SERVER_ERROR`.
  - **`QdrantStore` (56 tests).** It removes authentication headers inherited
    from an injected client by passing `'Authorization' => []` and
    `'api-key' => []`. Guzzle 8 rejects empty-array header values. Guzzle 7 and
    8 both accept the request option `'headers' => null`, which drops all of the
    client's default headers.
  - **Tests.** Error-recovery tests build `new RequestException($message,
    $request, $response)`; in Guzzle 8 the third parameter is `int $code`.
    `RequestException::create($request, $response)` exists in both versions and
    returns the right subclass. `new ConnectException($message, $request)` keeps
    its signature.
  - **MCP HTTP transport.** `react/http` has no stable release beyond 1.11.1,
    which requires `psr/http-message ^1.0`; Guzzle 8 requires guzzlehttp/psr7
    3.x, which requires `psr/http-message ^2.0`. Composer cannot install both.
- Everything else passes on Guzzle 8: the four providers, the embedders, the
  MCP stdio transport and the PSR-7 HTTP pipeline (`HttpTransportFactory` with
  guzzlehttp/psr7 3.x).
- mcp/sdk 0.8.1 installs with `psr/http-message` 2.0. Its
  `StreamableHttpTransport` serves both MCP lifecycles: the session-based one
  (`initialize`, `Mcp-Session-Id`) and the stateless 2026-07-28 one
  (`StatelessAwareTransportInterface`). It ships `Psr16SessionStore`.
- Its `DnsRebindingProtectionMiddleware` compares hosts case-insensitively and
  ignores the port.
- Per-request cost of the AST index on a real Laravel application (2,296 own
  PHP files, measured after the scanner fix in the scanner PR): fingerprint
  0.1–0.3 s, index build 4.4–4.7 s, serialized index 35.5 MB, unserialize
  0.6 s. A per-request process (PHP-FPM, `php artisan serve`) loses the
  in-memory index cache after every call.

## Scope

In scope:

1. Guzzle 8 in the core: `composer.json`, `DefaultErrorClassifier`,
   `QdrantStore`, tests.
2. The MCP Streamable HTTP transport served by an application route, ReactPHP
   removed.
3. A file-backed AST index snapshot so per-request processes do not rebuild the
   index on every call.
4. CI, documentation and CHANGELOG.

Out of scope: the stdio transport (unchanged), new MCP features (GET SSE
stream, server-initiated messages, OAuth), Packagist publication, the
release itself (v0.4.0 is a separate release PR).

## Part 1: Guzzle 8 in the core

### Composer

`guzzlehttp/guzzle: ^7.0|^8.0`. The lock stays on Guzzle 7 (Laravel 12).
`react/http` leaves `require-dev` and `suggest`.

### `DefaultErrorClassifier`

Classify the provider exception's previous throwable the same way on both
majors, without referring to classes that exist in only one of them:

1. `Psr\Http\Client\NetworkExceptionInterface` (Guzzle 7 `ConnectException`,
   Guzzle 8 `NetworkException` and subclasses) → `NETWORK_TIMEOUT`.
2. A throwable with a `getResponse()` method that returns a
   `Psr\Http\Message\ResponseInterface` (Guzzle 7 `RequestException` with a
   response, Guzzle 8 `ResponseException`) → `classifyStatusCode()` of its
   status, as today.
3. Any other `GuzzleHttp\Exception\RequestException` (a request failure without
   a response) → `NETWORK_TIMEOUT`, as today.
4. Anything else → `SERVER_ERROR`, as today.

`getResponse()` is called inside a `try` that treats a `Throwable` as "no
response", because Guzzle 7's `getResponse()` returns `null` when there is none.
The status-code mapping does not change.

### `QdrantStore`

Send each request as a PSR-7 `GuzzleHttp\Psr7\Request` built with the store's
own headers (`Accept`, `Content-Type`, and `api-key` only when a key is
configured) and a JSON body encoded with `json_encode(..., JSON_THROW_ON_ERROR)`
when there is one, through `$client->send($request, ['headers' => null,
'http_errors' => false, 'timeout' => $timeout])`. `'headers' => null` drops the
client's default headers on Guzzle 7 and 8 alike, so no inherited
`Authorization` or `api-key` reaches Qdrant; the store-level behaviour and its
tests stay as they are. The comment that explains the `[]` trick is replaced.

### Tests

Exceptions are built with `RequestException::create($request, $response)` and
`new ConnectException($message, $request)`, which work on both majors. A
classifier test covers each rule above, including a throwable whose
`getResponse()` returns `null`.

## Part 2: MCP HTTP served by the application

### Route

When `agent-kit.mcp.enabled` and `agent-kit.mcp.http.enabled` are both true,
the service provider loads `routes/mcp.php`, which registers
`Route::match(['GET', 'POST', 'DELETE', 'OPTIONS'], $path, McpHttpController::class)`
named `agent-kit.mcp`, where `$path` is `agent-kit.mcp.http.path` (default
`/mcp`). The route belongs to no middleware group: no `web` (CSRF, cookies,
session) and no `api` (throttling, which the token-protected endpoint does not
need). Global middleware still runs; Laravel's `HandleCors` only acts on the
paths listed in `config/cors.php`, which by default do not include `/mcp`.
`loadRoutesFrom()` respects `php artisan route:cache`, so the flags are read at
cache time; the docs say to re-cache after changing them.

### `McpHttpController`

An invokable controller that:

1. Builds `HttpTransportOptions` from `agent-kit.mcp.http`. A configuration
   error (for example a token shorter than 32 characters) is logged with its
   reason and answered `503` with a generic JSON error; the reason never
   reaches the client.
2. Unless `allow_remote` is true, answers `403` with a JSON error when the
   client IP (`$request->ip()`) is not a loopback address (`127.0.0.0/8` or
   `::1`). Behind a reverse proxy the application must trust the proxy
   (Laravel `TrustProxies`) for the real IP to be seen; the docs say so.
3. Raises the PHP time limit to `time_limit` seconds when it is above zero
   (`set_time_limit`), because PHP-FPM stops scripts after 30 s by default.
4. Converts the Laravel request to a `GuzzleHttp\Psr7\ServerRequest` (method,
   full URI, headers, protocol version, server params, and the raw body as a
   stream from `$request->getContent(true)`).
5. Builds the server with the existing `McpServerFactory`, the project root from
   `agent-kit.mcp.project_root` or `base_path()`, the MCP logger, and a
   `Psr16SessionStore` over `Cache::store($cacheStore)` with the prefix
   `agent-kit-mcp-session-` and the TTL `session_ttl`.
6. Runs it through the existing `HttpTransportFactory` (CORS, host/origin
   allowlist, bearer authentication, SDK transport), unchanged except that its
   own path check goes away, since routing already matched the path.
7. Converts the PSR-7 response to a Symfony `StreamedResponse` with the same
   status and headers that writes the body in 8 KiB chunks, so a streamed body
   (SSE) is not buffered.

### `HttpTransportOptions`

Replaces `HttpServerOptions`. Fields: `path`, `allowRemote`, `allowedHosts`,
`allowedOrigins`, `bearerToken` (32+ characters, as today), `maxBodyBytes`,
`sessionTtl`, `cacheStore` (`?string`, `null` = the application's default
store), `timeLimit` (seconds, `0` = leave PHP's limit alone). The allowed hosts
are `localhost`, `127.0.0.1`, `[::1]`, the host of `app.url`, and the hosts
parsed from `allowed_origins`, as today. Removed: `host`, `port`,
`idleTimeout`, `maxConcurrentRequests`, `maxSessions`, `bindUri()`.

### Configuration

`agent-kit.mcp.http` keeps `enabled`, `path`, `allow_remote` (now: accept
non-loopback client IPs), `allowed_origins`, `bearer_token`, `max_body_bytes`
and `session_ttl`. It gains `cache_store` (`AGENT_KIT_MCP_HTTP_CACHE_STORE`,
default `null`) and `time_limit` (`AGENT_KIT_MCP_HTTP_TIME_LIMIT`, default
`120`). It loses `host`, `port`, `idle_timeout`, `max_concurrent_requests`
and `max_sessions`, which are the web server's job now; their variables leave
`.env.example`.

### `agent-kit:mcp`

The command serves stdio only. `--transport=http` (or
`AGENT_KIT_MCP_TRANSPORT=http`) exits `1` with: `The Streamable HTTP transport
is served by your application at <path> when AGENT_KIT_MCP_HTTP_ENABLED=true;
start it with php artisan serve or your web server. See MCP_SERVER.md.`
The options `--host`, `--port` and `--allow-remote` are removed.

### Removed

`ReactHttpListener`, `BoundedInMemorySessionStore`, `HttpServerOptions`, their
tests, the subprocess `HttpListenerCommandTest`, and `react/http`.

## Part 3: AST index snapshot on disk

`CachedCodebaseIndexer` keeps its in-memory cache and gains an optional
`IndexSnapshotStore`:

- One file per project root, `<dir>/<sha1(root)>.idx`, holding a header line
  and the serialized `CodebaseIndex`. The header carries a format number, a
  hash of the context the service provider passes in (the facade prefixes from
  `agent-kit.refactoring.facades`, the installed `nikic/php-parser` version and
  the `peralta/agent-kit` reference, falling back to its version) and the
  fingerprint. A new fingerprint or context overwrites the file, so disk use is
  bounded to one snapshot per root.
- On a memory miss the indexer reads the header first and only reads and
  unserializes the body when it matches, returning the index without
  rebuilding. A missing, unreadable, corrupt or stale file, a different
  context, or an `unserialize` failure, falls back to a rebuild.
- Writes go to a temporary file in the same directory, made readable according
  to the process umask (`0666 & ~umask()`, so a CLI user and a PHP-FPM user of
  the same group can share it), and are renamed into place, so a concurrent
  reader never sees a partial snapshot. After a successful write, temporary
  files older than one hour (left by a writer that died) are removed. A failed
  write is ignored.
- The file is created by the application in its own storage, like Laravel's
  file cache, and is read with `unserialize()` at the same trust level.

Configuration: `agent-kit.mcp.index_cache.path` (`AGENT_KIT_MCP_INDEX_CACHE_PATH`).
Unset or empty means the default directory,
`storage_path('framework/cache/agent-kit/index')`; `false` disables snapshots;
any other value is the directory. The CLI commands and the HTTP route benefit;
the stdio server keeps its in-memory cache and only reads the snapshot on its
first call.

## Testing

- **Guzzle.** Classifier unit tests per rule on the locked Guzzle 7; the CI
  Guzzle 8 job runs the same tests on Guzzle 8. `QdrantStore` tests keep their
  assertions on the recorded requests (no inherited `Authorization`/`api-key`,
  the store's `api-key` when configured).
- **Route (in-process, Laravel test client).** The route is absent when HTTP or
  MCP is disabled; `OPTIONS` is `204` without a token; missing or wrong tokens
  are `401`; a disallowed `Host` or `Origin` is `403`; a non-loopback client IP
  is `403` unless `allow_remote`; a misconfigured token is `503` without the
  reason; a body over the limit is `413`; `GET` is `405`; the full MCP flow
  works (`initialize` returns an `Mcp-Session-Id`, `tools/list`, `tools/call`
  of `refactoring_audit` on the AST fixture returns the capability envelope,
  `DELETE` ends the session). Session persistence is proven with a `file` cache
  store and a fresh application instance between requests. The assertions of
  today's `HttpTransportPipelineTest` move onto the route.
- **Index snapshot.** A second `CachedCodebaseIndexer` (a fresh process) reads
  the snapshot without calling the inner indexer; a changed fingerprint
  rebuilds and replaces the file; a corrupt file rebuilds.
- **End to end.** A subprocess test starts PHP's built-in server on a free
  loopback port with HTTP enabled and a `file` cache store, serving the
  Testbench application with this package loaded: `vendor/bin/testbench serve`
  if it loads the package's service provider, otherwise `php -S` with a small
  router script that boots the Testbench application (the implementer checks
  which one works and records it). It reuses the existing `SpawnsMcpServer`
  helpers for the skeleton `.env`, then drives `initialize` → `tools/call` over
  real HTTP with Guzzle, which proves sessions survive a server where every
  request is a fresh script run.
- **Command.** `agent-kit:mcp --transport=http` exits `1` with the message;
  stdio tests are unchanged.

## CI

The `suite` matrix keeps "Suite (SQLite)" (the lock: Laravel 12, Guzzle 7) and
turns "Suite (Laravel 13)" into "Suite (Laravel 13, Guzzle 8)", which adds
`--with guzzlehttp/guzzle:^8.0` so resolution cannot fall back to Guzzle 7.

## Documentation

- `MCP_SERVER.md`: prerequisites (no `react/http`), the Streamable HTTP section
  rewritten around the route (enabling it, `php artisan serve` for local use,
  the web server's role in limits and concurrency, loopback-only by default,
  proxies, the production warning, route caching), the index snapshot, the
  client configuration examples with the application URL, troubleshooting, the
  status-code table without the ReactPHP rows, and the upgrade path.
- `README.md` and `SETUP.md`: the `-W` note from PR #13 goes away; the MCP
  section points to the route.
- `.env.example`: the new and removed variables.
- `CHANGELOG.md` `[Não Lançado]`: Guzzle 8 support under Adicionado; the HTTP
  transport move as **BREAKING** under Alterado; `react/http`, the listener
  options and variables under Removido; the classifier fix under Corrigido.
- README "Atualizando": what HTTP users change (enable the route, serve the app,
  point the client at the app URL, drop the removed variables).

## Security notes

The route runs inside the application, so enabling it on a deployed
environment exposes read-only source analysis to anyone holding the token who
can reach the application (and, with `allow_remote`, from any IP). The defaults
keep it off, loopback-only and token-protected, and the docs recommend
enabling it only on development machines. The project root comes from
configuration only; requests cannot choose it.
