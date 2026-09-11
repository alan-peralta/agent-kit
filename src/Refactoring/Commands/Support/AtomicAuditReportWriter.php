<?php

namespace Peralta\AgentKit\Refactoring\Commands\Support;

use Peralta\AgentKit\Refactoring\Application\CapabilityException;

final class AtomicAuditReportWriter
{
    public function __construct(private readonly ReportFilesystem $filesystem) {}

    /** @param array<string, string> $reports */
    public function write(string $output, array $reports): void
    {
        $this->ensureDirectory($output);

        $destinations = [];
        foreach (array_keys($reports) as $filename) {
            $destination = $output . DIRECTORY_SEPARATOR . $filename;
            $this->preflight($destination);
            $destinations[$filename] = $destination;
        }

        $temporary = [];
        $backups = [];
        $installed = [];

        try {
            foreach ($reports as $filename => $contents) {
                $temporary[$filename] = $this->writeTemporary($output, $contents);
            }

            foreach ($destinations as $filename => $destination) {
                if ($this->filesystem->exists($destination)) {
                    $backup = $this->filesystem->uniqueBackupPath($output);
                    if (!$this->filesystem->move($destination, $backup)) {
                        throw $this->failure("could not replace {$destination}.");
                    }
                    $backups[$filename] = $backup;
                }

                if (!$this->filesystem->move($temporary[$filename], $destination)) {
                    throw $this->failure("could not replace {$destination}.");
                }
                unset($temporary[$filename]);
                $installed[] = $filename;
            }
        } catch (CapabilityException $exception) {
            $rollbackFailures = $this->rollback($destinations, $backups, $installed);
            $cleanupFailures = $this->cleanup(array_values($temporary));

            throw $this->withRecoveryFailures($exception, array_merge($rollbackFailures, $cleanupFailures));
        }

        $cleanupFailures = $this->cleanup(array_values($backups));
        if ($cleanupFailures !== []) {
            throw $this->failure(implode(' ', $cleanupFailures));
        }
    }

    private function ensureDirectory(string $output): void
    {
        if ($this->filesystem->exists($output) && !$this->filesystem->isDirectory($output)) {
            throw $this->failure("{$output} is not a directory.");
        }

        if (
            !$this->filesystem->isDirectory($output)
            && !$this->filesystem->makeDirectory($output)
            && !$this->filesystem->isDirectory($output)
        ) {
            throw $this->failure("could not create output directory {$output}.");
        }

        if (!$this->filesystem->isWritable($output)) {
            throw $this->failure("output directory {$output} is not writable.");
        }
    }

    private function preflight(string $destination): void
    {
        if (!$this->filesystem->exists($destination)) {
            return;
        }

        if (!$this->filesystem->isRegularFile($destination)) {
            throw $this->failure("{$destination} is not a regular file.");
        }

        if (!$this->filesystem->isWritable($destination)) {
            throw $this->failure("{$destination} is not writable.");
        }
    }

    private function writeTemporary(string $output, string $contents): string
    {
        $temporary = $this->filesystem->createTemporaryFile($output);
        if ($temporary === false) {
            throw $this->failure("could not create a temporary report in {$output}.");
        }

        $bytes = $this->filesystem->write($temporary, $contents);
        if ($bytes === false || $bytes !== strlen($contents)) {
            $reason = "could not write a temporary report in {$output}.";
            if ($this->filesystem->exists($temporary) && !$this->filesystem->delete($temporary)) {
                $reason .= " Could not remove temporary report {$temporary}; it was preserved.";
            }

            throw $this->failure($reason);
        }

        return $temporary;
    }

    /**
     * @param array<string, string> $destinations
     * @param array<string, string> $backups
     * @param list<string> $installed
     * @return list<string>
     */
    private function rollback(array $destinations, array $backups, array $installed): array
    {
        $installed = array_fill_keys($installed, true);
        $failures = [];

        foreach (array_reverse(array_keys($destinations)) as $filename) {
            $destination = $destinations[$filename];
            $backup = $backups[$filename] ?? null;

            if (isset($installed[$filename]) && $this->filesystem->exists($destination)) {
                if (!$this->filesystem->delete($destination)) {
                    $failures[] = $backup === null
                        ? "Could not remove partial report {$destination}."
                        : "Could not remove partial report {$destination}. Recovery backup preserved at {$backup}.";
                    continue;
                }
            }

            if ($backup !== null && !$this->filesystem->move($backup, $destination)) {
                $failures[] = "Could not restore {$destination}. Recovery backup preserved at {$backup}.";
            }
        }

        return $failures;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function cleanup(array $paths): array
    {
        $failures = [];
        foreach ($paths as $path) {
            if ($this->filesystem->exists($path) && !$this->filesystem->delete($path)) {
                $failures[] = "Could not remove temporary report {$path}; it was preserved.";
            }
        }

        return $failures;
    }

    /** @param list<string> $failures */
    private function withRecoveryFailures(CapabilityException $exception, array $failures): CapabilityException
    {
        if ($failures === []) {
            return $exception;
        }

        return new CapabilityException(
            'OUTPUT_WRITE_FAILED',
            $exception->getMessage() . ' ' . implode(' ', $failures),
        );
    }

    private function failure(string $reason): CapabilityException
    {
        return new CapabilityException('OUTPUT_WRITE_FAILED', 'Cannot write audit report: ' . $reason);
    }
}
