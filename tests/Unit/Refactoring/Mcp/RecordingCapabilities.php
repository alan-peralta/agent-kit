<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp;

use Peralta\AgentKit\Refactoring\Application\CapabilityException;
use Peralta\AgentKit\Refactoring\Application\CapabilityResult;
use Peralta\AgentKit\Refactoring\Application\RefactoringCapabilities;

final class RecordingCapabilities implements RefactoringCapabilities
{
    /** @var list<array{0: string, 1: array}> */
    public array $calls = [];

    public ?CapabilityException $failure = null;

    public array $diagnostics = [];

    public array $unresolved = [];

    public function describeCapabilities(): CapabilityResult
    {
        return $this->record('describeCapabilities', [], 'capability_discovery', ['capabilities' => [
            ['name' => 'audit', 'targets' => ['project'], 'mcp_tool' => 'refactoring_audit', 'cli_fallback' => 'php artisan agent-kit:refactor-audit --json', 'json' => true],
            ['name' => 'analyze', 'targets' => ['file', 'class', 'method'], 'mcp_tool' => 'refactoring_analyze', 'cli_fallback' => 'php artisan agent-kit:refactor-analyze <target> --json', 'json' => true],
            ['name' => 'find_callers', 'targets' => ['class', 'method'], 'mcp_tool' => 'refactoring_callers', 'cli_fallback' => 'php artisan agent-kit:refactor-callers <class> --method=<method> --json', 'json' => true],
            ['name' => 'dependencies', 'targets' => ['class'], 'mcp_tool' => 'refactoring_dependencies', 'cli_fallback' => 'php artisan agent-kit:refactor-dependencies <class> --json', 'json' => true],
            ['name' => 'impact', 'targets' => ['class', 'method'], 'mcp_tool' => 'refactoring_impact', 'cli_fallback' => 'php artisan agent-kit:refactor-impact <class> --method=<method> --json', 'json' => true],
        ]]);
    }

    public function audit(string $projectRoot): CapabilityResult
    {
        return $this->record('audit', [$projectRoot], 'audit', ['project_root' => $projectRoot]);
    }

    public function analyze(string $projectRoot, string $target): CapabilityResult
    {
        return $this->record('analyze', [$projectRoot, $target], 'analyze', ['target' => $target]);
    }

    public function findCallers(string $projectRoot, string $target): CapabilityResult
    {
        return $this->record('findCallers', [$projectRoot, $target], 'find_callers', ['target' => $target]);
    }

    public function dependencies(string $projectRoot, string $target): CapabilityResult
    {
        return $this->record('dependencies', [$projectRoot, $target], 'dependencies', ['target' => $target]);
    }

    public function impact(string $projectRoot, string $target): CapabilityResult
    {
        return $this->record('impact', [$projectRoot, $target], 'impact', ['target' => $target]);
    }

    private function record(string $method, array $arguments, string $capability, array $data): CapabilityResult
    {
        $this->calls[] = [$method, $arguments];
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new CapabilityResult($capability, $data, $this->diagnostics, $this->unresolved);
    }
}
