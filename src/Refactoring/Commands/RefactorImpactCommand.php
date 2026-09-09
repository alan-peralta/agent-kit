<?php

namespace Peralta\AgentKit\Refactoring\Commands;

use Illuminate\Console\Command;
use Peralta\AgentKit\Refactoring\Analysis\ImpactAnalyzer;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexer;

final class RefactorImpactCommand extends Command
{
    protected $signature = 'agent-kit:refactor-impact
        {class : Fully qualified target class}
        {--path= : Project root; defaults to the Laravel base path}
        {--json : Emit JSON only}';

    protected $description = 'Analyze direct and transitive impact of changing a PHP class';

    public function handle(CodebaseIndexer $indexer, ImpactAnalyzer $analyzer): int
    {
        try {
            $result = $analyzer->analyze(
                $indexer->build($this->root()),
                (string) $this->argument('class'),
            );
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('REFACTORING IMPACT ANALYSIS');
        $this->line("Target: {$result->target}");
        $this->table(['Direct callers', 'Structural dependencies', 'Transitive dependents', 'Affected files', 'Risk'], [[
            $result->directCallers,
            $result->structuralDependencies,
            $result->transitiveDependents,
            $result->affectedFiles,
            $result->risk,
        ]]);
        $this->newLine();
        $this->info('DIRECT CALLERS');
        $this->renderEdges($result->direct);
        $this->newLine();
        $this->info('STRUCTURAL DEPENDENCIES');
        $this->renderEdges($result->structural);
        $this->newLine();
        $this->info('TRANSITIVE IMPACT');
        foreach ($result->transitive as $dependent) {
            $this->line(implode(' -> ', $dependent['path']));
        }
        if ($result->diagnostics !== []) {
            $this->warn(count($result->diagnostics) . ' PHP file(s) could not be parsed; results may be incomplete.');
        }

        return self::SUCCESS;
    }

    private function root(): string
    {
        $requested = (string) ($this->option('path') ?: base_path());
        $root = realpath($requested);
        if ($root === false || !is_dir($root)) {
            throw new \InvalidArgumentException("Project root not found: {$requested}");
        }

        return $root;
    }

    private function renderEdges(array $edges): void
    {
        $this->table(
            ['Source', 'Method', 'Type', 'Location'],
            array_map(fn (array $edge) => [
                $edge['source'],
                $edge['source_method'] ?? '-',
                strtoupper($edge['type']),
                $edge['file'] . ':' . $edge['line'],
            ], $edges),
        );
    }
}
