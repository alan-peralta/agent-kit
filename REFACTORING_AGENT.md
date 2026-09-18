# Refactoring Agent

Agent Kit includes an initial deterministic refactoring audit for PHP/Laravel projects. The goal is to give an LLM objective repository signals before it proposes architectural changes.

## Commands

```bash
php artisan agent-kit:refactor-audit
php artisan agent-kit:refactor-audit /path/to/project
php artisan agent-kit:refactor-analyze app/Services/PaymentService.php
php artisan agent-kit:refactor-callers "App\Services\PaymentService"
php artisan agent-kit:refactor-callers "App\Services\PaymentService::charge"
php artisan agent-kit:refactor-dependencies "App\Services\PaymentService"
php artisan agent-kit:refactor-impact "App\Services\PaymentService::charge"
```

The graph commands accept `--path=/path/to/project` and default to the Laravel
base path. Add `--json` to emit deterministic structured output without tables.
For audit, the project root is the optional positional argument. Its reports
stay under the analyzed project by default; `--output=/explicit/directory` may
select another destination explicitly. JSON mode emits data without writing
reports.

The audit writes:

```text
.agent-kit/refactoring/
├── audit.md
├── audit.json
└── baseline.json
```

`audit.json` is intended to be consumed by Cursor, Claude Code or another coding agent. `audit.md` is the human-readable report. `baseline.json` stores the summary for future trend comparison.

## Using Refactoring Agent with Coding Agents

Install the native Cursor or Claude Code skills into the project to analyze:

```bash
php artisan agent-kit:agents:install cursor --path=/project
php artisan agent-kit:agents:install claude --path=/project
php artisan agent-kit:agents:install --all --path=/project
```

`--path=/project` must name an existing directory. Omitting it uses the Laravel
application base path, while an explicitly empty or whitespace-only value is
rejected. Choose positional agents (`cursor`, `claude`) or `--all`; combining
them is rejected. Installation creates only Agent Kit-dedicated files. A
different existing file is reported as a conflict and preserved; `--force`
must be explicit to overwrite it. Reinstalling identical content reports the
file as unchanged.

Both adapters expose the same portable interface:

```text
/refactor-audit
/refactor-analyze <target>
/refactor-callers <target>
/refactor-dependencies <target>
/refactor-impact <target>
/refactor-plan <target>
```

Targets may be project-relative PHP files, fully qualified classes, or
`Class::method` where the capability supports method scope. For example:

```text
/refactor-impact App\Services\PaymentService::charge
```

The generated instructions gather evidence in this order:

1. A compatible MCP capability, when one is available.
2. The corresponding `php artisan agent-kit:refactor-* --json` command.
3. Repository search plus source and test reading for missing context.
4. LLM inference for interpretation only, never for invented relationships.

An Agent Kit MCP server does **not** exist yet; MCP is a future adapter. The
direct CLI is therefore the deterministic fallback today, for example:

```bash
php artisan agent-kit:refactor-impact "App\Services\PaymentService::charge" --json --path=/project
```

Responses separate `FACTS`, `INTERPRETATION`, and `RECOMMENDATIONS`. Dynamic
behavior that static analysis cannot resolve remains explicitly unknown or
unresolved. `ANALYZE != MODIFY`: the six skills audit, explain, or plan only.
No `/refactor-apply` command is generated.

The architecture stays deliberately small: one Refactoring Core provides the
capabilities shared by the direct CLI, Cursor/Claude Code adapters, and a future
MCP server. Agent-specific adapters render native skill and rule files from the
same canonical command repository instead of duplicating analysis logic.

```text
               Refactoring Core
                     |
      +--------------+--------------+
      v              v              v
     CLI        Coding Agents       MCP
```

## Current deterministic signals

- Large Class candidate (LOC threshold)
- Many Methods candidate
- High Coupling candidate (imports/use statements)
- High Branching candidate
- PHP/Laravel stack detection

Thresholds and excluded directories are configurable under `agent-kit.refactoring`.

## AST structural analysis

The structural analyzer uses `nikic/php-parser` rather than regular expressions.
Each included PHP file is parsed once while an in-memory index is built:

```text
ProjectScanner
  -> PhpAstParser
  -> CodebaseIndexer / CodebaseIndex
  -> DependencyGraph
  -> CallerAnalyzer / ImpactAnalyzer
  -> CLI presentation
```

The index extracts namespaces, imports and aliases, classes, interfaces,
traits, enums, scripts (files with code outside a named class), methods,
top-level functions, properties, constants, attributes, inheritance,
implemented interfaces, used traits, declared types, `ClassName::class`
expressions, instantiations, static calls, class constants, and object calls
whose receiver type can be inferred safely. Every relationship retains its
source (class or script), source method, file, and line.

Names are normalized to FQCNs. Imports, aliases, fully qualified names,
`self`, `static`, and `parent` are resolved before indexing. Union,
intersection, and nullable types are decomposed into their class-like members.

### Procedural code and scripts

Code outside a named class — `routes/*.php`, `config/*.php`,
`bootstrap/app.php`, helper files and anonymous-class migrations — is indexed
too. A file with at least one reference at script scope or at least one
top-level function yields a `script` symbol identified by its root-relative
path (for example `routes/web.php`, with `kind: "script"` and `line: 1`).
The symbol is created only when needed, so files that only declare classes
keep exactly the symbols they declare.

References are attributed to the routine that declares the code:

| Code | `source` | `source_method` |
|---|---|---|
| statement at script scope, including closures and control flow there | script path | `null` |
| body of a top-level function (also inside `if (!function_exists(...))`) | script path | function name |
| named function nested in a method, function or closure | the declaring routine | unchanged |
| anonymous class body | the declaring routine | unchanged |

Top-level functions are listed in the script's `methods` with the same shape
as class methods, so `helpers.php::make_user` is a valid `refactor-analyze`
target. Scripts appear as dependents in `refactor-callers`,
`refactor-impact` and `refactor-analyze` results, and a root-relative script
path is accepted wherever a class name is accepted (`refactor-dependencies
routes/web.php` lists what a routes file depends on). `ClassName::class`
expressions are recorded as `class_constant` edges with
`metadata.constant = "class"`, which is how routes, config arrays, listeners
and Eloquent relations name their classes.

Inside an anonymous class `$this`, `self` and `static` have no name, so calls
through them stay `unknown`; `parent::` resolves to the declared parent, and
the anonymous class's typed properties still drive receiver inference. Calls
to user-defined functions are not tracked: scripts and functions only appear
as sources, never as call targets.

### Dependency types

Edges retain the reason two symbols are related:

- `constructor_injection`
- `method_parameter`
- `return_type`
- `property_type`
- `extends`
- `implements`
- `trait`
- `instantiation`
- `static_call`
- `method_call`
- `class_constant`
- `attribute`
- `facade`
- `event`

Distinct relationships between the same two classes remain distinct edges.
Impact counts are deduplicated by dependent FQCN so repeated calls do not
artificially inflate risk.

### Confidence

- `exact` — the AST explicitly identifies and resolves the class.
- `inferred` — the receiver follows from a typed property or parameter, a
  direct `new` assignment, or a supported Laravel container helper.
- `unknown` — a relevant dynamic call exists but has no reliable target.

Unknown calls are retained as parser information but never become graph edges
to invented class names.

## Callers and dependencies

`refactor-callers` separates direct `method_call`, `static_call`, `facade`, and
`event` edges from structural dependencies. With a `Class::method` target, only
direct calls to that method are returned; structural class dependencies remain
visible because they are still relevant to class-level impact.

`refactor-dependencies` lists outgoing edges from a class and explains the
target, type, confidence, source method, file, and line.

The JSON caller schema is:

```json
{
  "schema_version": "1.0",
  "capability": "find_callers",
  "incomplete": false,
  "data": {
    "target": "App\\Services\\PaymentService",
    "method": "charge",
    "direct_callers": [],
    "structural_dependencies": [],
    "transitive_dependents": []
  },
  "diagnostics": [],
  "unresolved": []
}
```

Each caller/dependency item contains `source`, `source_method`, `target`,
`target_method`, `type`, `confidence`, `file`, `line`, and `metadata`.

## Impact analysis

Impact traversal follows incoming graph edges recursively and protects against
cycles. Direct dependents are the union of direct callers and structural
dependents. The transitive count excludes that direct union and the target
itself. Affected files are unique source files across all categories.

Default risk boundaries are configurable under
`agent-kit.refactoring.impact_thresholds`:

```text
LOW       0-2 unique dependents
MEDIUM    3-7
HIGH      8-15
CRITICAL  16+
```

The JSON impact response includes `target`, `direct_callers`,
`structural_dependencies`, `transitive_dependents`, `affected_files`, `risk`,
the detailed `direct`, `structural`, and `transitive` records, and parser
`diagnostics`. Transitive records include a representative dependency path.

## Laravel awareness

The analyzer recognizes statically explicit forms of:

- `event(new EventClass(...))`
- `Event::dispatch(new EventClass(...))`
- `dispatch(new JobClass(...))`
- `JobClass::dispatch(...)`
- `Bus::dispatch(new JobClass(...))`
- `app(Service::class)`
- `resolve(Service::class)`
- `app()->make(Service::class)`
- static calls through configured Laravel Facade prefixes

Event/job dispatches preserve both the dispatch relationship and any direct
static/Facade call. Dynamic strings and expressions are not guessed.

## Performance and diagnostics

`ProjectScanner` applies the same configured exclusions to audit and AST
indexing. Defaults exclude `vendor`, `storage`, `bootstrap/cache`,
`node_modules`, and `.git`. File discovery occurs once and each included PHP
file is parsed once per index build. Declaration and reverse-reference maps
avoid rescanning for each query.

A syntax error, an unreadable file or an internal analysis failure in one PHP file produces a diagnostic (`Analysis failed: …` for the latter two) and indexing continues.
Text output warns that results may be incomplete; JSON includes file, line, and
message in `diagnostics`. An invalid project root or missing target class fails
the command clearly.

## Static-analysis limits

The first version does not execute code, resolve runtime container bindings,
follow arbitrary assignments across branches, propagate types across method
boundaries, interpret dynamic class strings, or infer calls from untyped
receivers. It intentionally reports unknown rather than inventing a target. It also does not track calls to user-defined functions and names nothing for `$this`, `self` or `static` inside anonymous classes.

## Agent workflow

Use this order when an LLM consumes the report:

1. Run `agent-kit:refactor-audit`.
2. Select a priority file from `audit.json`.
3. Read the implementation and all relevant callers.
4. Map side effects: database, queues, cache, Redis, HTTP APIs, events and webhooks.
5. Locate tests and identify behavior that must remain stable.
6. Confirm or reject each deterministic smell candidate.
7. Produce an incremental refactoring plan with isolated steps.
8. Recommend characterization coverage when existing tests are insufficient.
9. Identify validation and rollback considerations for every proposed step.

### Core rule

**ANALYZE != MODIFY.** Audit and analysis are read-only. A design pattern is never the goal; it must solve a concrete problem and should be preferred only when simpler refactoring is insufficient.

## Roadmap

Next iterations can add persistent path/mtime/hash index caching, method-level
cyclomatic complexity, duplicate detection, architecture constraints, baseline
comparison, and an MCP server adapter for the existing reusable capabilities.
