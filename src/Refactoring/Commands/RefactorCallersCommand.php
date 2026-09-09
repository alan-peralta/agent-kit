<?php

namespace Peralta\AgentKit\Refactoring\Commands;

use Illuminate\Console\Command;
use Peralta\AgentKit\Refactoring\Analysis\CallerAnalyzer;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexer;

final class RefactorCallersCommand extends Command
{
    protected $signature = 'agent-kit:refactor-callers
        {class : Fully qualified target class}
        {--method= : Optional target method}
        {--path= : Project root; defaults to the Laravel base path}
        {--json : Emit JSON only}';

    protected $description = 'Find direct callers and structural dependencies of a PHP class';

    public function handle(CodebaseIndexer $indexer, CallerAnalyzer $analyzer): int
    {
        try {
            $root = $this->root();
            $result = $analyzer->findCallers(
                $indexer->build($root),
                (string) $this->argument('class'),
                $this->option('method') !== null ? (string) $this->option('method') : null,
            );
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('REFACTORING CALLER ANALYSIS');
        $this->line('Target: ' . $result->target . ($result->method ? '::' . $result->method : ''));
        $this->newLine();
        $this->info('DIRECT CALLERS');
        $this->renderEdges($result->directCallers);
        $this->newLine();
        $this->info('STRUCTURAL DEPENDENCIES');
        $this->renderEdges($result->structuralDependencies);
        $this->renderDiagnosticWarning($result->diagnostics);

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
            ['Source', 'Method', 'Type', 'Confidence', 'Location'],
            array_map(fn (array $edge) => [
                $edge['source'],
                $edge['source_method'] ?? '-',
                strtoupper($edge['type']),
                strtoupper($edge['confidence']),
                $edge['file'] . ':' . $edge['line'],
            ], $edges),
        );
    }

    private function renderDiagnosticWarning(array $diagnostics): void
    {
        if ($diagnostics !== []) {
            $this->warn(count($diagnostics) . ' PHP file(s) could not be parsed; results may be incomplete.');
        }
    }
}
