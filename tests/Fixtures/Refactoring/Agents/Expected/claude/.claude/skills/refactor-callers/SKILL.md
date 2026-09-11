---
name: refactor-callers
description: Find direct, structural, transitive, and unresolved callers for a PHP class or method.
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

# Find Callers

Find callers for the class or `Class::method` supplied with this invocation. Run `php artisan agent-kit:refactor-callers "<target>" --json` when an MCP caller capability is unavailable. Do not infer callers solely by reading source code.

Return DIRECT CALLERS, STRUCTURAL DEPENDENCIES, TRANSITIVE DEPENDENTS, and UNRESOLVED/DYNAMIC REFERENCES.
