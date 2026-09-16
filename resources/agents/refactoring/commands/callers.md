# Find Callers

Find callers for the class or `Class::method` supplied with this invocation. Call the `refactoring_callers` MCP tool when the Agent Kit MCP server is available; otherwise run `{{cli_callers}} "<target>" --json`. Do not infer callers solely by reading source code.

Return DIRECT CALLERS, STRUCTURAL DEPENDENCIES, TRANSITIVE DEPENDENTS, and UNRESOLVED/DYNAMIC REFERENCES.
