<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp;

use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\Tool;
use Peralta\AgentKit\Refactoring\Analysis\Ast\PhpAstParser;
use Peralta\AgentKit\Refactoring\Analysis\CallerAnalyzer;
use Peralta\AgentKit\Refactoring\Analysis\ImpactAnalyzer;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexer;
use Peralta\AgentKit\Refactoring\Application\DefaultRefactoringCapabilities;
use Peralta\AgentKit\Refactoring\Mcp\RefactoringToolCatalog;
use Peralta\AgentKit\Refactoring\Support\PhpFileAnalyzer;
use Peralta\AgentKit\Refactoring\Support\ProjectScanner;
use Peralta\AgentKit\Refactoring\Support\RefactoringReport;
use PHPUnit\Framework\TestCase;

final class RefactoringToolCatalogTest extends TestCase
{
    public function test_it_exposes_exactly_the_six_read_only_tools_in_order(): void
    {
        self::assertSame([
            'refactoring_capabilities',
            'refactoring_audit',
            'refactoring_analyze',
            'refactoring_callers',
            'refactoring_dependencies',
            'refactoring_impact',
        ], (new RefactoringToolCatalog())->names());
    }

    public function test_no_tool_can_apply_write_or_execute_anything(): void
    {
        foreach ((new RefactoringToolCatalog())->tools() as $definition) {
            foreach (['apply', 'write', 'edit', 'mutate', 'delete', 'exec', 'shell', 'refresh'] as $verb) {
                self::assertStringNotContainsString($verb, $definition->name);
            }
            $tool = $definition->toTool();
            self::assertTrue($tool->annotations?->readOnlyHint);
            self::assertFalse($tool->annotations?->destructiveHint);
            self::assertTrue($tool->annotations?->idempotentHint);
            self::assertFalse($tool->annotations?->openWorldHint);
        }
    }

    public function test_target_tools_require_a_non_empty_target_and_reject_unknown_arguments(): void
    {
        $catalog = new RefactoringToolCatalog();

        foreach (['refactoring_analyze', 'refactoring_callers', 'refactoring_dependencies', 'refactoring_impact'] as $name) {
            $schema = $catalog->tool($name)->inputSchema;
            self::assertSame('object', $schema['type'], $name);
            self::assertSame(['target'], $schema['required'], $name);
            self::assertSame('string', $schema['properties']['target']['type'], $name);
            self::assertSame(1, $schema['properties']['target']['minLength'], $name);
            self::assertFalse($schema['additionalProperties'], $name);
            self::assertTrue($catalog->tool($name)->requiresTarget(), $name);
        }

        foreach (['refactoring_capabilities', 'refactoring_audit'] as $name) {
            $schema = $catalog->tool($name)->inputSchema;
            self::assertSame(['type' => 'object', 'properties' => [], 'additionalProperties' => false], $schema, $name);
            self::assertFalse($catalog->tool($name)->requiresTarget(), $name);
        }
    }

    public function test_every_output_schema_accepts_the_success_and_the_error_envelope(): void
    {
        foreach ((new RefactoringToolCatalog())->tools() as $definition) {
            $schema = $definition->outputSchema;
            self::assertSame('object', $schema['type'], $definition->name);
            self::assertCount(2, $schema['oneOf'], $definition->name);
            [$success, $error] = $schema['oneOf'];
            self::assertSame(
                ['schema_version', 'capability', 'incomplete', 'data', 'diagnostics', 'unresolved'],
                $success['required'],
                $definition->name,
            );
            self::assertSame($definition->capability, $success['properties']['capability']['const'], $definition->name);
            self::assertSame(['schema_version', 'error'], $error['required'], $definition->name);
            self::assertSame(['code', 'message'], $error['properties']['error']['required'], $definition->name);
        }
    }

    public function test_tool_definitions_serialize_with_schemas_and_annotations(): void
    {
        $tool = (new RefactoringToolCatalog())->tool('refactoring_impact')->toTool();
        $serialized = $tool->jsonSerialize();

        self::assertInstanceOf(Tool::class, $tool);
        self::assertSame('refactoring_impact', $serialized['name']);
        self::assertArrayHasKey('inputSchema', $serialized);
        self::assertArrayHasKey('outputSchema', $serialized);
        self::assertArrayHasKey('annotations', $serialized);
        self::assertNotSame('', $serialized['description']);
    }

    public function test_tools_map_one_to_one_onto_the_capability_descriptors(): void
    {
        $catalog = new RefactoringToolCatalog();
        $descriptors = $this->capabilities()->describeCapabilities()->data['capabilities'];

        foreach ($descriptors as $descriptor) {
            self::assertSame($descriptor['name'], $catalog->tool($descriptor['mcp_tool'])->capability, $descriptor['name']);
        }

        $catalogCapabilities = array_values(array_filter(
            array_map(fn ($definition) => $definition->capability, $catalog->tools()),
            fn (string $capability) => $capability !== 'capability_discovery',
        ));
        self::assertSame(array_column($descriptors, 'name'), $catalogCapabilities);
        self::assertSame('capability_discovery', $catalog->tool('refactoring_capabilities')->capability);
    }

    public function test_the_resource_definition_is_a_read_only_json_document(): void
    {
        $resource = (new RefactoringToolCatalog())->resourceDefinition();

        self::assertInstanceOf(ResourceDefinition::class, $resource);
        self::assertSame('agent-kit://refactoring/capabilities', $resource->uri);
        self::assertSame('refactoring_capabilities', $resource->name);
        self::assertSame('application/json', $resource->mimeType);
    }

    public function test_an_unknown_tool_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RefactoringToolCatalog())->tool('refactoring_apply');
    }

    private function capabilities(): DefaultRefactoringCapabilities
    {
        $analyzer = new PhpFileAnalyzer();
        $scanner = new ProjectScanner($analyzer);

        return new DefaultRefactoringCapabilities(
            $scanner,
            $analyzer,
            new RefactoringReport(),
            new CodebaseIndexer($scanner, new PhpAstParser()),
            new CallerAnalyzer(),
            new ImpactAnalyzer(),
        );
    }
}
