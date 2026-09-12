<?php

namespace Peralta\AgentKit\Refactoring\Commands;

use Illuminate\Console\Command;
use Peralta\AgentKit\Refactoring\Application\CapabilityException;
use Peralta\AgentKit\Refactoring\Application\RefactoringCapabilities;
use Peralta\AgentKit\Refactoring\Commands\Concerns\RendersCapabilityResults;

final class RefactorAnalyzeCommand extends Command
{
    use RendersCapabilityResults;

    protected $signature = 'agent-kit:refactor-analyze
        {file : Project-relative PHP file, absolute in-project PHP file, fully qualified class, or Class::method}
        {--path= : Project root; defaults to the Laravel base path}
        {--json : Emit JSON only}';

    protected $description = 'Analyze a PHP file, class, or method for deterministic refactoring signals';

    public function handle(RefactoringCapabilities $capabilities): int
    {
        try {
            $result = $capabilities->analyze(
                (string) ($this->option('path') ?: base_path()),
                (string) $this->argument('file'),
            );
        } catch (CapabilityException $exception) {
            return $this->renderCapabilityFailure($exception, (bool) $this->option('json'));
        }

        if ($this->option('json')) {
            $this->renderJson($result);

            return self::SUCCESS;
        }

        $metrics = $result->data['metrics'];
        $this->table(['Metric', 'Value'], [
            ['Target', $result->data['target']],
            ['Lines', $metrics['lines']],
            ['Methods/functions', $metrics['methods']],
            ['Imports/uses', $metrics['dependencies']],
            ['Branches', $metrics['branches']],
            ['Risk', $result->data['risk'] ?? 'UNKNOWN'],
        ]);

        if ($metrics['smells'] === []) {
            $this->info('No threshold-based smells detected.');
        } else {
            $this->table(
                ['Severity', 'Smell', 'Reason'],
                array_map(static fn (array $smell): array => [
                    strtoupper($smell['severity']),
                    $smell['name'],
                    $smell['reason'],
                ], $metrics['smells']),
            );
        }

        if ($result->incomplete()) {
            $this->warn('Static analysis is incomplete; inspect diagnostics and unresolved references.');
        }

        return self::SUCCESS;
    }
}
