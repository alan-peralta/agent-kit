<?php

namespace Peralta\AgentKit\Refactoring\Commands;

use Illuminate\Console\Command;
use Peralta\AgentKit\Refactoring\Application\CapabilityException;
use Peralta\AgentKit\Refactoring\Application\RefactoringCapabilities;
use Peralta\AgentKit\Refactoring\Commands\Concerns\RendersCapabilityResults;

final class RefactorDependenciesCommand extends Command
{
    use RendersCapabilityResults;

    protected $signature = 'agent-kit:refactor-dependencies
        {target : Fully qualified target class}
        {--path= : Project root; defaults to the Laravel base path}
        {--json : Emit JSON only}';

    protected $description = 'List typed upstream dependencies and downstream dependents of a PHP class';

    public function handle(RefactoringCapabilities $capabilities): int
    {
        try {
            $result = $capabilities->dependencies(
                (string) ($this->option('path') ?: base_path()),
                (string) $this->argument('target'),
            );
        } catch (CapabilityException $exception) {
            return $this->renderCapabilityFailure($exception, (bool) $this->option('json'));
        }

        if ($this->option('json')) {
            $this->renderJson($result);

            return self::SUCCESS;
        }

        $data = $result->data;
        $this->info('REFACTORING DEPENDENCY ANALYSIS');
        $this->line('Target: ' . $data['target']);
        $this->newLine();
        $this->info('UPSTREAM DEPENDENCIES');
        $this->renderOutgoingEdges($data['upstream_dependencies']);
        $this->newLine();
        $this->info('DOWNSTREAM DEPENDENTS');
        $this->renderIncomingEdges($data['downstream_dependents']);
        $this->newLine();
        $this->info('TRANSITIVE DEPENDENTS');
        foreach ($data['transitive_dependents'] as $dependent) {
            $this->line(implode(' -> ', $dependent['path']));
        }
        if ($result->incomplete()) {
            $this->warn('Static analysis is incomplete; inspect diagnostics and unresolved references.');
        }

        return self::SUCCESS;
    }

    private function renderIncomingEdges(array $edges): void
    {
        $this->table(
            ['Source', 'Method', 'Type', 'Confidence', 'Location'],
            array_map(static fn (array $edge): array => [
                $edge['source'],
                $edge['source_method'] ?? '-',
                strtoupper($edge['type']),
                strtoupper($edge['confidence']),
                $edge['file'] . ':' . $edge['line'],
            ], $edges),
        );
    }

    private function renderOutgoingEdges(array $edges): void
    {
        $this->table(
            ['Target', 'Method', 'Type', 'Confidence', 'Location'],
            array_map(static fn (array $edge): array => [
                $edge['target'],
                $edge['target_method'] ?? '-',
                strtoupper($edge['type']),
                strtoupper($edge['confidence']),
                $edge['file'] . ':' . $edge['line'],
            ], $edges),
        );
    }
}
