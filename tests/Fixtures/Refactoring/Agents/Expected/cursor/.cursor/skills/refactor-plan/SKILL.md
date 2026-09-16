---
name: refactor-plan
description: Build a small behavior-preserving refactoring plan backed by deterministic analysis.
---

## Execution priority

ANALYZE != MODIFY. Do not modify source files.

Before analyzing relationships manually:
1. When the Agent Kit MCP server is connected, discover its MCP tools (`refactoring_capabilities` lists them; the resource `agent-kit://refactoring/capabilities` describes their schemas) and call the read-only tool named below. It accepts the same target grammar as the CLI and operates on the project root the server was started with.
2. Otherwise run `php artisan agent-kit:refactor-capabilities --json`, then use the Agent Kit CLI command below with `--json`.
3. Use repository search and source/test reading only to complement unresolved context.
4. Use LLM inference only for interpretation, never to invent structural relationships.

Separate the response into FACTS, INTERPRETATION, and RECOMMENDATIONS. Mark dynamic targets that static analysis cannot resolve as UNKNOWN or UNRESOLVED DYNAMIC REFERENCE.

# Build a Refactoring Plan

Do not modify code. For the target supplied with this invocation, complete Analyze -> Callers -> Impact -> Tests -> Plan using the `refactoring_analyze`, `refactoring_callers` and `refactoring_impact` MCP tools first and the documented CLI fallbacks second.

Return REFACTORING PLAN, Goal, Current Problem, Evidence, Affected Components, Risk, Preparation, small isolated numbered steps, Validation after each step, Rollback Considerations, and Definition of Done. Never propose a broad rewrite slogan in place of steps.
