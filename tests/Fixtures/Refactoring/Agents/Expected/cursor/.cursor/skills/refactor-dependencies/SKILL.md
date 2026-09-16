---
name: refactor-dependencies
description: Analyze upstream and downstream dependencies for a PHP class.
---

## Execution priority

ANALYZE != MODIFY. Do not modify source files.

Before analyzing relationships manually:
1. Discover compatible MCP tools and use the relevant deterministic capability when available.
2. Otherwise run `php artisan agent-kit:refactor-capabilities --json`, then use the Agent Kit CLI command below with `--json`.
3. Use repository search and source/test reading only to complement unresolved context.
4. Use LLM inference only for interpretation, never to invent structural relationships.

Separate the response into FACTS, INTERPRETATION, and RECOMMENDATIONS. Mark dynamic targets that static analysis cannot resolve as UNKNOWN or UNRESOLVED DYNAMIC REFERENCE.

# Analyze Dependencies

Analyze the class supplied with this invocation. Run `php artisan agent-kit:refactor-dependencies "<target>" --json` when an MCP dependency capability is unavailable.

Return UPSTREAM DEPENDENCIES, DOWNSTREAM DEPENDENTS, RELATIONSHIP TYPES, confidence, unresolved references, and dependency paths when available.
