<?php

namespace Peralta\AgentKit\Refactoring\Commands;

use Illuminate\Console\Command;
use Peralta\AgentKit\Refactoring\Application\CapabilityException;
use Peralta\AgentKit\Refactoring\Application\RefactoringCapabilities;
use Peralta\AgentKit\Refactoring\Commands\Concerns\RendersCapabilityResults;

final class RefactorImpactCommand extends Command
{
    use RendersCapabilityResults;

    protected $signature = 'agent-kit:refactor-impact
        {target : Fully qualified class or Class::method}
        {--method= : Deprecated method scope; prefer Class::method}
        {--path= : Project root; defaults to the Laravel base path}
        {--json : Emit JSON only}';

    protected $description = 'Analyze direct and transitive impact of changing a PHP class or method';

    public function handle(RefactoringCapabilities $capabilities): int
    {
        $target = (string) $this->argument('target');
        if ($this->option('method') !== null) {
            $target .= '::' . (string) $this->option('method');
        }

        try {
            $result = $capabilities->impact(
                (string) ($this->option('path') ?: base_path()),
                $target,
            );
        } catch (CapabilityException $exception) {
            return $this->renderCapabilityFailure($exception, (bool) $this->option('json'));
        }

        if ($this->option('json')) {
            $this->renderJson($result);

            return self::SUCCESS;
        }

        $data = $result->data;
        $this->info('REFACTORING IMPACT ANALYSIS');
        $this->table(['Target', 'Method', 'Risk', 'Direct', 'Structural', 'Transitive', 'Files'], [[
            $data['target'],
            $data['method'] ?? '-',
            $data['risk'],
            $data['direct_callers'],
            $data['structural_dependencies'],
            $data['transitive_dependents'],
            $data['affected_files'],
        ]]);
        $this->newLine();
        $this->info('DIRECT CALLERS');
        $this->renderIncomingEdges($data['direct']);
        $this->newLine();
        $this->info('STRUCTURAL DEPENDENCIES');
        $this->renderIncomingEdges($data['structural']);
        $this->newLine();
        $this->info('TRANSITIVE IMPACT');
        foreach ($data['transitive'] as $dependent) {
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
}
