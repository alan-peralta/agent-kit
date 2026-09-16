<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp;

use Peralta\AgentKit\Refactoring\Mcp\CapabilitiesResourceHandler;
use Peralta\AgentKit\Refactoring\Mcp\McpProjectRoot;
use Peralta\AgentKit\Refactoring\Mcp\RefactoringToolCatalog;
use PHPUnit\Framework\TestCase;

final class CapabilitiesResourceHandlerTest extends TestCase
{
    public function test_it_describes_every_tool_from_the_catalog_and_the_capability_descriptors(): void
    {
        $capabilities = new RecordingCapabilities();
        $catalog = new RefactoringToolCatalog();
        $document = $this->handler($capabilities, $catalog)->describe();

        self::assertSame('1.0', $document['schema_version']);
        self::assertSame(['name' => 'agent-kit-refactoring', 'version' => '9.9.9'], $document['server']);
        self::assertSame(McpProjectRoot::fromPath(__DIR__)->path, $document['project_root']);
        self::assertSame($catalog->names(), array_column($document['tools'], 'name'));
        self::assertSame([['describeCapabilities', []]], $capabilities->calls);

        $impact = $document['tools'][5];
        self::assertSame('impact', $impact['capability']);
        self::assertSame(['class', 'method'], $impact['targets']);
        self::assertSame('php artisan agent-kit:refactor-impact <class> --method=<method> --json', $impact['cli_fallback']);
        self::assertSame($catalog->tool('refactoring_impact')->inputSchema, $impact['input_schema']);
        self::assertSame($catalog->tool('refactoring_impact')->outputSchema, $impact['output_schema']);

        self::assertFalse($document['mutation']['supported']);
        self::assertStringContainsString('ANALYZE != MODIFY', $document['mutation']['note']);
        self::assertNotEmpty($document['limitations']);
        self::assertArrayHasKey('success', $document['result_format']);
        self::assertArrayHasKey('error', $document['result_format']);
    }

    public function test_read_returns_a_json_text_resource_for_the_catalog_uri(): void
    {
        $contents = $this->handler(new RecordingCapabilities(), new RefactoringToolCatalog())->readDocument(RefactoringToolCatalog::RESOURCE_URI);

        self::assertSame(RefactoringToolCatalog::RESOURCE_URI, $contents->uri);
        self::assertSame('application/json', $contents->mimeType);
        $decoded = json_decode($contents->text, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('1.0', $decoded['schema_version']);
        self::assertCount(6, $decoded['tools']);
    }

    private function handler(RecordingCapabilities $capabilities, RefactoringToolCatalog $catalog): CapabilitiesResourceHandler
    {
        return new CapabilitiesResourceHandler(
            $capabilities,
            $catalog,
            McpProjectRoot::fromPath(__DIR__),
            'agent-kit-refactoring',
            '9.9.9',
        );
    }
}
