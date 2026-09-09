<?php

namespace Peralta\AgentKit\Refactoring\Commands;

use Illuminate\Console\Command;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexer;

final class RefactorDependenciesCommand extends Command
{
    protected $signature = 'agent-kit:refactor-dependencies
        {class : Fully qualified target class}
        {--path= : Project root; defaults to the Laravel base path}
        {--json : Emit JSON only}';

    protected $description = 'List typed outgoing dependencies of a PHP class';

    public function handle(CodebaseIndexer $indexer): int
    {
        try {
            $root = $this->root();
            $index = $indexer->build($root);
            $target = ltrim((string) $this->argument('class'), '\\');
            if ($index->findClass($target) === null) {
                throw new \InvalidArgumentException("Classe não encontrada no índice: {$target}");
            }
            $data = [
                'target' => $target,
                'dependencies' => array_map(fn ($edge) => $edge->toArray(), $index->findDependencies($target)),
                'diagnostics' => array_map(fn ($diagnostic) => $diagnostic->toArray(), $index->diagnostics()),
            ];
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('REFACTORING DEPENDENCY ANALYSIS');
        $this->line("Target: {$target}");
        $this->table(
            ['Target', 'Method', 'Type', 'Confidence', 'Location'],
            array_map(fn (array $edge) => [
                $edge['target'],
                $edge['target_method'] ?? '-',
                strtoupper($edge['type']),
                strtoupper($edge['confidence']),
                $edge['file'] . ':' . $edge['line'],
            ], $data['dependencies']),
        );
        if ($data['diagnostics'] !== []) {
            $this->warn(count($data['diagnostics']) . ' PHP file(s) could not be parsed; results may be incomplete.');
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
}
