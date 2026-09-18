# Analyze Refactoring Target

Analyze the target supplied with this invocation. If it is missing, ask for a file, class, or module. Call the `refactoring_analyze` MCP tool with the target when the Agent Kit MCP server is available; otherwise run `{{cli_analyze}} "<target>" --json`. Then retrieve dependencies, callers, impact when appropriate, source context, and tests.

Return Target, Responsibilities, Metrics, Code Smells, Dependencies, Direct Callers, Transitive Impact, Side Effects, Tests, Refactoring Opportunities, and Risk.
