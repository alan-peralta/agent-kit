---
name: refactor-plan
description: Build a small behavior-preserving refactoring plan backed by deterministic analysis.
disable-model-invocation: true
---

## Execution priority

ANALYZE != MODIFY. Do not modify source files.

Before analyzing relationships manually:
1. Discover compatible MCP tools and use the relevant deterministic capability when available.
2. Otherwise run `php artisan agent-kit:refactor-capabilities --json`, then use the Agent Kit CLI command below with `--json`.
3. Use repository search and source/test reading only to complement unresolved context.
4. Use LLM inference only for interpretation, never to invent structural relationships.

Separate the response into FACTS, INTERPRETATION, and RECOMMENDATIONS. Mark dynamic targets that static analysis cannot resolve as UNKNOWN or UNRESOLVED DYNAMIC REFERENCE.

# Build a Refactoring Plan

Do not modify code. For the target supplied with this invocation, complete Analyze -> Callers -> Impact -> Tests -> Plan using MCP first and the documented CLI fallbacks second.

Return REFACTORING PLAN, Goal, Current Problem, Evidence, Affected Components, Risk, Preparation, small isolated numbered steps, Validation after each step, Rollback Considerations, and Definition of Done. Never propose a broad rewrite slogan in place of steps.
