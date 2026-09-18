# Procedural Code Indexing and Indexer Resilience — Design

**Date:** 2026-09-17
**Status:** approved (chat approval, 2026-09-17)
**Builds on:** PR #5 (`fix: keep AST scope stacks balanced outside named classes`)
**Origin:** the three follow-ups raised by the adversarial review of PR #5.

## Problem

`StructureCollector` only records symbols and references while inside a
named class. After PR #5 the collector no longer crashes on procedural code,
but everything outside a named class is still invisible to the index:

1. `routes/*.php`, `config/*.php`, `bootstrap/app.php`, `helpers.php` and
   anonymous-class migrations produce no symbols and no references, so a
   controller wired in `routes/web.php` reports zero callers and a model used
   by a data migration reports no dependents.
2. A named function declared inside a class method is analysed as if its body
   belonged to the method: its parameters overwrite the method's local types
   and its references are attributed to the method.
3. `CodebaseIndexer::build()` calls `AstParser::parse()` without any guard, so
   an unreadable file or an internal analysis failure in one file aborts the
   whole index instead of degrading to a `ParseDiagnostic`.

## Goals

- Every included PHP file with code outside a named class contributes its
  references to the dependency graph, attributed to a stable source.
- Top-level functions are addressable targets (`helpers.php::make_user`).
- Anonymous class bodies contribute references without inventing names.
- Nested named functions get their own local scope and never leak types into
  the declaring routine.
- One failing file never aborts the index.
- No change to the success/error envelopes, the `DependencyType` set, the
  `Confidence` set, the CLI/JSON schemas, or `describeCapabilities()`.

## Non-goals

- Tracking calls to user-defined functions (would need a global function
  table and a new dependency type). Scripts and functions never appear as
  call *targets*; they only appear as *sources*.
- Emitting symbols for anonymous classes.
- Resolving `self`/`static`/`$this` inside anonymous classes.
- Changing the `targets` list in `describeCapabilities()` or the MCP tool
  descriptors (PR #4). Root-relative script paths are accepted wherever a
  class name is accepted; documenting that in the MCP catalogue is a
  post-merge follow-up.

## Design

### Script symbols

A file that has at least one reference at script scope, or at least one
top-level function, yields one extra `SymbolDefinition`:

| field | value |
|---|---|
| `fqcn` | the root-relative path with `/` separators, e.g. `routes/web.php` |
| `kind` | `script` |
| `file` | same path |
| `line` | `1` |
| `methods` | the top-level functions, in the same shape as class methods (`name`, `parameters`, `return_types`, `line`) |
| `properties`, `constants`, `attributes` | `[]` |

The symbol is created lazily, so a file that only declares classes keeps
exactly the symbols it has today (no regression for `File.php::method`
targets, `classesInFile()` consumers or the "ambiguous declaration" check).
Paths never collide with FQCNs because class names cannot contain `/` or `.`.

`CodebaseIndexer` needs no special case: the script becomes a
`DependencyNode` with `kind: script`, its references become edges, and it is
a valid target for `findClass()`, so `refactor-dependencies routes/web.php`,
`refactor-callers routes/web.php` (always empty) and
`refactor-impact routes/web.php` work without new code paths.

### Attribution rules

`Reference.source` / `Reference.sourceMethod` are set by the *declaring
routine* of the code being visited:

| code location | `source` | `source_method` |
|---|---|---|
| statement at script scope (incl. closures, arrow functions and control flow there) | script path | `null` |
| body of a top-level function (also when wrapped in `if (!function_exists(...))`) | script path | function name |
| body of a named function nested in a method/function/closure | unchanged (the declaring routine) | unchanged |
| body of an anonymous class (all of its methods, property types, `extends`/`implements`, attributes) | unchanged (the declaring routine) | unchanged |
| body of a named class (as today) | class FQCN | method name or `null` |

Inside an anonymous class `$this`, `self` and `static` have no name, so
references through them keep `target: null` / `confidence: unknown`;
`parent::` resolves to the anonymous class's parent. The anonymous class's
own typed properties and promoted constructor parameters still drive
`$this->prop->method()` inference inside its methods.

### Collector state

`StructureCollector` gains three fields:

- `string $source` — attribution source, initialised to the file path and set
  to the FQCN when a *named* class is entered; anonymous classes leave it
  untouched. Stashed and restored through `classStack` like the other fields.
- `bool $scriptScope` — `true` while no named class encloses the visitor; the
  trigger for the lazy script symbol.
- `?array $script` — the lazily created script symbol (`['methods' => []]`).

The `currentClass === null` guards added by PR #5 in `enterNode()` and
`leaveNode()` are removed: with every scope handled uniformly, pushes and
pops are symmetric by construction, and PR #5's regression tests keep
proving it.

Writes to `$this->symbol[...]` (methods, properties, constants, attributes)
are guarded by `$this->symbol !== null`, which is the anonymous-class case.

### Named functions

`Stmt\Function_` is handled on both sides:

- enter: push a local-scope frame (like a closure without captured
  variables) so the function gets fresh locals and typed parameters; if the
  function is top-level (script scope, no enclosing routine, no enclosing
  anonymous class), also register it in the script symbol, set
  `currentMethod` to its name and record `method_parameter` / `return_type`
  references.
- leave: pop the frame and restore the previous `currentMethod`.

Local-scope frames carry `currentMethod` as a sixth element so closures and
functions share the same push/pop code. `writtenVariablesIn()` stops at
`Function_` nodes exactly as it stops at closures.

Attributes on top-level functions are collected like method attributes.

### `ClassName::class` references

`StructureCollector::collectClassConstant()` deliberately skipped
`ClassName::class` (locked by
`LaravelReferenceTest::test_dynamic_class_constant_references_preserve_every_known_fact`).
Routes (`[UserController::class, 'index']`), config arrays
(`'providers' => [X::class]`), listeners and Eloquent relations name their
classes exactly this way, so without it the script symbols above would carry
almost no edges. Amendment: `ClassName::class` is recorded everywhere as a
`class_constant` reference with `confidence: exact` and
`metadata: {"constant": "class"}`. Dynamic forms (`$class::class`) stay
`unknown`. `app(X::class)` therefore yields both its `instantiation` edge and
a `class_constant` edge on the same line; structural counts deduplicate by
source, so risk levels only change where a class was previously invisible.
The locked test is updated to expect the fourth reference.

### Capabilities

`DefaultRefactoringCapabilities::analysisTarget()` keeps returning every
symbol of the file for plain file targets (the script's upstream edges then
enrich `analyze routes/web.php`). For `File.php::method` targets it prefers
class-like symbols and only falls back to the script when the file has no
class, so a class file that also declares a helper stays unambiguous and
`helpers.php::make_user` resolves.

Function targets (`helpers.php::make_user`) resolve, but because calls to
user-defined functions are not indexed, `analyze`/`impact` report
`risk: UNKNOWN` and `analyze`/`findCallers`/`impact` add a `ParseDiagnostic`
(`Calls to user-defined functions are not indexed; caller and impact results
for <script>::<function> are incomplete.`) so the envelope is `incomplete`.

### Indexer resilience

`CodebaseIndexer::build()` wraps each `parse()` call:

```php
try {
    $parsed = $this->parser->parse($file, $relative);
} catch (\Throwable $failure) {
    $diagnostics[] = new ParseDiagnostic(
        $relative,
        1,
        sprintf('Analysis failed: %s: %s', $failure::class, $failure->getMessage()),
    );
    continue;
}
```

The message deliberately omits the exception's file/line and reduces every
absolute path inside the exception message to its last segment (internal
paths would leak through the MCP HTTP transport). `PhpAstParser` is
unchanged.

## Behaviour changes (documented in CHANGELOG)

- Impact/caller results now list scripts as dependents, so risk levels of
  classes referenced from routes, config, bootstrap or migrations can rise.
- Anonymous class bodies now contribute references (previously skipped).
- `refactor-dependencies`, `refactor-callers`, `refactor-impact` and
  `refactor-analyze` accept root-relative script paths.
- One unreadable/unanalysable file yields a diagnostic instead of an error.

## Testing

- `PhpAstParserTest`: routes file (`Route::` facade calls,
  `[Controller::class, 'index']` class constant, closures), config file with
  `::class`, helpers with `if (!function_exists())`, anonymous-class
  migration (attribution, `self::` unknown, typed-property inference inside),
  anonymous class inside a method (attributed to `A::m`), nested named
  function (no type leakage, attributed to `A::m`), class + procedural code in
  one file (two symbols), pure class file (one symbol, unchanged). The PR #5
  data-provider test asserts "no class-like symbols" instead of "no symbols".
- `CodebaseIndexerTest`: script node `kind`, `findMethod('helpers.php',
  'make_user')`, edges from a script source, analysis-failure diagnostic from
  a throwing `AstParser`, indexing continues for the other files.
- `DefaultRefactoringCapabilitiesTest`: `findCallers` lists
  `routes/web.php`; `analyze routes/web.php` returns upstream dependencies;
  `analyze helpers.php::make_user`; `analyze Foo.php::bar` with top-level code
  in `Foo.php` is not ambiguous; `dependencies routes/web.php`; `impact`
  keeps working with an analysis-failure diagnostic in the envelope.
- Whole suite green; stress parse over the same ~11k real files used for
  PR #5 with warnings turned into exceptions (0 failures) and a before/after
  digest of symbols and references for files without procedural code (0
  differences).

## Documentation

- `REFACTORING_AGENT.md`: new "Procedural code and scripts" subsection,
  updated "Performance and diagnostics" and "Static-analysis limits".
- `CHANGELOG.md`: Adicionado / Alterado / Corrigido entries.
