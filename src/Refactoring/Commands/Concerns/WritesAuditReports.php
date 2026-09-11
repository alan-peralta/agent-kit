<?php

namespace Peralta\AgentKit\Refactoring\Commands\Concerns;

use Peralta\AgentKit\Refactoring\Application\CapabilityException;
use Peralta\AgentKit\Refactoring\Support\RefactoringReport;

trait WritesAuditReports
{
    private function writeAuditReports(
        string $output,
        array $report,
        RefactoringReport $reporter,
        bool $includeBaseline,
    ): void {
        $this->ensureReportDirectory($output);

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

        $destinations = [];
        foreach (array_keys($contents) as $filename) {
            $destination = $output . DIRECTORY_SEPARATOR . $filename;
            $this->preflightReportTarget($destination);
            $destinations[$filename] = $destination;
        }

        $temporary = [];
        $backups = [];
        $installed = [];

        try {
            foreach ($contents as $filename => $content) {
                $temporary[$filename] = $this->writeTemporaryReport($output, $content);
            }

            foreach ($destinations as $filename => $destination) {
                if (file_exists($destination)) {
                    $backup = $this->reserveTemporaryPath($output, '.agent-kit-backup-');
                    if (!@unlink($backup) || !@rename($destination, $backup)) {
                        throw $this->writeFailure("could not replace {$destination}.");
                    }
                    $backups[$filename] = $backup;
                }

                if (!@rename($temporary[$filename], $destination)) {
                    throw $this->writeFailure("could not replace {$destination}.");
                }
                unset($temporary[$filename]);
                $installed[] = $filename;
            }
        } catch (CapabilityException $exception) {
            $this->rollbackReports($destinations, $backups, $installed);
            $this->removeTemporaryReports(array_merge(
                array_values($temporary),
                array_values($backups),
            ));

            throw $exception;
        }

        $this->removeTemporaryReports(array_values($backups));
    }

    private function ensureReportDirectory(string $output): void
    {
        if ((file_exists($output) || is_link($output)) && (!is_dir($output) || is_link($output))) {
            throw $this->writeFailure("{$output} is not a directory.");
        }

        if (!is_dir($output) && !@mkdir($output, 0777, true) && !is_dir($output)) {
            throw $this->writeFailure("could not create output directory {$output}.");
        }

        if (!is_writable($output)) {
            throw $this->writeFailure("output directory {$output} is not writable.");
        }
    }

    private function preflightReportTarget(string $destination): void
    {
        if (!file_exists($destination) && !is_link($destination)) {
            return;
        }

        if (is_link($destination) || !is_file($destination)) {
            throw $this->writeFailure("{$destination} is not a regular file.");
        }

        if (!is_writable($destination)) {
            throw $this->writeFailure("{$destination} is not writable.");
        }
    }

    private function writeTemporaryReport(string $output, string $content): string
    {
        $temporary = $this->reserveTemporaryPath($output, '.agent-kit-report-');
        $bytes = @file_put_contents($temporary, $content, LOCK_EX);
        if ($bytes === false || $bytes !== strlen($content)) {
            @unlink($temporary);

            throw $this->writeFailure("could not write a temporary report in {$output}.");
        }

        return $temporary;
    }

    private function reserveTemporaryPath(string $output, string $prefix): string
    {
        $temporary = @tempnam($output, $prefix);
        if ($temporary === false) {
            throw $this->writeFailure("could not create a temporary report in {$output}.");
        }

        return $temporary;
    }

    /**
     * @param array<string, string> $destinations
     * @param array<string, string> $backups
     * @param list<string> $installed
     */
    private function rollbackReports(array $destinations, array $backups, array $installed): void
    {
        foreach (array_reverse($installed) as $filename) {
            @unlink($destinations[$filename]);
        }

        foreach (array_reverse($backups, true) as $filename => $backup) {
            @rename($backup, $destinations[$filename]);
        }
    }

    /** @param list<string> $temporary */
    private function removeTemporaryReports(array $temporary): void
    {
        foreach ($temporary as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    private function writeFailure(string $reason): CapabilityException
    {
        return new CapabilityException('OUTPUT_WRITE_FAILED', 'Cannot write audit report: ' . $reason);
    }
}
