<?php

namespace Peralta\AgentKit\Tests\Feature\Refactoring;

use Illuminate\Support\Facades\Artisan;
use Peralta\AgentKit\Refactoring\Agents\AgentAdapterRegistry;
use Peralta\AgentKit\Refactoring\Agents\AgentCommandRepository;
use Peralta\AgentKit\Refactoring\Agents\AgentConfigurationInstaller;
use Peralta\AgentKit\Refactoring\Agents\AgentTemplateRenderer;
use Peralta\AgentKit\Refactoring\Commands\InstallAgentsCommand;
use Peralta\AgentKit\Tests\TestCase;
use ReflectionProperty;

final class InstallAgentsCommandTest extends TestCase
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
                $file->isLink() || !$file->isDir()
                    ? unlink($file->getPathname())
                    : rmdir($file->getPathname());
            }

            rmdir($directory);
        }

        parent::tearDown();
    }

    public function test_installs_only_the_selected_cursor_adapter(): void
    {
        $root = $this->temporaryDirectory();

        $status = Artisan::call('agent-kit:agents:install', [
            'agents' => ['cursor'],
            '--path' => $root,
        ]);

        self::assertSame(0, $status);
        self::assertCount(7, $this->files($root));
        self::assertFileExists($root . '/.cursor/skills/refactor-audit/SKILL.md');
        self::assertFileExists($root . '/.cursor/rules/agent-kit-refactoring.mdc');
        self::assertDirectoryDoesNotExist($root . '/.claude');
        self::assertSame(7, substr_count(Artisan::output(), 'CREATED '));
    }

    public function test_all_installs_both_adapters(): void
    {
        $root = $this->temporaryDirectory();

        $status = Artisan::call('agent-kit:agents:install', [
            '--all' => true,
            '--path' => $root,
        ]);

        self::assertSame(0, $status);
        self::assertCount(14, $this->files($root));
        self::assertFileExists($root . '/.cursor/rules/agent-kit-refactoring.mdc');
        self::assertFileExists($root . '/.claude/rules/agent-kit-refactoring.md');
        self::assertSame(14, substr_count(Artisan::output(), 'CREATED '));
    }

    public function test_all_rejects_positional_agents_instead_of_ignoring_them(): void
    {
        $root = $this->temporaryDirectory();

        $status = Artisan::call('agent-kit:agents:install', [
            'agents' => ['unknown-agent'],
            '--all' => true,
            '--path' => $root,
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString('Do not combine --all with positional coding agents.', Artisan::output());
        self::assertSame([], $this->files($root));
    }

    public function test_conflict_returns_failure_preserves_custom_content_and_creates_missing_files(): void
    {
        $root = $this->temporaryDirectory();
        $conflict = $root . '/.cursor/skills/refactor-audit/SKILL.md';
        mkdir(dirname($conflict), 0777, true);
        file_put_contents($conflict, 'custom content');

        $status = Artisan::call('agent-kit:agents:install', [
            'agents' => ['cursor'],
            '--path' => $root,
        ]);
        $output = Artisan::output();

        self::assertSame(1, $status);
        self::assertSame('custom content', file_get_contents($conflict));
        self::assertCount(7, $this->files($root));
        self::assertStringContainsString("CONFLICTS .cursor/skills/refactor-audit/SKILL.md", $output);
        self::assertSame(6, substr_count($output, 'CREATED '));
    }

    public function test_force_overwrites_conflicts(): void
    {
        $root = $this->temporaryDirectory();
        $conflict = $root . '/.cursor/skills/refactor-audit/SKILL.md';
        mkdir(dirname($conflict), 0777, true);
        file_put_contents($conflict, 'custom content');

        $status = Artisan::call('agent-kit:agents:install', [
            'agents' => ['cursor'],
            '--path' => $root,
            '--force' => true,
        ]);
        $output = Artisan::output();

        self::assertSame(0, $status);
        self::assertNotSame('custom content', file_get_contents($conflict));
        self::assertStringContainsString("OVERWRITTEN .cursor/skills/refactor-audit/SKILL.md", $output);
        self::assertSame(6, substr_count($output, 'CREATED '));
    }

    public function test_repeated_install_reports_every_file_as_unchanged(): void
    {
        $root = $this->temporaryDirectory();
        $arguments = ['agents' => ['claude'], '--path' => $root];

        self::assertSame(0, Artisan::call('agent-kit:agents:install', $arguments));
        self::assertSame(0, Artisan::call('agent-kit:agents:install', $arguments));

        self::assertCount(7, $this->files($root));
        self::assertSame(7, substr_count(Artisan::output(), 'UNCHANGED '));
        self::assertStringNotContainsString('CREATED ', Artisan::output());
    }

    public function test_unknown_adapter_returns_a_readable_failure(): void
    {
        $root = $this->temporaryDirectory();

        $status = Artisan::call('agent-kit:agents:install', [
            'agents' => ['windsurf'],
            '--path' => $root,
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString('Unsupported coding agent: windsurf', Artisan::output());
        self::assertSame([], $this->files($root));
    }

    public function test_invalid_project_root_returns_a_readable_failure(): void
    {
        $root = $this->temporaryDirectory() . '/missing';

        $status = Artisan::call('agent-kit:agents:install', [
            'agents' => ['cursor'],
            '--path' => $root,
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString("Project root is not an existing directory: {$root}", Artisan::output());
    }

    public function test_explicit_empty_or_whitespace_path_is_rejected_before_writing(): void
    {
        foreach ([null, '', '   '] as $path) {
            $fallbackRoot = $this->temporaryDirectory();
            $this->app->setBasePath($fallbackRoot);

            $status = Artisan::call('agent-kit:agents:install', [
                'agents' => ['cursor'],
                '--path' => $path,
            ]);

            self::assertSame(1, $status);
            self::assertStringContainsString(
                'The --path option requires a project root. Use --path=/project.',
                Artisan::output(),
            );
            self::assertSame([], $this->files($fallbackRoot));
        }
    }

    public function test_absent_path_defaults_to_the_application_base_path(): void
    {
        $root = $this->temporaryDirectory();
        $this->app->setBasePath($root);

        $status = Artisan::call('agent-kit:agents:install', [
            'agents' => ['cursor'],
        ]);

        self::assertSame(0, $status);
        self::assertCount(7, $this->files($root));
    }

    public function test_markup_in_an_unknown_agent_is_rendered_literally(): void
    {
        $root = $this->temporaryDirectory();

        $status = Artisan::call('agent-kit:agents:install', [
            'agents' => ['<info>cursor</info>'],
            '--path' => $root,
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'Unsupported coding agent: <info>cursor</info>',
            Artisan::output(),
        );
        self::assertSame([], $this->files($root));
    }

    public function test_markup_in_an_invalid_path_is_rendered_literally(): void
    {
        $root = $this->temporaryDirectory() . '/<info>missing</info>';

        $status = Artisan::call('agent-kit:agents:install', [
            'agents' => ['cursor'],
            '--path' => $root,
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString(
            "Project root is not an existing directory: {$root}",
            Artisan::output(),
        );
    }

    public function test_non_interactive_invocation_without_agents_fails_clearly(): void
    {
        $root = $this->temporaryDirectory();

        $status = Artisan::call('agent-kit:agents:install', [
            '--path' => $root,
            '--no-interaction' => true,
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString('Select at least one coding agent or use --all.', Artisan::output());
        self::assertSame([], $this->files($root));
    }

    public function test_interactive_invocation_uses_a_multi_select_from_registered_adapters(): void
    {
        $root = $this->temporaryDirectory();

        $this->artisan('agent-kit:agents:install', ['--path' => $root])
            ->expectsChoice(
                'Select coding agents to install',
                ['cursor', 'claude'],
                ['cursor', 'claude'],
                true,
            )
            ->assertSuccessful();

        self::assertCount(14, $this->files($root));
    }

    public function test_duplicate_adapter_ids_return_a_readable_failure_without_writing_files(): void
    {
        $root = $this->temporaryDirectory();

        $status = Artisan::call('agent-kit:agents:install', [
            'agents' => ['cursor', 'cursor'],
            '--path' => $root,
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString('Duplicate coding agent: cursor', Artisan::output());
        self::assertSame([], $this->files($root));
    }

    public function test_provider_registers_agent_installation_services_as_singletons_with_package_resources(): void
    {
        $repository = $this->app->make(AgentCommandRepository::class);
        $renderer = $this->app->make(AgentTemplateRenderer::class);
        $registry = $this->app->make(AgentAdapterRegistry::class);
        $installer = $this->app->make(AgentConfigurationInstaller::class);

        self::assertSame($repository, $this->app->make(AgentCommandRepository::class));
        self::assertSame($renderer, $this->app->make(AgentTemplateRenderer::class));
        self::assertSame($registry, $this->app->make(AgentAdapterRegistry::class));
        self::assertSame($installer, $this->app->make(AgentConfigurationInstaller::class));
        self::assertSame(['cursor', 'claude'], $registry->ids());
        self::assertSame(['audit', 'analyze', 'callers', 'dependencies', 'impact', 'plan'], $repository->names());
        self::assertStringContainsString('{{cli_audit}} --json', $repository->command('audit'));
    }

    public function test_command_signature_and_description_match_the_public_contract(): void
    {
        $command = $this->app->make(InstallAgentsCommand::class);
        $signature = (new ReflectionProperty($command, 'signature'))->getValue($command);

        self::assertSame(
            'agent-kit:agents:install {agents?* : cursor and/or claude} {--all : Install every supported adapter} {--path= : Consumer project root} {--force : Overwrite conflicting Agent Kit-dedicated files}',
            preg_replace('/\s+/', ' ', trim($signature)),
        );
        self::assertSame(
            'Install Agent Kit refactoring skills for coding agents',
            $command->getDescription(),
        );
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/agent-kit-agents-command-' . bin2hex(random_bytes(6));
        mkdir($directory, 0777, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    /** @return list<string> */
    private function files(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = str_replace($root . '/', '', $file->getPathname());
            }
        }

        sort($files);

        return $files;
    }
}
