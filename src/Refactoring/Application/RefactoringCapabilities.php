<?php

namespace Peralta\AgentKit\Refactoring\Application;

interface RefactoringCapabilities
{
    public function describeCapabilities(): CapabilityResult;

    public function audit(string $projectRoot): CapabilityResult;

    public function analyze(string $projectRoot, string $target): CapabilityResult;

    public function findCallers(string $projectRoot, string $target): CapabilityResult;

    public function dependencies(string $projectRoot, string $target): CapabilityResult;

    public function impact(string $projectRoot, string $target): CapabilityResult;
}
