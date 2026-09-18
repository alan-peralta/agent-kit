# Refactoring Audit

Call the `refactoring_audit` MCP tool when the Agent Kit MCP server is available; otherwise run `{{cli_audit}} --json`. Do not modify source files.

Use dependency and impact analysis for high-risk areas. Never recommend a pattern before identifying the concrete problem.

Return Executive Summary, Architecture Score (label it as deterministic or interpretive), Critical Issues, High Priority Issues, Code Smells, Coupling Risks, High Impact Classes, Testing Risks, and Recommended Roadmap.
