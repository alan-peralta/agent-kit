---
name: refactor-impact
description: Assess potential change impact and risk for a PHP class or method.
---

## Execution priority

ANALYZE != MODIFY. Do not modify source files.

Before analyzing relationships manually:
1. Discover compatible MCP tools and use the relevant deterministic capability when available.
2. Otherwise run `php artisan agent-kit:refactor-capabilities --json`, then use the Agent Kit CLI command below with `--json`.
3. Use repository search and source/test reading only to complement unresolved context.
4. Use LLM inference only for interpretation, never to invent structural relationships.

Separate the response into FACTS, INTERPRETATION, and RECOMMENDATIONS. Mark dynamic targets that static analysis cannot resolve as UNKNOWN or UNRESOLVED DYNAMIC REFERENCE.

# Analyze Change Impact

Answer: “If I change this, what can potentially be affected?” Run `php artisan agent-kit:refactor-impact "<target>" --json` when an MCP impact capability is unavailable. Inspect relevant tests, jobs, events, and integrations after deterministic analysis.

Return CHANGE IMPACT, Target, Risk, Direct Callers, Structural Dependencies, Transitive Dependents, Affected Files, Affected Modules, Jobs/Events, External Integrations, Relevant Tests, Potential Breakage Scenarios, and Recommended Verification. Say “potentially affected” or “should be verified”; dependency does not prove breakage.
