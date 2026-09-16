# Refactoring AST and Dependency Graph Design

## Context

The existing Refactoring Agent provides read-only PHP/Laravel audits through
`agent-kit:refactor-audit` and `agent-kit:refactor-analyze`. It discovers PHP
files with `ProjectScanner`, calculates deterministic metrics with
`PhpFileAnalyzer`, emits audit and baseline files, and applies configurable
smell thresholds.

This change extends that MVP with AST-backed structural analysis, a reusable
in-memory codebase index, an explicitly typed dependency graph, caller lookup,
and impact analysis. It preserves the rule `ANALYZE != MODIFY`: none of these
services or commands may change the analyzed project.

The existing audit API and output remain compatible. AST analysis is added
alongside `PhpFileAnalyzer`, rather than replacing its current token-based
metrics in this iteration.

## Goals

- Parse PHP with a mature AST implementation.
- Extract structural declarations and statically knowable references with
  source locations.
- Parse each included PHP file no more than once per index build.
- Build an in-memory index addressable by normalized FQCN and reference target.
- Represent dependency reasons as typed graph edges.
- Find direct callers and structural dependents of a class or method.
- Traverse transitive dependents safely in cyclic graphs.
- Provide deterministic impact summaries and configurable risk thresholds.
- Expose the analysis through thin Artisan commands with human and JSON output.
- Keep application services reusable by a future MCP server or other clients.

## Non-goals

- Modifying or refactoring analyzed source code.
- Executing analyzed code.
- Fully resolving Laravel container bindings or runtime service providers.
- Whole-program type inference, dynamic method resolution, or reflection.
- Persistent index caching in this iteration.
- Implementing an MCP server.

## Selected Approach

Evolve the MVP incrementally. Keep `PhpFileAnalyzer`, `ProjectScanner`, the
current DTO, reports, commands, and configuration behavior intact. Add AST,
index, graph, caller, and impact components under the existing
`Peralta\AgentKit\Refactoring` namespace. Reuse the scanner's file discovery
rules so exclusions have one source of truth.

Alternatives rejected:

1. Replacing `PhpFileAnalyzer` with AST analysis would couple smell metrics to
   structural indexing and unnecessarily risk existing audit compatibility.
2. A generic plugin pipeline for AST visitors would add extension machinery
   before a second implementation exists. Focused visitors and services are
   sufficient for this version.

`nikic/php-parser:^5.0` becomes a direct runtime dependency. It is currently
present only transitively through development packages, which is insufficient
for consumers of Agent Kit.

## Architecture

The implementation extends the existing module with these responsibilities:

```text
src/Refactoring/
├── Analysis/
│   ├── Ast/
│   │   ├── AstParser.php
│   │   └── PhpAstParser.php
│   ├── Index/
│   │   ├── CodebaseIndexer.php
│   │   └── CodebaseIndex.php
│   ├── Graph/
│   │   ├── DependencyGraph.php
│   │   ├── DependencyNode.php
│   │   ├── DependencyEdge.php
│   │   ├── DependencyType.php
│   │   └── Confidence.php
│   ├── CallerAnalyzer.php
│   └── ImpactAnalyzer.php
├── Commands/
├── DTOs/
└── Support/
```

Supporting DTOs and focused AST visitors may be split into additional files
when each has one clear responsibility. Domain and application types must not
depend on Illuminate Console classes.

The execution flow is:

```text
ProjectScanner discovers included PHP files once
  -> PhpAstParser parses each file once
  -> CodebaseIndexer extracts symbols and references
  -> CodebaseIndex stores FQCN and reverse-reference maps
  -> DependencyGraph serves direct and transitive graph queries
  -> CallerAnalyzer and ImpactAnalyzer return result DTOs
  -> Artisan commands validate input and render text or JSON
```

`ProjectScanner` will expose reusable discovery separately from its existing
`scan()` behavior. Existing callers continue to receive sorted `FileAnalysis`
results from `scan()`.

## AST Boundary and Structural Model

`AstParser` defines the parsing boundary. `PhpAstParser` uses PHP Parser 5 and
its `NameResolver` visitor. No PHP Parser node escapes into public application
results; Agent Kit-owned DTOs represent declarations, references, and parse
diagnostics.

The extracted declaration model must cover:

- namespace and imports, including aliases;
- classes, interfaces, traits, and enums;
- methods, properties, and constants;
- inheritance, implemented interfaces, and used traits;
- attributes;
- constructor dependencies;
- method parameter, return, and property types;
- instantiation, static calls, class constants, and inferable object calls;
- relevant function calls and statically identifiable Laravel relationships.

Every declaration and reference retains its file, enclosing class, enclosing
method when applicable, and AST source line. File paths are normalized relative
to the analyzed root in public results.

## Name and Type Resolution

The PHP Parser `NameResolver` handles namespaces, imports, aliases, and fully
qualified names. A small Agent Kit resolver handles context-sensitive names:

- `self` resolves to the declaring class;
- `static` resolves to the declaring class and is marked exact for the class
  dependency, without attempting late-static-binding subclass expansion;
- `parent` resolves to the normalized parent FQCN when declared;
- built-in and scalar types are not dependency nodes.

Union and intersection types contribute one structural edge for each resolved
class-like member. Nullable types retain the underlying class dependency.

Object-call targets are inferred only from:

- `$this->property` with a declared type or promoted constructor type;
- a typed method parameter;
- a local variable directly assigned from `new ClassName`;
- a direct result of supported `app()`, `resolve()`, or `app()->make()` calls
  containing `ClassName::class`.

The first iteration does not propagate inferred types across method boundaries,
arbitrary assignments, conditionals, collections, docblocks, or container
bindings.

## Confidence

Each edge has one of three confidence levels:

- `EXACT`: the AST explicitly identifies and resolves the target class.
- `INFERRED`: the receiver type follows safely from one of the supported local
  inference rules.
- `UNKNOWN`: a relevant relation is visible but its target is not reliable.

Unknown references may be retained as diagnostics, but do not create graph
edges to invented class names. Queries therefore prefer incomplete but sound
results over false positives.

## Codebase Index

`CodebaseIndexer::build(string $root): CodebaseIndex` obtains the included file
list once and parses each file once. The resulting index is treated as immutable
by consumers and stores maps for:

- declarations by normalized FQCN;
- methods by declaring FQCN and method name;
- declarations by file;
- outgoing dependencies by source FQCN;
- incoming references by target FQCN;
- direct call references by target FQCN and target method;
- parse diagnostics by file.

This shape supports future persistent serialization without implementing cache
invalidation now. No command may independently rescan files after building its
index.

## Dependency Graph

Initial nodes represent classes, interfaces, traits, and enums. A
`DependencyEdge` contains:

```text
source
sourceMethod
target
targetMethod
type
confidence
file
line
metadata
```

`DependencyType` includes:

- `CONSTRUCTOR_INJECTION`
- `METHOD_PARAMETER`
- `RETURN_TYPE`
- `PROPERTY_TYPE`
- `EXTENDS`
- `IMPLEMENTS`
- `TRAIT`
- `INSTANTIATION`
- `STATIC_CALL`
- `METHOD_CALL`
- `CLASS_CONSTANT`
- `ATTRIBUTE`
- `FACADE`
- `EVENT`

Multiple edges between the same nodes are preserved when their reason, method,
or source location differs. Graph summary counts that drive impact risk are
deduplicated by dependent FQCN.

Reverse traversal starts at the target and follows incoming edges. It maintains
a visited set, never returns the target as its own dependent, terminates on
cycles, and retains representative paths for human output.

## Laravel Awareness

Laravel-specific relationships are emitted only when the target class is
statically present:

- `event(new PaymentApproved(...))` creates an `EVENT` edge.
- `Event::dispatch(new PaymentApproved(...))` creates an `EVENT` edge.
- `dispatch(new ProcessPayment(...))` creates an `EVENT` edge with metadata
  identifying a job dispatch.
- `ProcessPayment::dispatch(...)` creates an `EVENT` edge to
  `ProcessPayment`.
- `Bus::dispatch(new ProcessPayment(...))` creates an `EVENT` edge with job
  dispatch metadata.
- `app(Service::class)`, `resolve(Service::class)`, and
  `app()->make(Service::class)` create an inferred dependency to `Service` and
  provide a usable receiver type for an immediately chained method call.
- Static calls through known Facade aliases create `FACADE` edges. The known
  facade FQCN prefixes and aliases are configurable, with conservative Laravel
  defaults.

The graph records only statically safe relationships and does not interpret
arbitrary string service keys or dynamic event/job class expressions.

## Caller Analysis

The application API supports class and optional method lookup:

```php
$result = $callerAnalyzer->findCallers(
    $index,
    'App\\Services\\PaymentService',
    'charge',
);
```

The result separates:

- direct callers: `METHOD_CALL` and `STATIC_CALL`, with source method, file,
  line, dependency type, and confidence;
- structural dependencies: constructor injection, parameter, return, property,
  inheritance, interface implementation, trait use, instantiation, class
  constant, and attribute references.

When a method is supplied, direct calls are restricted to that target method.
Structural class dependencies remain in the result because they are relevant
to class-level impact even without invoking that specific method. Results use
stable ordering by source FQCN, source method, file, line, and edge type.

## Impact Analysis

`ImpactAnalyzer::analyze(CodebaseIndex $index, string $target)` returns:

- normalized target FQCN;
- direct caller count;
- structural-dependent count;
- transitive-dependent count;
- affected-file count;
- deterministic risk;
- the underlying direct, structural, and transitive records and paths.

Each displayed category uses unique dependent FQCNs. `direct_callers` counts
classes with direct call edges, while `structural_dependencies` counts classes
with structural edges; the same class may legitimately appear in both displayed
categories. For reachability and risk, `direct dependents` means the union of
those two sets. Transitive dependents exclude that union from the
transitive-only count. Affected files are unique source files across direct,
structural, and transitive results.

Risk is based on the union of all unique direct and transitive dependents:

```php
'impact_thresholds' => [
    'low_max' => 2,
    'medium_max' => 7,
    'high_max' => 15,
],
```

- `LOW`: 0-2 dependents;
- `MEDIUM`: 3-7 dependents;
- `HIGH`: 8-15 dependents;
- `CRITICAL`: 16 or more dependents.

The thresholds are injectable into the analyzer and read from
`agent-kit.refactoring.impact_thresholds` by the service provider.

## CLI

Three thin commands are added:

```bash
php artisan agent-kit:refactor-callers "App\Services\PaymentService" \
  --method=charge --path=/path/to/project --json

php artisan agent-kit:refactor-impact "App\Services\PaymentService" \
  --path=/path/to/project --json

php artisan agent-kit:refactor-dependencies "App\Services\PaymentService" \
  --path=/path/to/project --json
```

`--path` defaults to `base_path()`. Without `--json`, commands render concise
tables and sections suitable for humans. With `--json`, commands emit only a
deterministic JSON document suitable for agents and future MCP adapters.

Commands only resolve arguments, build the index once, invoke an application
service, and render its result. Analysis logic and graph traversal must not live
inside command classes.

Invalid roots and missing target classes return command failure with a clear
message. Parse failures are collected as file diagnostics and do not abort the
entire index. Text output warns when diagnostics exist; JSON output includes a
`diagnostics` array. No query command writes analysis artifacts or source files.

## Configuration and Registration

Existing exclusions remain under `agent-kit.refactoring.exclude` and apply to
both audits and AST indexing. The configuration gains impact thresholds and a
conservative Facade-recognition list.

The service provider registers parser, indexer, graph/query services, and the
three commands. Parser and index instances are not global persistent state; a
command explicitly builds one index for its requested root.

## Performance

- Reuse scanner exclusions and normalized root handling.
- Discover files once per index build.
- Parse every included file at most once per index build.
- Index declarations and reverse references by normalized keys.
- Use adjacency maps for graph traversal.
- Protect all recursive traversal with visited sets.
- Continue excluding `vendor`, `storage`, `bootstrap/cache`, `node_modules`,
  `.git`, and user-configured directories.
- Avoid persistent caching now, while keeping index DTOs serializable enough for
  a future path/mtime/hash cache design.

## Testing Strategy

Development follows red-green-refactor TDD. Fixtures live under
`tests/Fixtures/Refactoring/Ast` and model a small Laravel-like payment flow.

Parser tests cover namespace resolution, imports and aliases, classes,
interfaces, traits, enums, methods, properties, constants, attributes,
constructor promotion, parameter/return/property types, inheritance,
implemented interfaces, trait use, `new`, static calls, class constants, source
context, and syntax diagnostics.

Index and graph tests cover node construction, every dependency type, distinct
parallel edges, reverse lookup, stable ordering, unique-dependent counting,
cycles, and the guarantee that each fixture is parsed only once per build.

Caller tests cover direct object calls, static calls, constructor and property
dependencies, aliases, method filtering, supported local receiver inference,
and unknown targets that do not become invented graph nodes.

Impact tests cover direct and transitive dependents, non-overlapping counts,
affected files, cycles, representative paths, configured boundaries, and all
four risk levels.

Laravel tests cover `event`, `Event::dispatch`, global and static job dispatch,
`Bus::dispatch`, `app`, `resolve`, `app()->make`, known Facades, and dynamic
expressions that must remain unresolved.

Feature tests cover command registration, human output, JSON schemas, method
filtering, invalid roots, missing targets, and parse diagnostics. Existing
`PhpFileAnalyzerTest` and `ProjectScannerTest` remain green to prove backward
compatibility.

## Documentation

`README.md` will list the new commands and link to detailed guidance.
`REFACTORING_AGENT.md` will document architecture, query semantics, JSON output,
confidence levels, exclusions, risk thresholds, Laravel awareness, performance,
and static-analysis limitations. The roadmap will remove features completed by
this iteration while retaining persistent cache and MCP exposure as future work.

## Acceptance Criteria

- Agent Kit directly requires PHP Parser 5 at runtime.
- Existing audit/analyze behavior and tests continue to pass.
- One index build parses each included PHP file no more than once.
- Aliases and contextual names resolve to normalized FQCNs.
- All specified structural elements retain source context.
- Graph edges explain the dependency reason and confidence.
- Caller lookup distinguishes direct calls from structural dependencies and
  supports method filtering.
- Impact traversal finds indirect dependents, terminates on cycles, deduplicates
  dependents, and applies configured risk thresholds.
- Supported Laravel patterns produce only statically defensible relationships.
- All three commands support deterministic `--json` output.
- Business logic remains outside Artisan commands and reusable by future MCP
  adapters.
- Analysis performs no writes to the analyzed project.
