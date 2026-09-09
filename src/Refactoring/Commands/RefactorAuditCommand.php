<?php

namespace Peralta\AgentKit\Refactoring\Commands;

use Illuminate\Console\Command;
use Peralta\AgentKit\Refactoring\Support\ProjectScanner;
use Peralta\AgentKit\Refactoring\Support\RefactoringReport;

final class RefactorAuditCommand extends Command
{
    protected $signature = 'agent-kit:refactor-audit {path? : Project root} {--output= : Output directory} {--no-baseline : Do not update baseline.json}';
    protected $description = 'Audit a PHP/Laravel codebase for deterministic refactoring signals';

    public function handle(ProjectScanner $scanner, RefactoringReport $reporter): int
    {
        $root = realpath($this->argument('path') ?: base_path()) ?: ($this->argument('path') ?: base_path());
        $output = $this->option('output') ?: $root . '/.agent-kit/refactoring';

        $this->info("Scanning {$root}...");
        $files = $scanner->scan($root);
        $report = $reporter->build($files, $root);

        if (!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)) {
            throw new \RuntimeException("Could not create output directory: {$output}");
        }

        file_put_contents($output . '/audit.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($output . '/audit.md', $reporter->markdown($report));

        if (!$this->option('no-baseline')) {
            file_put_contents($output . '/baseline.json', json_encode($report['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $s = $report['summary'];
        $this->table(['PHP files', 'Lines', 'High', 'Medium', 'Low'], [[
            $s['php_files'], $s['lines'], $s['issues']['high'], $s['issues']['medium'], $s['issues']['low'],
        ]]);
        $this->newLine();
        $this->info("Reports written to {$output}/audit.md and audit.json");

        return self::SUCCESS;
    }
}
