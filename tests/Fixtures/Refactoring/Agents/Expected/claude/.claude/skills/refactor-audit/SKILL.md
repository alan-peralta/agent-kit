---
name: refactor-audit
description: Audit a PHP codebase for refactoring risks without modifying source files.
disable-model-invocation: true
---

## Execution priority

ANALYZE != MODIFY. Do not modify source files.

Before analyzing relationships manually:
1. When the Agent Kit MCP server is connected, discover its MCP tools (`refactoring_capabilities` lists them; the resource `agent-kit://refactoring/capabilities` describes their schemas) and call the read-only tool named below. It accepts the same target grammar as the CLI and operates on the project root the server was started with.
2. Otherwise run `php artisan agent-kit:refactor-capabilities --json`, then use the Agent Kit CLI command below with `--json`.
3. Use repository search and source/test reading only to complement unresolved context.
4. Use LLM inference only for interpretation, never to invent structural relationships.

Separate the response into FACTS, INTERPRETATION, and RECOMMENDATIONS. Mark dynamic targets that static analysis cannot resolve as UNKNOWN or UNRESOLVED DYNAMIC REFERENCE.

# Refactoring Audit

Call the `refactoring_audit` MCP tool when the Agent Kit MCP server is available; otherwise run `php artisan agent-kit:refactor-audit --json`. Do not modify source files.

Use dependency and impact analysis for high-risk areas. Never recommend a pattern before identifying the concrete problem.

Return Executive Summary, Architecture Score (label it as deterministic or interpretive), Critical Issues, High Priority Issues, Code Smells, Coupling Risks, High Impact Classes, Testing Risks, and Recommended Roadmap.
