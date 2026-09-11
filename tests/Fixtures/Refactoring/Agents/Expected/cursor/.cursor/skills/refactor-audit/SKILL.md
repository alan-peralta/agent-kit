---
name: refactor-audit
description: Audit a PHP codebase for refactoring risks without modifying source files.
---

## Execution priority

ANALYZE != MODIFY. Do not modify source files.

Before analyzing relationships manually:
1. Discover compatible MCP tools and use the relevant deterministic capability when available.
2. Otherwise run `php artisan agent-kit:refactor-capabilities --json`, then use the Agent Kit CLI command below with `--json`.
3. Use repository search and source/test reading only to complement unresolved context.
4. Use LLM inference only for interpretation, never to invent structural relationships.

Separate the response into FACTS, INTERPRETATION, and RECOMMENDATIONS. Mark dynamic targets that static analysis cannot resolve as UNKNOWN or UNRESOLVED DYNAMIC REFERENCE.

# Refactoring Audit

Run `php artisan agent-kit:refactor-audit --json` when an MCP audit capability is unavailable. Do not modify source files.

Use dependency and impact analysis for high-risk areas. Never recommend a pattern before identifying the concrete problem.

Return Executive Summary, Architecture Score (label it as deterministic or interpretive), Critical Issues, High Priority Issues, Code Smells, Coupling Risks, High Impact Classes, Testing Risks, and Recommended Roadmap.
