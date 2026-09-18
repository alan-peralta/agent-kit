---
name: refactor-analyze
description: Analyze a PHP file, class, or module for evidence-based refactoring opportunities.
---

## Execution priority

ANALYZE != MODIFY. Do not modify source files.

Before analyzing relationships manually:
1. When the Agent Kit MCP server is connected, discover its MCP tools (`refactoring_capabilities` lists them; the resource `agent-kit://refactoring/capabilities` describes their schemas) and call the read-only tool named below. It accepts the same target grammar as the CLI and operates on the project root the server was started with.
2. Otherwise run `php artisan agent-kit:refactor-capabilities --json`, then use the Agent Kit CLI command below with `--json`.
3. Use repository search and source/test reading only to complement unresolved context.
4. Use LLM inference only for interpretation, never to invent structural relationships.

Separate the response into FACTS, INTERPRETATION, and RECOMMENDATIONS. Mark dynamic targets that static analysis cannot resolve as UNKNOWN or UNRESOLVED DYNAMIC REFERENCE.

# Analyze Refactoring Target

Analyze the target supplied with this invocation. If it is missing, ask for a file, class, or module. Call the `refactoring_analyze` MCP tool with the target when the Agent Kit MCP server is available; otherwise run `php artisan agent-kit:refactor-analyze "<target>" --json`. Then retrieve dependencies, callers, impact when appropriate, source context, and tests.

Return Target, Responsibilities, Metrics, Code Smells, Dependencies, Direct Callers, Transitive Impact, Side Effects, Tests, Refactoring Opportunities, and Risk.
