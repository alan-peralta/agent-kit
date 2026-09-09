<?php

namespace Peralta\AgentKit\Refactoring\Support;

use Peralta\AgentKit\Refactoring\DTOs\FileAnalysis;

final class PhpFileAnalyzer
{
    public function __construct(private readonly array $thresholds = []) {}

    public function analyze(string $file, ?string $displayPath = null): FileAnalysis
    {
        $code = file_get_contents($file);
        if ($code === false) {
            throw new \RuntimeException("Não foi possível ler {$file}.");
        }

        $tokens = token_get_all($code);
        $methods = 0;
        $dependencies = 0;
        $branches = 0;

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }

            $id = $token[0];
            if ($id === T_FUNCTION) {
                $methods++;
            } elseif ($id === T_USE) {
                $dependencies++;
            } elseif (in_array($id, [T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE, T_CASE, T_CATCH, T_MATCH], true)) {
                $branches++;
            }
        }

        $lines = substr_count($code, "\n") + 1;
        $smells = [];

        if ($lines >= $this->threshold('large_class_lines', 500)) {
            $smells[] = ['name' => 'Large Class', 'severity' => 'high', 'reason' => "{$lines} linhas"];
        }
        if ($methods >= $this->threshold('many_methods', 20)) {
            $smells[] = ['name' => 'Many Methods', 'severity' => 'medium', 'reason' => "{$methods} métodos/funções"];
        }
        if ($dependencies >= $this->threshold('many_dependencies', 12)) {
            $smells[] = ['name' => 'High Coupling Candidate', 'severity' => 'high', 'reason' => "{$dependencies} imports/uses"];
        }
        if ($branches >= $this->threshold('high_branching', 25)) {
            $smells[] = ['name' => 'High Branching', 'severity' => 'medium', 'reason' => "{$branches} pontos de decisão"];
        }

        return new FileAnalysis(
            path: $displayPath ?? $file,
            lines: $lines,
            methods: $methods,
            dependencies: $dependencies,
            branches: $branches,
            smells: $smells,
        );
    }

    private function threshold(string $key, int $default): int
    {
        return (int) ($this->thresholds[$key] ?? $default);
    }
}
