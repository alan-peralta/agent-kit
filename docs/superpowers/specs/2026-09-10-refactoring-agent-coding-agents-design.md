# Refactoring Agent for Coding Agents — Design

**Date:** 2026-09-10

**Status:** Approved for implementation planning

**Scope:** Expose the existing deterministic Refactoring Agent consistently through Artisan, Cursor, Claude Code, and a future MCP adapter.

## 1. Context

Agent Kit already contains a deterministic refactoring core built around PHP AST parsing, a codebase index, a typed dependency graph, caller analysis, impact analysis, file metrics, audit reporting, and Artisan commands. The current Artisan commands still orchestrate some of those components directly, and the package does not yet provide installable coding-agent workflows.

This change turns those capabilities into a stable application boundary and adds native Cursor and Claude Code integrations. It does not add automated refactoring or an MCP server.

The governing rule is:

> DETERMINISTIC ANALYSIS FIRST. LLM REASONING SECOND.

The deterministic layer establishes structural facts. Coding agents explain, contextualize, prioritize, and recommend based on those facts.

## 2. Goals

- Provide one application API for audit, analysis, callers, dependencies, impact, and capability discovery.
- Make Artisan a thin adapter over that API.
- Install invocable refactoring workflows for Cursor and Claude Code.
- Keep the semantic workflow in one canonical template source.
- Prefer MCP, then Agent Kit CLI, then repository inspection, then LLM inference.
- Produce stable machine-readable output for agent consumption and future MCP exposure.
- Protect existing consumer configuration during installation.
- Preserve analysis-only behavior in every installed workflow.

## 3. Non-goals

- Implement an MCP server or MCP transport.
- Implement `/refactor-apply` or automatic source modifications.
- Guarantee runtime resolution of dynamic PHP calls.
- Replace the existing AST, index, graph, metrics, or analyzers.
- Refactor unrelated Agent Kit subsystems.
- Maintain literal colon command names. Portable command names use hyphens.

## 4. Supported agent formats

The implementation uses the current native Agent Skills formats:

- Cursor project skills: `.cursor/skills/<skill-name>/SKILL.md`
- Cursor persistent rules: `.cursor/rules/*.mdc`
- Claude Code project skills: `.claude/skills/<skill-name>/SKILL.md`
- Claude Code persistent rules: `.claude/rules/*.md`

The decision follows the current official documentation:

- Cursor Agent Skills: <https://cursor.com/docs/skills>
- Cursor project rules: <https://docs.cursor.com/context/rules>
- Claude Code skills: <https://code.claude.com/docs/en/slash-commands>
- Claude Code project rules: <https://code.claude.com/docs/en/memory>

Legacy `.claude/commands` files are not generated. Cursor may load Claude skills for compatibility, but the installer still generates native files for both platforms so each integration remains explicit and independently testable.

## 5. User-facing command names

The installed skills expose:

- `/refactor-audit`
- `/refactor-analyze`
- `/refactor-callers`
- `/refactor-dependencies`
- `/refactor-impact`
- `/refactor-plan`

The target follows the invocation as normal prompt input. Examples:

```text
/refactor-impact App\Services\PaymentService
/refactor-callers App\Services\PaymentService::charge
/refactor-analyze app/Services/PaymentService.php
```

`/refactor-apply` is reserved for a future implementation and is not installed.

## 6. Architecture

```text
                         Refactoring Core
          AST / Index / Graph / Metrics / Existing Analyzers
                                  |
                                  v
                    RefactoringCapabilities
          audit / analyze / callers / dependencies / impact
                         / capability discovery
                                  |
             +--------------------+--------------------+
             |                    |                    |
             v                    v                    v
        Artisan CLI          Coding Agents        Future MCP
                         Cursor / Claude Code
```

### 6.1 Application boundary

A `RefactoringCapabilities` contract becomes the stable entry point for all consumers. Its default implementation composes the existing `ProjectScanner`, `CodebaseIndexer`, `CallerAnalyzer`, `ImpactAnalyzer`, graph, report builder, and target resolution logic.

The boundary exposes operations equivalent to:

```text
describeCapabilities()
audit(projectRoot)
analyze(projectRoot, target)
findCallers(projectRoot, target, optionalMethod)
dependencies(projectRoot, target)
impact(projectRoot, target, optionalMethod)
```

The exact PHP signatures and DTO types are chosen during implementation planning, but each operation returns a serializable result object with a stable `toArray()` representation.

The application layer does not know about Console output, Markdown agent files, Cursor, Claude Code, or MCP. It also does not write audit reports. This keeps it usable by a future in-process MCP adapter.

### 6.2 Artisan adapter

Artisan commands validate command-line input, call `RefactoringCapabilities`, and render human or JSON output. They do not assemble AST indexes or invoke low-level analyzers directly.

Existing command names remain stable:

```text
agent-kit:refactor-audit
agent-kit:refactor-analyze
agent-kit:refactor-callers
agent-kit:refactor-dependencies
agent-kit:refactor-impact
```

The following command is added:

```text
agent-kit:refactor-capabilities
```

Audit report persistence remains a CLI concern. `agent-kit:refactor-audit` receives data from the application layer and writes `audit.json`, `audit.md`, and optionally `baseline.json` as it does today.

### 6.3 Future MCP adapter

No MCP server is implemented in this scope. The future adapter will translate MCP inputs into calls to `RefactoringCapabilities` and serialize the same result objects used by Artisan. MCP must not call Artisan or parse terminal output.

## 7. Capability behavior

### 7.1 Capability discovery

`describeCapabilities()` returns stable descriptors for the available operations, their accepted target forms, JSON support, CLI fallback command, and schema version. The CLI exposes this through:

```bash
php artisan agent-kit:refactor-capabilities --json
```

Installed skills first inspect the agent's available MCP tools for the needed capability. If none exists, they use this CLI discovery command. They do not infer that a tool exists merely because instructions mention it.

### 7.2 Target resolution

Analysis commands accept:

- a project-relative or absolute PHP file path;
- a fully qualified class name;
- a fully qualified class and method in `Class::method` form where the operation supports method scope.

Target resolution uses the codebase index for FQCN lookup. Missing or ambiguous targets produce structured errors rather than silent fallback guesses.

### 7.3 Audit

Audit scans the project and returns deterministic file metrics, threshold-based smells, issue counts, and diagnostics. The agent interprets these facts into architectural risks and a prioritized roadmap.

An architecture score, when presented by an agent and not supplied by the core, must be labeled as interpretation rather than deterministic fact.

### 7.4 Analyze

Analyze resolves a path, class, or module and combines:

- file metrics and smells;
- responsibilities visible from symbols and references;
- upstream dependencies;
- direct callers and structural dependents;
- transitive impact where applicable;
- relevant diagnostics.

Relevant tests and business context may be inspected by the coding agent after deterministic analysis. They are reported separately from structural facts when derived through repository search.

### 7.5 Find callers

Caller analysis returns:

- direct method callers;
- structural dependents;
- transitive dependents with paths;
- unresolved or dynamic references;
- parse diagnostics.

Method scope narrows direct calls when a method is supplied. Class-level structural relationships remain visible.

### 7.6 Dependencies

Dependency analysis returns both directions:

- upstream dependencies: what the target depends on;
- downstream dependents: what depends on the target;
- relationship types and confidence;
- available dependency paths;
- unresolved or dynamic references.

Relationship types continue to come from the typed graph, including constructor injection, method calls, static calls, inheritance, interfaces, traits, events, jobs, attributes, and other types supported by the existing parser.

### 7.7 Impact

Impact answers what is potentially affected by a proposed change. It supports class and optional method scope and returns:

- risk level: `LOW`, `MEDIUM`, `HIGH`, or `CRITICAL`;
- direct callers;
- structural dependents;
- transitive dependents and graph paths;
- affected files and modules when known;
- jobs, events, and external integration relationships when detected;
- diagnostics and unresolved references.

Dependency never proves breakage. Agent-facing instructions require terms such as “potentially affected” and “should be verified.”

## 8. Machine-readable output

All structural Artisan commands support `--json`, including audit and analyze. Success payloads contain:

```json
{
  "schema_version": "1.0",
  "data": {}
}
```

Errors use a non-zero exit code and a predictable payload:

```json
{
  "schema_version": "1.0",
  "error": {
    "code": "TARGET_NOT_FOUND",
    "message": "The requested target was not found in the codebase index."
  }
}
```

Partial static analysis remains a successful result when useful data exists. Such results include:

```json
{
  "incomplete": true,
  "diagnostics": [],
  "unresolved": []
}
```

The implementation must not invent relationships to fill these arrays.

## 9. Shared agent content

Canonical source files live under:

```text
resources/agents/refactoring/
├── commands/
│   ├── audit.md
│   ├── analyze.md
│   ├── callers.md
│   ├── dependencies.md
│   ├── impact.md
│   └── plan.md
├── rules/
│   ├── core.md
│   ├── laravel.md
│   ├── smells.md
│   └── patterns.md
└── instructions.md
```

These files contain semantic content only. Platform-specific frontmatter and output paths belong to adapters.

### 9.1 Shared priority

Every workflow follows this priority:

1. Compatible MCP tool.
2. Native Agent Kit Artisan command with `--json`.
3. Repository search and source/test reading for unresolved context.
4. LLM inference for interpretation only.

If MCP is unavailable, CLI is the normal deterministic path, not an error condition.

### 9.2 Shared rules

All integrations include these rules:

- `ANALYZE != MODIFY`
- `A DESIGN PATTERN IS NOT A GOAL`
- Preserve behavior.
- Prefer small changes.
- Search callers before moving public methods.
- Search events and jobs before changing side effects.
- Check tests before recommending a refactor.
- Never assume a class is isolated.
- Prefer evidence over speculation.
- Mark unresolved dynamic behavior explicitly.

Laravel-aware instructions cover controllers, form requests, services, actions, models, jobs, events, listeners, observers, policies, commands, providers, facades, Eloquent relationships, container bindings, gateways, external integrations, scheduled commands, queued listeners, and tests.

### 9.3 Output discipline

Agent output separates:

```text
FACTS
Deterministic Agent Kit output and explicitly sourced repository observations.

INTERPRETATION
Reasoning based on those facts.

RECOMMENDATIONS
Proposed actions, safeguards, and validation.
```

Statements derived from repository reading rather than Agent Kit output identify that source. Unknown runtime targets use `UNKNOWN` or `UNRESOLVED DYNAMIC REFERENCE` and explain that static analysis cannot determine the runtime target.

## 10. Command workflows

### 10.1 `/refactor-audit`

Runs capability discovery and deterministic audit, optionally uses dependency and impact capabilities for hotspots, reads the generated facts, then returns:

- Executive Summary
- Architecture Score, clearly labeled as deterministic or interpretive
- Critical Issues
- High Priority Issues
- Code Smells
- Coupling Risks
- High Impact Classes
- Testing Risks
- Recommended Roadmap

It never modifies source files and never recommends a pattern without first identifying a concrete problem.

### 10.2 `/refactor-analyze`

Runs target resolution, structural analysis, dependencies, callers, impact when appropriate, and then inspects relevant source and tests. It returns target, responsibilities, metrics, smells, dependencies, direct callers, transitive impact, side effects, tests, opportunities, and risk.

### 10.3 `/refactor-callers`

Uses deterministic caller analysis and returns direct callers, structural dependencies, transitive dependents, and unresolved dynamic references. It does not infer callers from source reading alone.

### 10.4 `/refactor-dependencies`

Returns upstream dependencies, downstream dependents, relationship types, confidence, unresolved references, and paths when available.

### 10.5 `/refactor-impact`

Combines callers, typed graph, transitive dependents, jobs/events, integrations, and relevant tests. It returns risk, potentially affected components, potential breakage scenarios, and recommended verification.

### 10.6 `/refactor-plan`

Runs the mandatory sequence:

```text
Analyze -> Callers -> Impact -> Tests -> Plan
```

It does not modify code. Steps remain small and conceptually isolated and include evidence, preparation, validation after each step, rollback considerations, and definition of done.

## 11. Agent adapters

The generation layer contains:

- `AgentCommandRepository`: loads canonical command and rule content.
- `AgentTemplateRenderer`: resolves controlled placeholders and rejects unresolved placeholders.
- `AgentAdapter`: defines adapter identity and generated files.
- `CursorAgentAdapter`: supplies Cursor frontmatter, `.mdc` rules, and `.cursor` destinations.
- `ClaudeCodeAgentAdapter`: supplies Claude Code frontmatter and `.claude` destinations.
- `AgentConfigurationInstaller`: compares and installs generated files safely.

Adapters may vary frontmatter, file extension, and destination only. They must not maintain divergent semantic command bodies.

## 12. Installer

The installer command is:

```bash
php artisan agent-kit:agents:install cursor
php artisan agent-kit:agents:install claude
php artisan agent-kit:agents:install cursor claude
php artisan agent-kit:agents:install --all
```

When invoked without agent arguments in an interactive terminal, it offers a multi-selection prompt. In non-interactive mode, it requires explicit agents or `--all`.

An optional project path may be supplied for monorepos, automation, and tests; otherwise the Laravel base path is used.

For each destination:

- missing file: create it;
- identical file: report `unchanged`;
- different file: report `conflict`, do not write it, and return failure;
- different file with explicit `--force`: overwrite only the Agent Kit-dedicated destination and report `overwritten`.

The installer never edits `AGENTS.md`, `CLAUDE.md`, `.cursorrules`, or another generic user-owned file. Persistent project guidance is installed in dedicated rule files. Writes are atomic, parent directories are created as needed, unknown adapters are rejected, and the summary lists `created`, `unchanged`, `conflicts`, and `overwritten` paths.

## 13. Error handling

Application failures use specific exceptions or error codes for invalid roots, missing targets, ambiguous targets, unsupported target forms, unavailable capabilities, template errors, and installation conflicts.

Console adapters translate them into readable errors or the JSON error envelope. Templates instruct agents to report deterministic failures instead of silently pretending that manual inference is equivalent.

Parse failures and dynamic PHP behavior are diagnostics, not fabricated edges. Useful partial results remain available and are labeled incomplete.

## 14. Testing strategy

Implementation follows test-driven development. Coverage includes:

- `RefactoringCapabilities` contract and default implementation;
- CLI delegation to the capability layer;
- JSON success and error envelopes;
- audit and analyze `--json` support;
- path, FQCN, and `Class::method` resolution;
- upstream and downstream dependencies;
- method-aware callers and impact;
- transitive paths, diagnostics, and unresolved behavior;
- capability discovery descriptors;
- shared command repository and placeholder validation;
- Cursor generation;
- Claude Code generation;
- golden fixtures for every generated file;
- proof that adapters use the same canonical semantic bodies;
- MCP-first and CLI-fallback instructions;
- shared rules and Laravel awareness;
- installer creation, idempotency, conflict protection, explicit force, invalid adapters, and summaries;
- service-provider registration;
- absence of source-modification instructions in every installed skill;
- the complete existing test suite.

Golden fixtures are preferred over adding a snapshot-testing dependency because the repository does not currently use a snapshot framework.

## 15. Documentation

`README.md` and `REFACTORING_AGENT.md` gain a “Using Refactoring Agent with Coding Agents” section covering:

- Cursor installation and usage;
- Claude Code installation and usage;
- all portable slash-command names;
- MCP-first and CLI-fallback behavior;
- direct Artisan examples with `--json`;
- output separation between facts, interpretation, and recommendations;
- the Core -> CLI/Coding Agents/MCP architecture;
- safe installer behavior;
- current limitations;
- MCP exposure as the next step.

## 16. Compatibility and migration

- Existing Artisan command names and human-readable behavior remain available.
- Existing callers, dependencies, and impact JSON data are wrapped in the versioned envelope consistently; documentation calls out the schema.
- No legacy Cursor or Claude command directory is removed or modified.
- Generated command names use hyphens because current Agent Skill identifiers allow lowercase letters, numbers, and hyphens, not colon namespaces for project skills.
- Consumer-owned configuration is not merged automatically.

## 17. Definition of done

The work is complete when:

1. Cursor receives six native refactoring skills and a persistent Agent Kit rule.
2. Claude Code receives six equivalent native skills and a persistent Agent Kit rule.
3. Both adapters render from shared semantic sources.
4. Artisan delegates deterministic work through `RefactoringCapabilities`.
5. Audit, analyze, callers, dependencies, impact, and discovery provide stable JSON.
6. MCP is represented as a future adapter over the same application boundary.
7. Installed workflows enforce deterministic-first priority and `ANALYZE != MODIFY`.
8. Dynamic behavior is explicitly unresolved rather than invented.
9. Installation preserves differing existing files unless `--force` is explicit.
10. Tests and static validation pass.
11. User documentation is current.

## 18. Known limitations

- Static PHP analysis cannot resolve every container lookup, dynamic class name, dynamic method, macro, reflection path, or runtime configuration.
- Agent Skills orchestrate tools available in the host; they do not make MCP available by themselves.
- Separate CLI invocations may rebuild the in-memory codebase index until persistent indexing or MCP session reuse is implemented.
- Architecture scoring that is not produced by the deterministic core remains an explicitly labeled LLM interpretation.
- `/refactor-apply` remains disabled.

## 19. Next step

After this capability and adapter layer is stable, implement an MCP server that exposes the same `RefactoringCapabilities` operations without routing through Artisan.
