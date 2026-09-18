<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Peralta\AgentKit\Refactoring\Application\CapabilityException;
use Peralta\AgentKit\Refactoring\Mcp\McpProjectRoot;
use Peralta\AgentKit\Refactoring\Mcp\RefactoringToolCatalog;
use Peralta\AgentKit\Refactoring\Mcp\RefactoringToolHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefactoringToolHandlerTest extends TestCase
{
    #[DataProvider('toolMappings')]
    public function test_each_tool_calls_its_capability_with_the_fixed_root_and_trimmed_target(
        string $tool,
        string $method,
        array $arguments,
        array $expectedArguments,
    ): void {
        $capabilities = new RecordingCapabilities();
        $root = McpProjectRoot::fromPath(__DIR__);

        $result = $this->handler($capabilities, $tool)->call($arguments);

        self::assertSame([[$method, array_map(
            fn ($value) => $value === '{root}' ? $root->path : $value,
            $expectedArguments,
        )]], $capabilities->calls);
        self::assertFalse($result->isError);
    }

    public static function toolMappings(): array
    {
        return [
            'capabilities' => ['refactoring_capabilities', 'describeCapabilities', [], []],
            'audit' => ['refactoring_audit', 'audit', [], ['{root}']],
            'analyze' => ['refactoring_analyze', 'analyze', ['target' => '  app/Service.php '], ['{root}', 'app/Service.php']],
            'callers' => ['refactoring_callers', 'findCallers', ['target' => 'App\\Service::run'], ['{root}', 'App\\Service::run']],
            'dependencies' => ['refactoring_dependencies', 'dependencies', ['target' => 'App\\Service'], ['{root}', 'App\\Service']],
            'impact' => ['refactoring_impact', 'impact', ['target' => 'App\\Service::run'], ['{root}', 'App\\Service::run']],
        ];
    }

    public function test_success_results_carry_the_cli_envelope_as_structured_content_and_text(): void
    {
        $capabilities = new RecordingCapabilities();
        $capabilities->diagnostics = [['file' => 'Broken.php', 'line' => 1, 'message' => 'Syntax error']];
        $capabilities->unresolved = [['source' => 'App\\A', 'target' => null]];

        $result = $this->handler($capabilities, 'refactoring_impact')->call(['target' => 'App\\Service']);
        $expected = $capabilities->impact(McpProjectRoot::fromPath(__DIR__)->path, 'App\\Service')->toArray();

        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertSame($expected, $result->structuredContent);
        self::assertSame('1.0', $result->structuredContent['schema_version']);
        self::assertTrue($result->structuredContent['incomplete']);
        self::assertSame($capabilities->diagnostics, $result->structuredContent['diagnostics']);
        self::assertSame($capabilities->unresolved, $result->structuredContent['unresolved']);
        self::assertCount(1, $result->content);
        self::assertInstanceOf(TextContent::class, $result->content[0]);
        self::assertSame(
            json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $result->content[0]->text,
        );
    }

    public function test_domain_errors_become_tool_errors_with_the_stable_error_envelope(): void
    {
        $capabilities = new RecordingCapabilities();
        $capabilities->failure = new CapabilityException('TARGET_NOT_FOUND', 'Class not found: Missing\\Service');

        $result = $this->handler($capabilities, 'refactoring_callers')->call(['target' => 'Missing\\Service']);

        self::assertTrue($result->isError);
        self::assertSame([
            'schema_version' => '1.0',
            'error' => ['code' => 'TARGET_NOT_FOUND', 'message' => 'Class not found: Missing\\Service'],
        ], $result->structuredContent);
        self::assertSame(
            json_encode($result->structuredContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $result->content[0]->text,
        );
    }

    public function test_a_non_string_target_is_a_domain_error_not_a_crash(): void
    {
        $capabilities = new RecordingCapabilities();

        $result = $this->handler($capabilities, 'refactoring_analyze')->call(['target' => ['nested']]);

        self::assertTrue($result->isError);
        self::assertSame('INVALID_TARGET', $result->structuredContent['error']['code']);
        self::assertSame([], $capabilities->calls);
    }

    public function test_a_target_with_control_characters_is_a_domain_error(): void
    {
        $capabilities = new RecordingCapabilities();

        $result = $this->handler($capabilities, 'refactoring_analyze')->call(['target' => "Check\x00out.php"]);

        self::assertTrue($result->isError);
        self::assertSame('INVALID_TARGET', $result->structuredContent['error']['code']);
        self::assertSame([], $capabilities->calls);
    }

    public function test_the_adapter_never_touches_artisan_or_the_console_layer(): void
    {
        foreach ([
            dirname(__DIR__, 4) . '/src/Refactoring/Mcp/RefactoringToolHandler.php',
            dirname(__DIR__, 4) . '/src/Refactoring/Mcp/RefactoringToolCatalog.php',
        ] as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString('Illuminate\\Console', $source, $file);
            self::assertStringNotContainsString('Artisan', $source, $file);
            self::assertStringNotContainsString('DefaultRefactoringCapabilities', $source, $file);
        }
    }

    private function handler(RecordingCapabilities $capabilities, string $tool): RefactoringToolHandler
    {
        return new RefactoringToolHandler(
            $capabilities,
            McpProjectRoot::fromPath(__DIR__),
            (new RefactoringToolCatalog())->tool($tool),
        );
    }
}
