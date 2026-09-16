# Analyze Change Impact

Answer: “If I change this, what can potentially be affected?” Run `{{cli_impact}} "<target>" --json` when an MCP impact capability is unavailable. Inspect relevant tests, jobs, events, and integrations after deterministic analysis.

Return CHANGE IMPACT, Target, Risk, Direct Callers, Structural Dependencies, Transitive Dependents, Affected Files, Affected Modules, Jobs/Events, External Integrations, Relevant Tests, Potential Breakage Scenarios, and Recommended Verification. Say “potentially affected” or “should be verified”; dependency does not prove breakage.
