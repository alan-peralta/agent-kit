<?php

namespace Peralta\AgentKit\Refactoring\Support;

final class RefactoringReport
{
    public function build(array $files, string $root): array
    {
        $issues = ['high' => 0, 'medium' => 0, 'low' => 0];
        $smells = [];

        foreach ($files as $file) {
            foreach ($file->smells as $smell) {
                $severity = $smell['severity'] ?? 'low';
                $issues[$severity] = ($issues[$severity] ?? 0) + 1;
                $smells[$smell['name']] = ($smells[$smell['name']] ?? 0) + 1;
            }
        }

        arsort($smells);

        return [
            'generated_at' => date(DATE_ATOM),
            'project_root' => $root,
            'stack' => $this->detectStack($root),
            'summary' => [
                'php_files' => count($files),
                'lines' => array_sum(array_map(fn ($f) => $f->lines, $files)),
                'issues' => $issues,
                'smells' => $smells,
            ],
            'files' => array_map(fn ($f) => $f->toArray(), $files),
        ];
    }

    public function markdown(array $report, int $limit = 20): string
    {
        $summary = $report['summary'];
        $out = "# Agent Kit Refactoring Audit\n\n";
        $out .= "Generated: {$report['generated_at']}\n\n";
        $out .= "Stack: " . implode(', ', $report['stack']) . "\n\n";
        $out .= "## Summary\n\n";
        $out .= "- PHP files: {$summary['php_files']}\n";
        $out .= "- Lines: {$summary['lines']}\n";
        $out .= "- High: {$summary['issues']['high']}\n";
        $out .= "- Medium: {$summary['issues']['medium']}\n";
        $out .= "- Low: {$summary['issues']['low']}\n\n";
        $out .= "## Smells\n\n";
        foreach ($summary['smells'] as $name => $count) {
            $out .= "- {$name}: {$count}\n";
        }

        $out .= "\n## Priority files\n\n";
        foreach (array_slice($report['files'], 0, $limit) as $file) {
            if (empty($file['smells'])) continue;
            $out .= "### `{$file['path']}`\n\n";
            $out .= "{$file['lines']} lines · {$file['methods']} methods/functions · {$file['dependencies']} imports/uses · {$file['branches']} branches\n\n";
            foreach ($file['smells'] as $smell) {
                $out .= "- **" . strtoupper($smell['severity']) . "** {$smell['name']} — {$smell['reason']}\n";
            }
            $out .= "\n";
        }

        $out .= "## Interpretation rules\n\n";
        $out .= "These metrics are deterministic signals, not architectural verdicts. Confirm each candidate by reading callers, side effects, tests and domain responsibilities before refactoring. A design pattern must solve a concrete problem; it is never a goal by itself.\n";

        return $out;
    }

    private function detectStack(string $root): array
    {
        $stack = ['PHP'];
        if (is_file($root . '/artisan')) $stack[] = 'Laravel';
        if (is_file($root . '/composer.json')) {
            $composer = json_decode((string) file_get_contents($root . '/composer.json'), true) ?: [];
            $requires = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);
            if (isset($requires['laravel/framework']) || isset($requires['illuminate/support'])) $stack[] = 'Laravel/Illuminate';
        }
        return array_values(array_unique($stack));
    }
}
