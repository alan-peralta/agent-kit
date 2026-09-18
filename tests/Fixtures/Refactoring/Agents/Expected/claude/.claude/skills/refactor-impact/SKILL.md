---
name: refactor-impact
description: Assess potential change impact and risk for a PHP class or method.
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

# Analyze Change Impact

Answer: “If I change this, what can potentially be affected?” Call the `refactoring_impact` MCP tool when the Agent Kit MCP server is available; otherwise run `php artisan agent-kit:refactor-impact "<target>" --json`. Inspect relevant tests, jobs, events, and integrations after deterministic analysis.

Return CHANGE IMPACT, Target, Risk, Direct Callers, Structural Dependencies, Transitive Dependents, Affected Files, Affected Modules, Jobs/Events, External Integrations, Relevant Tests, Potential Breakage Scenarios, and Recommended Verification. Say “potentially affected” or “should be verified”; dependency does not prove breakage.
