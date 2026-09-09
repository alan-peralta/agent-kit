<?php

namespace Peralta\AgentKit\Tests\Feature\Refactoring;

use Illuminate\Support\Facades\Artisan;
use Peralta\AgentKit\Tests\TestCase;

final class RefactoringCommandsTest extends TestCase
{
    public function test_callers_command_emits_machine_readable_json(): void
    {
        $status = Artisan::call('agent-kit:refactor-callers', [
            'class' => 'Fixtures\\Payments\\PaymentService',
            '--method' => 'charge',
            '--path' => $this->fixtureRoot(),
            '--json' => true,
        ]);

        $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $status);
        $this->assertSame('Fixtures\\Payments\\PaymentService', $decoded['target']);
        $this->assertSame('charge', $decoded['method']);
        $this->assertNotEmpty($decoded['direct_callers']);
    }

    public function test_dependencies_and_impact_commands_emit_json(): void
    {
        $this->assertSame(0, Artisan::call('agent-kit:refactor-dependencies', [
            'class' => 'Fixtures\\Checkout\\CheckoutService',
            '--path' => $this->fixtureRoot(),
            '--json' => true,
        ]));
        $dependencies = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Fixtures\\Checkout\\CheckoutService', $dependencies['target']);
        $this->assertNotEmpty($dependencies['dependencies']);

        $this->assertSame(0, Artisan::call('agent-kit:refactor-impact', [
            'class' => 'Fixtures\\Payments\\PaymentService',
            '--path' => $this->fixtureRoot(),
            '--json' => true,
        ]));
        $impact = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('LOW', $impact['risk']);
        $this->assertArrayHasKey('transitive_dependents', $impact);
    }

    public function test_commands_render_human_headings(): void
    {
        $this->assertSame(0, Artisan::call('agent-kit:refactor-impact', [
            'class' => 'Fixtures\\Payments\\PaymentService',
            '--path' => $this->fixtureRoot(),
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString('REFACTORING IMPACT ANALYSIS', $output);
        $this->assertStringContainsString('DIRECT CALLERS', $output);
        $this->assertStringContainsString('STRUCTURAL DEPENDENCIES', $output);
    }

    public function test_commands_fail_for_invalid_roots_and_missing_targets(): void
    {
        $this->assertSame(1, Artisan::call('agent-kit:refactor-impact', [
            'class' => 'Anything',
            '--path' => '/path/that/does/not/exist',
            '--json' => true,
        ]));
        $this->assertStringContainsString('Project root not found', Artisan::output());

        $this->assertSame(1, Artisan::call('agent-kit:refactor-callers', [
            'class' => 'Missing\\Service',
            '--path' => $this->fixtureRoot(),
            '--json' => true,
        ]));
        $this->assertStringContainsString('Missing\\Service', Artisan::output());
    }

    public function test_json_contains_non_fatal_parse_diagnostics(): void
    {
        $root = sys_get_temp_dir() . '/agent-kit-cli-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        file_put_contents($root . '/Valid.php', '<?php namespace Demo; class Valid {}');
        file_put_contents($root . '/Broken.php', '<?php class Broken {');

        $status = Artisan::call('agent-kit:refactor-impact', [
            'class' => 'Demo\\Valid',
            '--path' => $root,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        unlink($root . '/Valid.php');
        unlink($root . '/Broken.php');
        rmdir($root);

        $this->assertSame(0, $status);
        $this->assertCount(1, $decoded['diagnostics']);
        $this->assertSame('Broken.php', $decoded['diagnostics'][0]['file']);
    }

    private function fixtureRoot(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Refactoring/Ast';
    }
}
