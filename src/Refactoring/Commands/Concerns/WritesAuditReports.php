<?php

namespace Peralta\AgentKit\Refactoring\Commands\Concerns;

use Peralta\AgentKit\Refactoring\Commands\Support\AtomicAuditReportWriter;
use Peralta\AgentKit\Refactoring\Commands\Support\NativeReportFilesystem;
use Peralta\AgentKit\Refactoring\Support\RefactoringReport;

trait WritesAuditReports
{
    private function writeAuditReports(
        string $output,
        array $report,
        RefactoringReport $reporter,
        bool $includeBaseline,
    ): void {
        $contents = [
            'audit.json' => json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
            'audit.md' => $reporter->markdown($report),
        ];
        if ($includeBaseline) {
            $contents['baseline.json'] = json_encode(
                $report['summary'],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        }

        (new AtomicAuditReportWriter(new NativeReportFilesystem()))->write($output, $contents);
    }
}
