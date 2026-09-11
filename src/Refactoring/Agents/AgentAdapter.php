<?php

namespace Peralta\AgentKit\Refactoring\Agents;

interface AgentAdapter
{
    /** @var array<string, string> */
    public const CLI_VALUES = [
        'cli_audit' => 'php artisan agent-kit:refactor-audit',
        'cli_analyze' => 'php artisan agent-kit:refactor-analyze',
        'cli_callers' => 'php artisan agent-kit:refactor-callers',
        'cli_dependencies' => 'php artisan agent-kit:refactor-dependencies',
        'cli_impact' => 'php artisan agent-kit:refactor-impact',
    ];

    /** @var array<string, string> */
    public const DESCRIPTIONS = [
        'audit' => 'Audit a PHP codebase for refactoring risks without modifying source files.',
        'analyze' => 'Analyze a PHP file, class, or module for evidence-based refactoring opportunities.',
        'callers' => 'Find direct, structural, transitive, and unresolved callers for a PHP class or method.',
        'dependencies' => 'Analyze upstream and downstream dependencies for a PHP class.',
        'impact' => 'Assess potential change impact and risk for a PHP class or method.',
        'plan' => 'Build a small behavior-preserving refactoring plan backed by deterministic analysis.',
    ];

    public function id(): string;

    /** @return list<GeneratedAgentFile> */
    public function generate(AgentCommandRepository $repository, AgentTemplateRenderer $renderer): array;
}
