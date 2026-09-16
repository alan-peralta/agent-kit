<?php

namespace Peralta\AgentKit\Refactoring\Mcp;

use InvalidArgumentException;
use Mcp\Schema\ResourceDefinition;

final class RefactoringToolCatalog
{
    public const RESOURCE_URI = 'agent-kit://refactoring/capabilities';
    public const RESOURCE_NAME = 'refactoring_capabilities';

    private const TARGET_DESCRIPTION = 'Project-relative or absolute in-project PHP file (analyze only), fully qualified class name, or Class::method where the capability supports method scope. The path is resolved inside the fixed project root of this server.';

    private const NO_ARGUMENTS = ['type' => 'object', 'properties' => [], 'additionalProperties' => false];

    private const TARGET_ARGUMENT = [
        'type' => 'object',
        'properties' => [
            'target' => ['type' => 'string', 'minLength' => 1, 'description' => self::TARGET_DESCRIPTION],
        ],
        'required' => ['target'],
        'additionalProperties' => false,
    ];

    private const EDGE = [
        'type' => 'object',
        'properties' => [
            'source' => ['type' => 'string'],
            'source_method' => ['type' => ['string', 'null']],
            'target' => ['type' => ['string', 'null']],
            'target_method' => ['type' => ['string', 'null']],
            'type' => ['type' => 'string'],
            'confidence' => ['type' => 'string', 'enum' => ['exact', 'inferred', 'unknown']],
            'file' => ['type' => 'string'],
            'line' => ['type' => 'integer'],
            'metadata' => ['type' => ['object', 'array']],
        ],
        'required' => ['source', 'target', 'type', 'confidence', 'file', 'line'],
    ];

    private const EDGES = ['type' => 'array', 'items' => self::EDGE];

    private const TRANSITIVE = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => [
                'fqcn' => ['type' => 'string'],
                'file' => ['type' => 'string'],
                'depth' => ['type' => 'integer'],
                'path' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['fqcn', 'file', 'depth', 'path'],
        ],
    ];

    private const DIAGNOSTIC = [
        'type' => 'object',
        'properties' => [
            'file' => ['type' => 'string'],
            'line' => ['type' => ['integer', 'null']],
            'message' => ['type' => 'string'],
        ],
        'required' => ['file', 'message'],
    ];

    private const METRICS = [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string'],
            'lines' => ['type' => 'integer'],
            'methods' => ['type' => 'integer'],
            'dependencies' => ['type' => 'integer'],
            'branches' => ['type' => 'integer'],
            'smells' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'severity' => ['type' => 'string'],
                    'reason' => ['type' => 'string'],
                ],
                'required' => ['name', 'severity', 'reason'],
            ]],
        ],
        'required' => ['path', 'lines', 'methods', 'dependencies', 'branches', 'smells'],
    ];

    /** @var list<ToolDefinition>|null */
    private ?array $tools = null;

    /** @return list<ToolDefinition> */
    public function tools(): array
    {
        return $this->tools ??= [
            new ToolDefinition(
                'refactoring_capabilities',
                'capability_discovery',
                'Refactoring capabilities',
                'Describe the deterministic Agent Kit refactoring capabilities served by this MCP server: capability names, accepted targets, the MCP tool and the JSON CLI fallback for each. Read-only.',
                self::NO_ARGUMENTS,
                self::outputSchema('capability_discovery', [
                    'capabilities' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'targets' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'mcp_tool' => ['type' => 'string'],
                            'cli_fallback' => ['type' => 'string'],
                            'json' => ['type' => 'boolean'],
                        ],
                        'required' => ['name', 'targets', 'mcp_tool', 'cli_fallback', 'json'],
                    ]],
                ], ['capabilities']),
            ),
            new ToolDefinition(
                'refactoring_audit',
                'audit',
                'Refactoring audit',
                'Audit the whole project root of this server for deterministic refactoring signals (file metrics, threshold-based smells, issue counts). Read-only; writes no report files.',
                self::NO_ARGUMENTS,
                self::outputSchema('audit', [
                    'generated_at' => ['type' => 'string'],
                    'project_root' => ['type' => 'string'],
                    'stack' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'summary' => [
                        'type' => 'object',
                        'properties' => [
                            'php_files' => ['type' => 'integer'],
                            'lines' => ['type' => 'integer'],
                            'issues' => ['type' => 'object', 'properties' => [
                                'high' => ['type' => 'integer'],
                                'medium' => ['type' => 'integer'],
                                'low' => ['type' => 'integer'],
                            ], 'required' => ['high', 'medium', 'low']],
                            'smells' => ['type' => ['object', 'array']],
                        ],
                        'required' => ['php_files', 'lines', 'issues', 'smells'],
                    ],
                    'files' => ['type' => 'array', 'items' => self::METRICS],
                ], ['generated_at', 'project_root', 'stack', 'summary', 'files']),
            ),
            new ToolDefinition(
                'refactoring_analyze',
                'analyze',
                'Analyze refactoring target',
                'Analyze one PHP file, class or Class::method: metrics, smells, upstream dependencies, direct callers, structural dependents, transitive impact and risk. Read-only.',
                self::TARGET_ARGUMENT,
                self::outputSchema('analyze', [
                    'target' => ['type' => 'string'],
                    'method' => ['type' => ['string', 'null']],
                    'metrics' => self::METRICS,
                    'upstream_dependencies' => self::EDGES,
                    'direct_callers' => self::EDGES,
                    'structural_dependencies' => self::EDGES,
                    'transitive_impact' => self::TRANSITIVE,
                    'risk' => ['type' => 'string'],
                ], ['target', 'method', 'metrics', 'upstream_dependencies', 'direct_callers', 'structural_dependencies', 'transitive_impact', 'risk']),
            ),
            new ToolDefinition(
                'refactoring_callers',
                'find_callers',
                'Find callers',
                'Find direct callers, structural dependents and transitive dependents of a class or Class::method. Unresolved dynamic references are reported separately. Read-only.',
                self::TARGET_ARGUMENT,
                self::outputSchema('find_callers', [
                    'target' => ['type' => 'string'],
                    'method' => ['type' => ['string', 'null']],
                    'direct_callers' => self::EDGES,
                    'structural_dependencies' => self::EDGES,
                    'transitive_dependents' => self::TRANSITIVE,
                    'unresolved_scope' => ['type' => 'string'],
                ], ['target', 'method', 'direct_callers', 'structural_dependencies', 'transitive_dependents', 'unresolved_scope']),
            ),
            new ToolDefinition(
                'refactoring_dependencies',
                'dependencies',
                'Analyze dependencies',
                'List typed upstream dependencies, downstream dependents and transitive dependents of a class. Read-only.',
                self::TARGET_ARGUMENT,
                self::outputSchema('dependencies', [
                    'target' => ['type' => 'string'],
                    'upstream_dependencies' => self::EDGES,
                    'downstream_dependents' => self::EDGES,
                    'transitive_dependents' => self::TRANSITIVE,
                ], ['target', 'upstream_dependencies', 'downstream_dependents', 'transitive_dependents']),
            ),
            new ToolDefinition(
                'refactoring_impact',
                'impact',
                'Analyze change impact',
                'Estimate what is potentially affected by changing a class or Class::method: dependent counts, risk level, affected files and the underlying direct, structural and transitive records. Read-only.',
                self::TARGET_ARGUMENT,
                self::outputSchema('impact', [
                    'target' => ['type' => 'string'],
                    'method' => ['type' => ['string', 'null']],
                    'direct_callers' => ['type' => 'integer'],
                    'structural_dependencies' => ['type' => 'integer'],
                    'transitive_dependents' => ['type' => 'integer'],
                    'affected_files' => ['type' => 'integer'],
                    'risk' => ['type' => 'string'],
                    'direct' => self::EDGES,
                    'structural' => self::EDGES,
                    'transitive' => self::TRANSITIVE,
                ], ['target', 'method', 'direct_callers', 'structural_dependencies', 'transitive_dependents', 'affected_files', 'risk', 'direct', 'structural', 'transitive']),
            ),
        ];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (ToolDefinition $definition): string => $definition->name, $this->tools());
    }

    public function tool(string $name): ToolDefinition
    {
        foreach ($this->tools() as $definition) {
            if ($definition->name === $name) {
                return $definition;
            }
        }

        throw new InvalidArgumentException("Unknown refactoring MCP tool: {$name}");
    }

    public function resourceDefinition(): ResourceDefinition
    {
        return new ResourceDefinition(
            uri: self::RESOURCE_URI,
            name: self::RESOURCE_NAME,
            title: 'Refactoring capabilities',
            description: 'Versioned description of the refactoring tools served by this Agent Kit MCP server: arguments, result envelopes, limitations and the absence of any apply/mutation capability.',
            mimeType: 'application/json',
        );
    }

    /**
     * @param array<string, array<string, mixed>> $dataProperties
     * @param list<string> $requiredData
     */
    private static function outputSchema(string $capability, array $dataProperties, array $requiredData): array
    {
        return [
            'type' => 'object',
            'oneOf' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'schema_version' => ['type' => 'string'],
                        'capability' => ['const' => $capability],
                        'incomplete' => ['type' => 'boolean'],
                        'data' => [
                            'type' => 'object',
                            'properties' => $dataProperties,
                            'required' => $requiredData,
                            'additionalProperties' => true,
                        ],
                        'diagnostics' => ['type' => 'array', 'items' => self::DIAGNOSTIC],
                        'unresolved' => ['type' => 'array', 'items' => self::EDGE],
                    ],
                    'required' => ['schema_version', 'capability', 'incomplete', 'data', 'diagnostics', 'unresolved'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'schema_version' => ['type' => 'string'],
                        'error' => [
                            'type' => 'object',
                            'properties' => [
                                'code' => ['type' => 'string'],
                                'message' => ['type' => 'string'],
                            ],
                            'required' => ['code', 'message'],
                        ],
                    ],
                    'required' => ['schema_version', 'error'],
                ],
            ],
        ];
    }
}
