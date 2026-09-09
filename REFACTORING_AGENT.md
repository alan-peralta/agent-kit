# Refactoring Agent

Agent Kit includes an initial deterministic refactoring audit for PHP/Laravel projects. The goal is to give an LLM objective repository signals before it proposes architectural changes.

## Commands

```bash
php artisan agent-kit:refactor-audit
php artisan agent-kit:refactor-audit /path/to/project
php artisan agent-kit:refactor-analyze app/Services/PaymentService.php
php artisan agent-kit:refactor-callers "App\Services\PaymentService"
php artisan agent-kit:refactor-callers "App\Services\PaymentService" --method=charge
php artisan agent-kit:refactor-dependencies "App\Services\PaymentService"
php artisan agent-kit:refactor-impact "App\Services\PaymentService"
```

The graph commands accept `--path=/path/to/project` and default to the Laravel
base path. Add `--json` to emit deterministic structured output without tables.

The audit writes:

```text
.agent-kit/refactoring/
├── audit.md
├── audit.json
└── baseline.json
```

`audit.json` is intended to be consumed by Cursor, Claude Code or another coding agent. `audit.md` is the human-readable report. `baseline.json` stores the summary for future trend comparison.

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
traits, enums, methods, properties, constants, attributes, inheritance,
implemented interfaces, used traits, declared types, instantiations, static
calls, class constants, and object calls whose receiver type can be inferred
safely. Every relationship retains its source class, source method, file, and
line.

Names are normalized to FQCNs. Imports, aliases, fully qualified names,
`self`, `static`, and `parent` are resolved before indexing. Union,
intersection, and nullable types are decomposed into their class-like members.

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
`event` edges from structural dependencies. With `--method`, only direct calls
to that method are returned; structural class dependencies remain visible
because they are still relevant to class-level impact.

`refactor-dependencies` lists outgoing edges from a class and explains the
target, type, confidence, source method, file, and line.

The JSON caller schema is:

```json
{
  "target": "App\\Services\\PaymentService",
  "method": "charge",
  "direct_callers": [],
  "structural_dependencies": [],
  "diagnostics": []
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

A syntax error in one PHP file produces a diagnostic and indexing continues.
Text output warns that results may be incomplete; JSON includes file, line, and
message in `diagnostics`. An invalid project root or missing target class fails
the command clearly.

## Static-analysis limits

The first version does not execute code, resolve runtime container bindings,
follow arbitrary assignments across branches, propagate types across method
boundaries, interpret dynamic class strings, or infer calls from untyped
receivers. It intentionally reports unknown rather than inventing a target.

## Agent workflow

Use this order when an LLM consumes the report:

1. Run `agent-kit:refactor-audit`.
2. Select a priority file from `audit.json`.
3. Read the implementation and all relevant callers.
4. Map side effects: database, queues, cache, Redis, HTTP APIs, events and webhooks.
5. Locate tests and identify behavior that must remain stable.
6. Confirm or reject each deterministic smell candidate.
7. Produce an incremental refactoring plan before editing.
8. Add characterization tests when coverage is insufficient.
9. Apply one conceptual refactoring at a time.
10. Run tests/static analysis after each meaningful change.

### Core rule

**ANALYZE != MODIFY.** Audit and analysis are read-only. A design pattern is never the goal; it must solve a concrete problem and should be preferred only when simpler refactoring is insufficient.

## Roadmap

Next iterations can add persistent path/mtime/hash index caching, method-level
cyclomatic complexity, duplicate detection, architecture constraints, baseline
comparison, MCP adapters for the reusable analysis services, and portable agent
commands such as `/refactor:audit`, `/refactor:plan`, and
`/refactor:architecture`.
