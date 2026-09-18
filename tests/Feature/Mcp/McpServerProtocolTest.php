<?php

namespace Peralta\AgentKit\Tests\Feature\Mcp;

use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Transport\StdioTransport;
use Opis\JsonSchema\Validator;
use Peralta\AgentKit\Refactoring\Application\RefactoringCapabilities;
use Peralta\AgentKit\Refactoring\Mcp\McpProjectRoot;
use Peralta\AgentKit\Refactoring\Mcp\McpServerFactory;
use Peralta\AgentKit\Refactoring\Mcp\RefactoringToolCatalog;
use Peralta\AgentKit\Refactoring\Mcp\Transport\StdioRunnerControl;
use Peralta\AgentKit\Tests\TestCase;
use Psr\Log\NullLogger;

final class McpServerProtocolTest extends TestCase
{
    private const INITIALIZE = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
        'protocolVersion' => '2025-11-25',
        'capabilities' => [],
        'clientInfo' => ['name' => 'agent-kit-tests', 'version' => '1.0.0'],
    ]];
    private const INITIALIZED = ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'];

    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                array_map('unlink', glob($path . '/*.php') ?: []);
                rmdir($path);
            }
        }
        parent::tearDown();
    }

    public function test_initialize_advertises_tools_and_resources_only(): void
    {
        $responses = $this->runProtocol([self::INITIALIZE]);

        $result = $responses[1]['result'];
        self::assertSame('2025-11-25', $result['protocolVersion']);
        self::assertSame('agent-kit-refactoring', $result['serverInfo']['name']);
        self::assertArrayHasKey('tools', $result['capabilities']);
        self::assertArrayHasKey('resources', $result['capabilities']);
        self::assertArrayNotHasKey('prompts', $result['capabilities']);
        self::assertStringContainsString('ANALYZE != MODIFY', $result['instructions']);
    }

    public function test_tools_list_matches_the_catalog_with_schemas_and_read_only_annotations(): void
    {
        $responses = $this->runProtocol([self::INITIALIZE, self::INITIALIZED, $this->request(2, 'tools/list')]);

        $tools = $responses[2]['result']['tools'];
        self::assertSame((new RefactoringToolCatalog())->names(), array_column($tools, 'name'));
        foreach ($tools as $tool) {
            self::assertSame('object', $tool['inputSchema']['type'], $tool['name']);
            self::assertArrayHasKey('outputSchema', $tool, $tool['name']);
            self::assertTrue($tool['annotations']['readOnlyHint'], $tool['name']);
            self::assertFalse($tool['annotations']['destructiveHint'], $tool['name']);
        }
    }

    public function test_every_tool_returns_the_same_envelope_as_the_capability_layer(): void
    {
        $root = McpProjectRoot::fromPath($this->fixtureRoot())->path;
        $direct = $this->app->make(RefactoringCapabilities::class);
        $calls = [
            2 => ['refactoring_capabilities', [], $direct->describeCapabilities()->toArray()],
            3 => ['refactoring_audit', [], null],
            4 => ['refactoring_analyze', ['target' => 'CheckoutService.php'], $direct->analyze($root, 'CheckoutService.php')->toArray()],
            5 => ['refactoring_callers', ['target' => 'Fixtures\\Payments\\PaymentService::charge'], $direct->findCallers($root, 'Fixtures\\Payments\\PaymentService::charge')->toArray()],
            6 => ['refactoring_dependencies', ['target' => 'Fixtures\\Checkout\\CheckoutService'], $direct->dependencies($root, 'Fixtures\\Checkout\\CheckoutService')->toArray()],
            7 => ['refactoring_impact', ['target' => 'Fixtures\\Payments\\PaymentService::charge'], $direct->impact($root, 'Fixtures\\Payments\\PaymentService::charge')->toArray()],
        ];
        $messages = [self::INITIALIZE, self::INITIALIZED];
        foreach ($calls as $id => [$name, $arguments]) {
            $messages[] = $this->request($id, 'tools/call', ['name' => $name, 'arguments' => (object) $arguments]);
        }

        $responses = $this->runProtocol($messages);
        $catalog = new RefactoringToolCatalog();

        foreach ($calls as $id => [$name, , $expected]) {
            $result = $responses[$id]['result'];
            self::assertFalse($result['isError'] ?? false, $name);
            self::assertSame('1.0', $result['structuredContent']['schema_version'], $name);
            self::assertSame($catalog->tool($name)->capability, $result['structuredContent']['capability'], $name);
            self::assertSame('text', $result['content'][0]['type'], $name);
            self::assertSame($result['structuredContent'], json_decode($result['content'][0]['text'], true), $name);
            if ($expected !== null) {
                // audit carries generated_at; every other envelope must equal the direct call byte for byte.
                self::assertSame($expected, $result['structuredContent'], $name);
            } else {
                self::assertSame($root, $result['structuredContent']['data']['project_root']);
            }
            $this->assertMatchesOutputSchema($catalog->tool($name)->outputSchema, $result['structuredContent'], $name);
        }
    }

    public function test_domain_errors_are_tool_errors_that_still_match_the_output_schema(): void
    {
        $responses = $this->runProtocol([
            self::INITIALIZE,
            self::INITIALIZED,
            $this->request(2, 'tools/call', ['name' => 'refactoring_impact', 'arguments' => ['target' => 'Missing\\Service']]),
            $this->request(3, 'tools/call', ['name' => 'refactoring_dependencies', 'arguments' => ['target' => 'Fixtures\\Payments\\PaymentService::charge']]),
        ]);

        $notFound = $responses[2]['result'];
        self::assertTrue($notFound['isError']);
        self::assertSame(['schema_version' => '1.0', 'error' => [
            'code' => 'TARGET_NOT_FOUND',
            'message' => 'Class not found: Missing\\Service',
        ]], $notFound['structuredContent']);
        $this->assertMatchesOutputSchema((new RefactoringToolCatalog())->tool('refactoring_impact')->outputSchema, $notFound['structuredContent']);

        self::assertSame('UNSUPPORTED_TARGET', $responses[3]['result']['structuredContent']['error']['code']);
    }

    public function test_paths_outside_the_fixed_root_are_rejected_through_the_tool(): void
    {
        $parent = sys_get_temp_dir() . '/agent-kit-mcp-outside-' . bin2hex(random_bytes(6));
        $root = $parent . '/project';
        mkdir($root, 0777, true);
        file_put_contents($root . '/Inside.php', '<?php namespace Demo; class Inside {}');
        file_put_contents($parent . '/Outside.php', '<?php class Outside {}');
        $this->temporaryPaths[] = $parent . '/Outside.php';
        $this->temporaryPaths[] = $root;
        $this->temporaryPaths[] = $parent;

        $responses = $this->runProtocol([
            self::INITIALIZE,
            self::INITIALIZED,
            $this->request(2, 'tools/call', ['name' => 'refactoring_analyze', 'arguments' => ['target' => '../Outside.php']]),
            $this->request(3, 'tools/call', ['name' => 'refactoring_analyze', 'arguments' => ['target' => $parent . '/Outside.php']]),
        ], $root);

        foreach ([2, 3] as $id) {
            self::assertTrue($responses[$id]['result']['isError']);
            self::assertSame('TARGET_OUTSIDE_PROJECT', $responses[$id]['result']['structuredContent']['error']['code']);
        }
    }

    public function test_schema_violations_and_unknown_tools_are_invalid_params_errors(): void
    {
        $responses = $this->runProtocol([
            self::INITIALIZE,
            self::INITIALIZED,
            $this->request(2, 'tools/call', ['name' => 'refactoring_analyze', 'arguments' => (object) []]),
            $this->request(3, 'tools/call', ['name' => 'refactoring_analyze', 'arguments' => ['target' => '']]),
            $this->request(4, 'tools/call', ['name' => 'refactoring_analyze', 'arguments' => ['target' => 'A', 'extra' => true]]),
            $this->request(5, 'tools/call', ['name' => 'refactoring_audit', 'arguments' => ['target' => 42]]),
            $this->request(6, 'tools/call', ['name' => 'refactoring_apply', 'arguments' => (object) []]),
        ]);

        foreach ([2, 3, 4, 5, 6] as $id) {
            self::assertSame(-32602, $responses[$id]['error']['code'], "message {$id}");
        }
    }

    public function test_the_resource_lists_and_reads_the_catalog(): void
    {
        $responses = $this->runProtocol([
            self::INITIALIZE,
            self::INITIALIZED,
            $this->request(2, 'resources/list'),
            $this->request(3, 'resources/read', ['uri' => RefactoringToolCatalog::RESOURCE_URI]),
        ]);

        self::assertSame([RefactoringToolCatalog::RESOURCE_URI], array_column($responses[2]['result']['resources'], 'uri'));
        $contents = $responses[3]['result']['contents'][0];
        self::assertSame('application/json', $contents['mimeType']);
        $document = json_decode($contents['text'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame((new RefactoringToolCatalog())->names(), array_column($document['tools'], 'name'));
        self::assertFalse($document['mutation']['supported']);
    }

    public function test_malformed_json_yields_a_parse_error_and_stdout_stays_pure(): void
    {
        [$responses, $lines] = $this->runRaw([json_encode(self::INITIALIZE), '{not json', json_encode(self::INITIALIZED)]);

        $parseErrors = array_values(array_filter($responses, fn (array $message) => ($message['error']['code'] ?? null) === -32700));
        self::assertCount(1, $parseErrors);
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            self::assertIsArray($decoded, "stdout line is not JSON-RPC: {$line}");
            self::assertSame('2.0', $decoded['jsonrpc']);
        }
    }

    /** @return array<int, array> responses keyed by id */
    private function runProtocol(array $messages, ?string $root = null): array
    {
        [$responses] = $this->runRaw(array_map(fn ($message) => json_encode($message, JSON_THROW_ON_ERROR), $messages), $root);

        return $responses;
    }

    /** @return array{0: array<int, array>, 1: list<string>} */
    private function runRaw(array $lines, ?string $root = null): array
    {
        $input = fopen('php://temp', 'r+');
        fwrite($input, implode("\n", $lines) . "\n");
        rewind($input);
        $outputPath = tempnam(sys_get_temp_dir(), 'agent-kit-mcp-out-');
        $this->temporaryPaths[] = $outputPath;
        $output = fopen($outputPath, 'w+');

        $server = $this->app->make(McpServerFactory::class)->create(
            McpProjectRoot::fromPath($root ?? $this->fixtureRoot()),
            new NullLogger(),
            new InMemorySessionStore(PHP_INT_MAX),
            gcProbability: 0,
        );
        $status = $server->run(new StdioTransport($input, $output, new NullLogger(), new StdioRunnerControl()));
        self::assertSame(0, $status);

        $written = array_values(array_filter(explode("\n", (string) file_get_contents($outputPath)), fn ($line) => trim($line) !== ''));
        $responses = [];
        foreach ($written as $line) {
            $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $responses[$decoded['id'] ?? count($responses) + 1000] = $decoded;
        }

        return [$responses, $written];
    }

    private function request(int $id, string $method, array|object $params = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params === [] ? (object) [] : $params];
    }

    private function assertMatchesOutputSchema(array $schema, array $data, string $label = ''): void
    {
        $result = (new Validator())->validate(
            json_decode(json_encode($data, JSON_THROW_ON_ERROR)),
            json_decode(json_encode($schema, JSON_THROW_ON_ERROR)),
        );

        self::assertTrue($result->isValid(), $label . ': ' . ($result->error()?->message() ?? ''));
    }

    private function fixtureRoot(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Refactoring/Ast';
    }
}
