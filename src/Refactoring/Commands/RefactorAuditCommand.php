<?php

namespace Peralta\AgentKit\Refactoring\Commands;

use Illuminate\Console\Command;
use Peralta\AgentKit\Refactoring\Application\CapabilityException;
use Peralta\AgentKit\Refactoring\Application\RefactoringCapabilities;
use Peralta\AgentKit\Refactoring\Commands\Concerns\RendersCapabilityResults;
use Peralta\AgentKit\Refactoring\Commands\Concerns\WritesAuditReports;
use Peralta\AgentKit\Refactoring\Support\RefactoringReport;

final class RefactorAuditCommand extends Command
{
    use RendersCapabilityResults;
    use WritesAuditReports;

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

        $requestedOutput = $this->option('output');
        $customOutput = is_string($requestedOutput) && $requestedOutput !== '';
        $output = (string) ($customOutput
            ? $requestedOutput
            : rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR) . '/.agent-kit/refactoring');
        try {
            $this->writeAuditReports(
                $output,
                $result->data,
                $reporter,
                !$this->option('no-baseline'),
                $customOutput ? null : (string) $result->data['project_root'],
            );
        } catch (CapabilityException $exception) {
            return $this->renderCapabilityFailure($exception, false);
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
