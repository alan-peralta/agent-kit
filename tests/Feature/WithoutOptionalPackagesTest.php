<?php

namespace Peralta\AgentKit\Tests\Feature;

use Composer\Autoload\ClassLoader;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Peralta\AgentKit\Agent;
use Peralta\AgentKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * An application that installs Agent Kit for its agent core gets none of the suggested packages
 * (issue #11). They cannot be uninstalled here, since PHPUnit itself needs nikic/php-parser, so
 * each test runs in a fresh process whose Composer autoloader refuses their namespaces before
 * the application boots: class_exists() then answers false exactly as if they were missing.
 * Coverage analysis itself needs nikic/php-parser, which this test hides, so it is marked
 * #[CoversNothing] to keep it passing when a coverage driver (pcov, Xdebug) is loaded.
 */
#[RunTestsInSeparateProcesses]
#[CoversNothing]
final class WithoutOptionalPackagesTest extends TestCase
{
    /** mcp/sdk and what it pulls in, and nikic/php-parser. */
    private const HIDDEN_NAMESPACES = [
        'Mcp\\',
        'Opis\\',
        'Http\\Discovery\\',
        'Psr\\Http\\Server\\',
        'phpDocumentor\\',
        'PHPStan\\PhpDocParser\\',
        'Webmozart\\Assert\\',
        'Doctrine\\Deprecations\\',
        'PhpParser\\',
    ];

    private const AST_MESSAGE = 'The AST analysis (analyze, callers, dependencies, impact) requires nikic/php-parser. Install it with: composer require --dev nikic/php-parser';

    protected function setUp(): void
    {
        self::hideOptionalPackages();

        parent::setUp();
    }

    public function test_an_agent_sends_through_a_real_provider(): void
    {
        $this->app['config']->set('agent-kit.providers.deepseek', [
            'driver' => 'deepseek',
            'api_key' => 'test-key',
            'base_url' => 'http://api.test',
            'model' => 'deepseek-chat',
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, [], json_encode([
                    'choices' => [['message' => ['role' => 'assistant', 'content' => '["b","a"]'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2],
                ])),
            ])),
        ]);

        $response = $this->app->make(Agent::class)
            ->provider('deepseek')
            ->system('Reorder the ids.')
            ->options(['response_format' => ['type' => 'json_object'], 'timeout' => 5])
            ->send('["a","b"]');

        self::assertSame('["b","a"]', $response->text());
    }

    public function test_artisan_lists_every_agent_kit_command(): void
    {
        self::assertSame(0, Artisan::call('list'));
        $output = Artisan::output();

        foreach ([
            'agent-kit:mcp',
            'agent-kit:refactor-capabilities',
            'agent-kit:refactor-audit',
            'agent-kit:refactor-analyze',
            'agent-kit:refactor-callers',
            'agent-kit:refactor-dependencies',
            'agent-kit:refactor-impact',
        ] as $command) {
            self::assertStringContainsString($command, $output);
        }
    }

    public function test_capability_discovery_and_the_audit_need_no_parser(): void
    {
        self::assertSame(0, Artisan::call('agent-kit:refactor-capabilities', ['--json' => true]));
        self::assertSame('capability_discovery', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['capability']);

        self::assertSame(0, Artisan::call('agent-kit:refactor-audit', ['path' => $this->fixtureRoot(), '--json' => true]));
        self::assertSame('audit', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['capability']);
    }

    /** @param array<string, string> $arguments */
    #[DataProvider('astCommands')]
    public function test_ast_commands_name_the_package_to_install(string $command, array $arguments): void
    {
        $status = Artisan::call($command, [...$arguments, '--path' => $this->fixtureRoot(), '--json' => true]);

        self::assertSame(1, $status);
        self::assertSame([
            'schema_version' => '1.0',
            'error' => ['code' => 'DEPENDENCY_MISSING', 'message' => self::AST_MESSAGE],
        ], json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
    }

    public static function astCommands(): array
    {
        $target = 'Fixtures\\Payments\\PaymentService';

        return [
            'analyze' => ['agent-kit:refactor-analyze', ['file' => $target]],
            'callers' => ['agent-kit:refactor-callers', ['class' => $target]],
            'dependencies' => ['agent-kit:refactor-dependencies', ['class' => $target]],
            'impact' => ['agent-kit:refactor-impact', ['class' => $target]],
        ];
    }

    #[DataProvider('transports')]
    public function test_the_mcp_server_names_the_package_to_install(string $transport): void
    {
        $status = Artisan::call('agent-kit:mcp', ['--transport' => $transport, '--path' => $this->fixtureRoot()]);

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'The MCP server requires mcp/sdk. Install it with: composer require --dev mcp/sdk',
            Artisan::output(),
        );
    }

    public static function transports(): array
    {
        return ['stdio' => ['stdio'], 'http' => ['http']];
    }

    private function fixtureRoot(): string
    {
        return dirname(__DIR__) . '/Fixtures/Refactoring/Ast';
    }

    private static function hideOptionalPackages(): void
    {
        $loaded = array_values(array_filter(
            [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()],
            static fn (string $class): bool => self::hidden($class),
        ));
        self::assertSame([], $loaded, 'Optional packages were loaded before they could be hidden.');

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->unregister();
            spl_autoload_register(static function (string $class) use ($loader): void {
                if (!self::hidden($class)) {
                    $loader->loadClass($class);
                }
            }, true, true);
        }

        self::assertFalse(class_exists(\Mcp\Server::class));
        self::assertFalse(class_exists(\PhpParser\ParserFactory::class));
    }

    private static function hidden(string $class): bool
    {
        foreach (self::HIDDEN_NAMESPACES as $namespace) {
            if (str_starts_with(ltrim($class, '\\'), $namespace)) {
                return true;
            }
        }

        return false;
    }
}
