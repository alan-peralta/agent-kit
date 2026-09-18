# Optional MCP and AST Dependencies Design

Issue: [#11](https://github.com/alan-peralta/agent-kit/issues/11).

## Objective

Installing Agent Kit for its agent core (providers, tools, conversations, RAG)
must not pull the MCP server's or the Refactoring Agent's dependencies into the
consuming application. `mcp/sdk` and `nikic/php-parser` become optional, like
`react/http`, `pgvector/pgvector` and the AWS SDK already are, and every feature
that needs one of them fails with a clear install hint when it is missing.

## Background

Jatoindo Delivery adopted v0.3.0 for one `Agent` call
(`->provider('deepseek')->system()->options()->send()`). The install added
seven packages its runtime never loads (`mcp/sdk` and its transitive
`opis/json-schema`, `opis/string`, `opis/uri`, `php-http/discovery`,
`psr/http-server-handler`, `psr/http-server-middleware`), made `ext-fileinfo` a
platform requirement, and bumped the app's `nikic/php-parser` from 5.6.1 to
5.9.0. `php-http/discovery` is a Composer plugin: the package's own
`allow-plugins: {"php-http/discovery": false}` does not apply to consumers.

Facts established on 2026-09-18:

- Nothing in `src/` uses `ext-fileinfo`. It is required only because `mcp/sdk`
  requires it.
- `nikic/php-parser` is used by exactly two classes, `PhpAstParser` and
  `StructureCollector`. Only the AST capabilities need it: `analyze`,
  `find_callers`, `dependencies` and `impact`. `audit` (token based) and
  `describeCapabilities` do not.
- The container builds `PhpAstParser` eagerly when anything resolves
  `RefactoringCapabilities`, and its constructor calls `new ParserFactory()`.
  Without the parser even `agent-kit:refactor-audit` would crash.
- `CodebaseIndexer::build()` catches `\Throwable` per file and turns it into a
  diagnostic. A missing parser would therefore surface as one
  `Analysis failed: Error: Class "PhpParser\ParserFactory" not found`
  diagnostic per file followed by a misleading `TARGET_NOT_FOUND`.
- The `^5.8` constraint was simply the latest release when the AST index was
  added (23dd2cd); every node class and API the kit uses exists in 5.0. The
  full suite (630 tests) passes with the `PhpParser\` classes of v5.0.0 loaded
  in front of the locked 5.8.0. PHPUnit 11.5 itself needs `^5.7`, so the
  repository keeps `^5.8` for its own development.
- `agent-kit:mcp` resolves its runners before doing anything; without the SDK it
  would die deep inside `McpServerFactory` with `Class "Mcp\Server" not found`.
- None of the `Refactoring\Mcp` classes that `McpServeCommand::handle()` injects
  (`StdioServerRunner`, `ReactHttpListener`, `McpLoggerFactory`,
  `McpServerFactory`) extends or implements an SDK type, so resolving them does
  not autoload the SDK.

## Decision

Approach (a) from the issue: optional dependencies in the same package.
Approach (b), a separate `peralta/agent-kit-core` plus a metapackage, is out of
scope: it needs a repository split and coordinated versioning, and the package
is not even on Packagist yet. It can be revisited if the core grows.

The issue suggested registering the MCP and refactoring commands only under
`class_exists()`. Commands stay registered instead: a hidden command answers
`Command "agent-kit:mcp" is not defined`, which does not say what to install;
a registered one shows up in `php artisan list` and its error says exactly
which package to add.

## Composer manifest

- `require` loses `ext-fileinfo`, `mcp/sdk` and `nikic/php-parser`.
- `require-dev` gains `mcp/sdk: ^0.8.1` and `nikic/php-parser: ^5.8` (the
  package's own tests; the lock keeps the same versions).
- `suggest` gains:
  - `mcp/sdk`: "Required by the MCP server (php artisan agent-kit:mcp), ^0.8.1;
    install it with --dev"
  - `nikic/php-parser`: "Required by the Refactoring Agent's AST capabilities
    (analyze, callers, dependencies, impact), ^5.0; install it with --dev"
- A new `conflict` entry `"mcp/sdk": "<0.8.1 || >=0.9"` keeps MCP users on the
  tested SDK minor (the SDK is pre-1.0 and breaks between minors). It only
  affects applications that install the SDK.
- `nikic/php-parser` gets **no** `conflict` entry: a consumer whose toolchain
  pins php-parser 4 (an older Rector or Psalm) could not install the agent core
  at all. The major version is checked at run time instead.
- `config.allow-plugins` stays; it only governs this repository's own install.

## Missing dependency errors

### `Peralta\AgentKit\Exceptions\MissingDependencyException`

A `RuntimeException` carrying the Composer package name:
`__construct(public readonly string $package, string $message)`. Named
constructor for the common message:

```php
MissingDependencyException::forFeature(string $feature, string $package): self
// "{$feature} requires {$package}. Install it with: composer require --dev {$package}"
```

The hint says `--dev` because both features are development tools: the MCP
server and the AST index read source code, and `--dev` keeps the packages out
of production images built with `--no-dev`.

### AST capabilities

`Peralta\AgentKit\Refactoring\Analysis\Ast\PhpParserRequirement` has one
static method,
`assertSatisfied(string $factory = ParserFactory::class, string $version = PhpVersion::class): void`.
Both class names are parameters only so tests can stand in for a missing
package and for an older major.

- Class `$factory` does not exist → `MissingDependencyException::forFeature('The AST analysis (analyze, callers, dependencies, impact)', 'nikic/php-parser')`.
- The factory exists but `PhpParser\PhpVersion` does not (php-parser 4.x;
  4.18 and later already ship the 5.x factory methods, so a method check
  would not tell the majors apart) → `MissingDependencyException` for
  `nikic/php-parser` with the message `The AST analysis (analyze, callers, dependencies, impact) requires nikic/php-parser 5.x, but an older major version is installed. Upgrade it with: composer require --dev "nikic/php-parser:^5.0"`.

`PhpAstParser`:

- The constructor only stores the facade prefixes; it no longer touches
  php-parser. The parser property becomes `private ?Parser $parser = null`.
- `parse()` calls `PhpParserRequirement::assertSatisfied()` before creating the
  parser on first use (`$this->parser ??= ...`) and before reading the file,
  so the error does not depend on which file is parsed first.

`CodebaseIndexer::build()` rethrows `MissingDependencyException` from its
per-file `try` (a `catch (MissingDependencyException $e) { throw $e; }` clause
before the `\Throwable` one): a missing package breaks every file the same way,
so it is reported once instead of as one diagnostic per file.

`DefaultRefactoringCapabilities` routes its four `$this->indexer->build($root)`
calls through one private `index(string $root): CodebaseIndex` method that
converts `MissingDependencyException` into
`CapabilityException('DEPENDENCY_MISSING', $exception->getMessage())`.
The CLI (`renderCapabilityFailure`, `--json` or not) and the MCP tool handler
(`isError: true` envelope) already render `CapabilityException`, so both
adapters report the new code without changes. `audit` and
`describeCapabilities` never build the index and keep working.

A project with no PHP files never calls `parse()`, so the AST capabilities
answer `TARGET_NOT_FOUND` there instead of `DEPENDENCY_MISSING`; that answer is
also true and needs no special case.

### MCP server

`McpServeCommand::handle()` checks `class_exists(\Mcp\Server::class)` as the
first statement of its `try` block, right after the `enabled` check, and throws
`MissingDependencyException::forFeature('The MCP server', 'mcp/sdk')`. The
`catch` that already handles `McpConfigurationException` also catches
`MissingDependencyException` and refuses with its message, exit code 1, on
stderr. With neither `mcp/sdk` nor `react/http` installed,
`--transport=http` therefore reports the SDK first. The existing `react/http`
message in `ReactHttpListener` is unchanged.

## Testing

- **Unit:** `MissingDependencyException::forFeature()` message and package;
  `PhpParserRequirement` for a missing class, for the real `ParserFactory`
  with a missing `PhpVersion` marker class (php-parser 4.x), and for the
  real `ParserFactory` with the real `PhpVersion`; `PhpAstParser`
  construction does not create a parser (a constructed instance parses
  normally on first use); `CodebaseIndexer`
  rethrows `MissingDependencyException` from a fake `AstParser` instead of
  recording diagnostics; `DefaultRefactoringCapabilities` maps it to
  `DEPENDENCY_MISSING` for `analyze`, `findCallers`, `dependencies` and
  `impact` while `audit` and `describeCapabilities` still succeed.
- **CLI:** `agent-kit:refactor-analyze --json` with a `CodebaseIndexBuilder`
  that throws `MissingDependencyException` prints the `DEPENDENCY_MISSING`
  envelope and exits 1.
- **Without the optional packages:** the packages cannot be uninstalled in the
  test environment (PHPUnit depends on php-parser), so a feature test class
  runs each test in a separate process (`#[RunTestsInSeparateProcesses]`) and,
  before the application boots, replaces Composer's autoloader with a wrapper
  that skips the namespaces of every package the issue lists: `Mcp\`, `Opis\`,
  `Http\Discovery\`, `Psr\Http\Server\`, `PhpParser\` and `React\`. For those
  classes `class_exists()` answers `false`, exactly as if they were not
  installed. The test first asserts none of those classes is already loaded, so
  it cannot pass by accident. Scenarios:
  - an `Agent` with the real `DeepSeekProvider` (Guzzle mock handler through
    the provider's `handler` config), `system()`, `options()` and `send()`
    returns the mocked text;
  - `php artisan list` succeeds and lists `agent-kit:mcp` and the
    `agent-kit:refactor-*` commands;
  - `agent-kit:refactor-capabilities --json` and
    `agent-kit:refactor-audit --json` succeed on the AST fixture project;
  - `agent-kit:refactor-analyze`, `-callers`, `-dependencies` and `-impact`
    with `--json` exit 1 with `DEPENDENCY_MISSING` and the
    `composer require --dev nikic/php-parser` hint;
  - `agent-kit:mcp` exits 1 and prints
    `The MCP server requires mcp/sdk. Install it with: composer require --dev mcp/sdk`.
- **Manifest:** a unit test reads `composer.json` and asserts that `require`
  contains none of `ext-fileinfo`, `mcp/sdk` and `nikic/php-parser`, that
  `suggest` names `mcp/sdk` and `nikic/php-parser`, and that `conflict`
  constrains `mcp/sdk`.

## Documentation

- `README.md`: the install section says the agent core needs nothing else, and
  names the extra `composer require --dev` for the Refactoring Agent's AST
  commands and for the MCP server (in those sections too). "Atualizando" gains
  a "Vindo da v0.3.x" note: MCP or AST users run
  `composer require --dev mcp/sdk nikic/php-parser` after upgrading.
- `SETUP.md`: the same note next to its install step.
- `MCP_SERVER.md`: prerequisites (install `mcp/sdk` and `nikic/php-parser`;
  `ext-fileinfo` is required by the SDK; answer the `php-http/discovery` plugin
  prompt with `composer config allow-plugins.php-http/discovery false`, since
  the kit passes its PSR-17 factories explicitly), `DEPENDENCY_MISSING` in the
  error codes, a troubleshooting entry for `requires mcp/sdk`, and "Upgrading
  the SDK" explaining the `require-dev` plus `conflict` pair.
- `REFACTORING_AGENT.md`: which commands need `nikic/php-parser` and the
  `DEPENDENCY_MISSING` error; note that PHPUnit usually brings it into dev
  already.
- `ARCHITECTURE.md`: the refactoring section says `nikic/php-parser` is
  optional.
- `CHANGELOG.md` `[Não Lançado]`: an `Alterado` entry marked **BREAKING** for
  MCP and AST users, and `ext-fileinfo` under `Removido`. The version bump
  (v0.4.0) is a separate release PR.

## Out of scope

- Splitting the package (approach (b)).
- Changing the `react/http` message or other existing optional-dependency
  checks (`AwsTitanEmbedder`, `PgvectorStore`).
- Marking unavailable capabilities in `describeCapabilities()` or hiding MCP
  tools when php-parser is missing.
- The release itself and Packagist publication.
