<?php

namespace Peralta\AgentKit\Tests\Feature\Refactoring;

use Illuminate\Support\Facades\Artisan;
use Peralta\AgentKit\Refactoring\Analysis\Ast\AstParser;
use Peralta\AgentKit\Refactoring\Analysis\DTOs\ParsedFile;
use Peralta\AgentKit\Refactoring\Application\DefaultRefactoringCapabilities;
use Peralta\AgentKit\Refactoring\Application\RefactoringCapabilities;
use Peralta\AgentKit\Refactoring\Commands\RefactorAnalyzeCommand;
use Peralta\AgentKit\Refactoring\Commands\RefactorAuditCommand;
use Peralta\AgentKit\Refactoring\Commands\RefactorCallersCommand;
use Peralta\AgentKit\Refactoring\Commands\RefactorDependenciesCommand;
use Peralta\AgentKit\Refactoring\Commands\RefactorImpactCommand;
use Peralta\AgentKit\Refactoring\Support\RefactoringReport;
use Peralta\AgentKit\Tests\TestCase;
use ReflectionMethod;

final class RefactoringCommandsTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        }

        parent::tearDown();
    }

    public function test_capability_discovery_emits_the_versioned_json_envelope_only(): void
    {
        $status = Artisan::call('agent-kit:refactor-capabilities', ['--json' => true]);
        $output = Artisan::output();
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(0, $status);
        self::assertSame(
            ['schema_version', 'capability', 'incomplete', 'data', 'diagnostics', 'unresolved'],
            array_keys($decoded),
        );
        self::assertSame('1.0', $decoded['schema_version']);
        self::assertSame('capability_discovery', $decoded['capability']);
        self::assertFalse($decoded['incomplete']);
        self::assertSame(
            ['audit', 'analyze', 'find_callers', 'dependencies', 'impact'],
            array_column($decoded['data']['capabilities'], 'name'),
        );
        self::assertSame(
            json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            trim($output),
        );
    }

    public function test_audit_json_is_side_effect_free_and_does_not_parse_ast(): void
    {
        $output = $this->temporaryDirectory() . '/reports';
        $this->app->bind(AstParser::class, fn () => new class implements AstParser {
            public function parse(string $file, ?string $displayPath = null): ParsedFile
            {
                throw new \RuntimeException('Audit must not parse AST.');
            }
        });

        $status = Artisan::call('agent-kit:refactor-audit', [
            'path' => $this->fixtureRoot(),
            '--output' => $output,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(0, $status);
        self::assertSame('audit', $decoded['capability']);
        self::assertArrayHasKey('summary', $decoded['data']);
        self::assertDirectoryDoesNotExist($output);
    }

    public function test_analyze_json_accepts_class_and_absolute_file_targets(): void
    {
        self::assertSame(0, Artisan::call('agent-kit:refactor-analyze', [
            'target' => 'Fixtures\\Checkout\\CheckoutService',
            '--path' => $this->fixtureRoot(),
            '--json' => true,
        ]));
        $class = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('analyze', $class['capability']);
        self::assertSame('Fixtures\\Checkout\\CheckoutService', $class['data']['target']);
        self::assertSame('CheckoutService.php', $class['data']['metrics']['path']);

        self::assertSame(0, Artisan::call('agent-kit:refactor-analyze', [
            'target' => $this->fixtureRoot() . '/CheckoutService.php',
            '--path' => $this->fixtureRoot(),
            '--json' => true,
        ]));
        $file = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('CheckoutService.php', $file['data']['target']);
        self::assertSame('CheckoutService.php', $file['data']['metrics']['path']);
    }

    public function test_callers_json_forwards_class_method_target(): void
    {
        $status = Artisan::call('agent-kit:refactor-callers', [
            'target' => 'Fixtures\\Payments\\PaymentService::charge',
            '--path' => $this->fixtureRoot(),
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(0, $status);
        self::assertSame('find_callers', $decoded['capability']);
        self::assertSame('Fixtures\\Payments\\PaymentService', $decoded['data']['target']);
        self::assertSame('charge', $decoded['data']['method']);
        self::assertNotEmpty($decoded['data']['direct_callers']);
    }

    public function test_legacy_method_option_is_forwarded_without_changing_the_canonical_target_argument(): void
    {
        $status = Artisan::call('agent-kit:refactor-impact', [
            'target' => 'Fixtures\\Payments\\PaymentService',
            '--method' => 'charge',
            '--path' => $this->fixtureRoot(),
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(0, $status);
        self::assertSame('charge', $decoded['data']['method']);
    }

    public function test_dependencies_and_impact_emit_versioned_json(): void
    {
        self::assertSame(0, Artisan::call('agent-kit:refactor-dependencies', [
            'target' => 'Fixtures\\Checkout\\CheckoutService',
            '--path' => $this->fixtureRoot(),
            '--json' => true,
        ]));
        $dependencies = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('dependencies', $dependencies['capability']);
        self::assertNotEmpty($dependencies['data']['upstream_dependencies']);
        self::assertArrayHasKey('downstream_dependents', $dependencies['data']);

        self::assertSame(0, Artisan::call('agent-kit:refactor-impact', [
            'target' => 'Fixtures\\Payments\\PaymentService::charge',
            '--path' => $this->fixtureRoot(),
            '--json' => true,
        ]));
        $impact = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('impact', $impact['capability']);
        self::assertSame('charge', $impact['data']['method']);
        self::assertArrayHasKey('transitive_dependents', $impact['data']);
    }

    public function test_json_failure_uses_the_exact_stable_error_envelope(): void
    {
        $status = Artisan::call('agent-kit:refactor-impact', [
            'target' => 'Missing\\Service',
            '--path' => $this->fixtureRoot(),
            '--json' => true,
        ]);

        self::assertSame(1, $status);
        self::assertSame([
            'schema_version' => '1.0',
            'error' => [
                'code' => 'TARGET_NOT_FOUND',
                'message' => 'Class not found: Missing\\Service',
            ],
        ], json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_human_commands_render_useful_output_and_audit_reports(): void
    {
        self::assertSame(0, Artisan::call('agent-kit:refactor-impact', [
            'target' => 'Fixtures\\Payments\\PaymentService',
            '--path' => $this->fixtureRoot(),
        ]));
        $impactOutput = Artisan::output();
        self::assertStringContainsString('REFACTORING IMPACT ANALYSIS', $impactOutput);
        self::assertStringContainsString('DIRECT CALLERS', $impactOutput);
        self::assertStringContainsString('STRUCTURAL DEPENDENCIES', $impactOutput);

        $output = $this->temporaryDirectory() . '/reports';
        self::assertSame(0, Artisan::call('agent-kit:refactor-audit', [
            'path' => $this->fixtureRoot(),
            '--output' => $output,
            '--no-baseline' => true,
        ]));
        self::assertFileExists($output . '/audit.json');
        self::assertFileExists($output . '/audit.md');
        self::assertFileDoesNotExist($output . '/baseline.json');
        self::assertStringContainsString('Reports written to', Artisan::output());
    }

    public function test_audit_rejects_a_report_target_directory_without_creating_partial_reports(): void
    {
        $output = $this->temporaryDirectory() . '/reports';
        mkdir($output, 0777, true);
        mkdir($output . '/audit.json');

        try {
            $status = Artisan::call('agent-kit:refactor-audit', [
                'path' => $this->fixtureRoot(),
                '--output' => $output,
            ]);
        } catch (\Throwable $exception) {
            self::fail('The command must render a stable failure instead of throwing: ' . $exception->getMessage());
        }

        self::assertSame(1, $status);
        self::assertSame(
            "Cannot write audit report: {$output}/audit.json is not a regular file.\n",
            Artisan::output(),
        );
        self::assertDirectoryExists($output . '/audit.json');
        self::assertFileDoesNotExist($output . '/audit.md');
        self::assertFileDoesNotExist($output . '/baseline.json');
    }

    public function test_audit_atomically_overwrites_existing_regular_report_files(): void
    {
        $output = $this->temporaryDirectory() . '/reports';
        mkdir($output, 0777, true);
        foreach (['audit.json', 'audit.md', 'baseline.json'] as $file) {
            file_put_contents($output . '/' . $file, 'stale');
        }

        $status = Artisan::call('agent-kit:refactor-audit', [
            'path' => $this->fixtureRoot(),
            '--output' => $output,
        ]);

        self::assertSame(0, $status);
        self::assertNotSame('stale', file_get_contents($output . '/audit.json'));
        self::assertNotSame('stale', file_get_contents($output . '/audit.md'));
        self::assertNotSame('stale', file_get_contents($output . '/baseline.json'));
        self::assertSame([], glob($output . '/.agent-kit-*') ?: []);
    }

    public function test_provider_binds_default_capabilities_and_commands_inject_only_the_application_contract(): void
    {
        self::assertInstanceOf(DefaultRefactoringCapabilities::class, $this->app->make(RefactoringCapabilities::class));

        foreach ([
            RefactorAnalyzeCommand::class,
            RefactorCallersCommand::class,
            RefactorDependenciesCommand::class,
            RefactorImpactCommand::class,
        ] as $command) {
            $parameters = (new ReflectionMethod($command, 'handle'))->getParameters();
            self::assertCount(1, $parameters);
            self::assertSame(RefactoringCapabilities::class, $parameters[0]->getType()?->getName());
        }

        $auditParameters = (new ReflectionMethod(RefactorAuditCommand::class, 'handle'))->getParameters();
        self::assertSame(
            [RefactoringCapabilities::class, RefactoringReport::class],
            array_map(fn ($parameter) => $parameter->getType()?->getName(), $auditParameters),
        );
    }

    public function test_json_contains_non_fatal_parse_diagnostics(): void
    {
        $root = $this->temporaryDirectory();
        file_put_contents($root . '/Valid.php', '<?php namespace Demo; class Valid {}');
        file_put_contents($root . '/Broken.php', '<?php class Broken {');

        $status = Artisan::call('agent-kit:refactor-impact', [
            'target' => 'Demo\\Valid',
            '--path' => $root,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(0, $status);
        self::assertTrue($decoded['incomplete']);
        self::assertCount(1, $decoded['diagnostics']);
        self::assertSame('Broken.php', $decoded['diagnostics'][0]['file']);
    }

    private function fixtureRoot(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Refactoring/Ast';
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/agent-kit-cli-' . bin2hex(random_bytes(6));
        mkdir($directory, 0777, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
