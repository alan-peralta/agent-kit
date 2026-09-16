<?php

namespace Peralta\AgentKit\Refactoring\Agents;

/** @internal Filesystem seam used to verify publish and recovery failures. */
interface AgentInstallationFilesystem
{
    public function link(string $temporary, string $target): bool;

    public function rename(string $from, string $to): bool;

    public function unlink(string $path): bool;

    public function requiresBackupForOverwrite(): bool;
}
