<?php

namespace Peralta\AgentKit\Refactoring\Commands;

use Illuminate\Console\Command;
use Peralta\AgentKit\Refactoring\Support\PhpFileAnalyzer;

final class RefactorAnalyzeCommand extends Command
{
    protected $signature = 'agent-kit:refactor-analyze {file : PHP file to analyze}';
    protected $description = 'Analyze one PHP file for deterministic refactoring signals';

    public function handle(PhpFileAnalyzer $analyzer): int
    {
        $file = $this->argument('file');
        $path = realpath($file) ?: realpath(base_path($file));
        if (!$path || !is_file($path)) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }

        $result = $analyzer->analyze($path, $file);
        $this->table(['Metric', 'Value'], [
            ['Lines', $result->lines],
            ['Methods/functions', $result->methods],
            ['Imports/uses', $result->dependencies],
            ['Branches', $result->branches],
        ]);

        if (!$result->smells) {
            $this->info('No threshold-based smells detected.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(['Severity', 'Smell', 'Reason'], array_map(fn ($s) => [strtoupper($s['severity']), $s['name'], $s['reason']], $result->smells));
        return self::SUCCESS;
    }
}
