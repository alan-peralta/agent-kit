<?php

namespace Peralta\AgentKit\Refactoring\Commands;

use Illuminate\Console\Command;
use Peralta\AgentKit\Refactoring\Application\CapabilityException;
use Peralta\AgentKit\Refactoring\Application\RefactoringCapabilities;
use Peralta\AgentKit\Refactoring\Commands\Concerns\RendersCapabilityResults;
use Peralta\AgentKit\Refactoring\Support\RefactoringReport;

final class RefactorAuditCommand extends Command
{
    use RendersCapabilityResults;

    protected $signature = 'agent-kit:refactor-audit
        {path? : Project root}
        {--output= : Output directory}
        {--no-baseline : Do not update baseline.json}
        {--json : Emit JSON only}';

    protected $description = 'Audit a PHP/Laravel codebase for deterministic refactoring signals';

    public function handle(RefactoringCapabilities $capabilities, RefactoringReport $reporter): int
    {
        $root = (string) ($this->argument('path') ?: base_path());

        try {
            $result = $capabilities->audit($root);
        } catch (CapabilityException $exception) {
            return $this->renderCapabilityFailure($exception, (bool) $this->option('json'));
        }

        if ($this->option('json')) {
            $this->renderJson($result);

            return self::SUCCESS;
        }

        $output = (string) ($this->option('output')
            ?: rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR) . '/.agent-kit/refactoring');
        if (!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)) {
            return $this->renderCapabilityFailure(
                new CapabilityException('OUTPUT_WRITE_FAILED', "Could not create output directory: {$output}"),
                false,
            );
        }

        file_put_contents(
            $output . '/audit.json',
            json_encode($result->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        file_put_contents($output . '/audit.md', $reporter->markdown($result->data));

        if (!$this->option('no-baseline')) {
            file_put_contents(
                $output . '/baseline.json',
                json_encode(
                    $result->data['summary'],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
            );
        }

        $summary = $result->data['summary'];
        $this->table(['PHP files', 'Lines', 'High', 'Medium', 'Low'], [[
            $summary['php_files'],
            $summary['lines'],
            $summary['issues']['high'],
            $summary['issues']['medium'],
            $summary['issues']['low'],
        ]]);
        $this->newLine();
        $this->info("Reports written to {$output}/audit.md and audit.json");

        return self::SUCCESS;
    }
}
