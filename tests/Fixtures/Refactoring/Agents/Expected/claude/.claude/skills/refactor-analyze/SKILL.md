---
name: refactor-analyze
description: Analyze a PHP file, class, or module for evidence-based refactoring opportunities.
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

# Analyze Refactoring Target

Analyze the target supplied with this invocation. If it is missing, ask for a file, class, or module. Run `php artisan agent-kit:refactor-analyze "<target>" --json` when the equivalent MCP capability is unavailable, then retrieve dependencies, callers, impact when appropriate, source context, and tests.

Return Target, Responsibilities, Metrics, Code Smells, Dependencies, Direct Callers, Transitive Impact, Side Effects, Tests, Refactoring Opportunities, and Risk.
