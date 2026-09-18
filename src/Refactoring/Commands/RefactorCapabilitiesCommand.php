<?php

namespace Peralta\AgentKit\Refactoring\Commands;

use Illuminate\Console\Command;
use Peralta\AgentKit\Refactoring\Application\RefactoringCapabilities;
use Peralta\AgentKit\Refactoring\Commands\Concerns\RendersCapabilityResults;

final class RefactorCapabilitiesCommand extends Command
{
    use RendersCapabilityResults;

    protected $signature = 'agent-kit:refactor-capabilities {--json : Emit JSON only}';

    protected $description = 'Describe deterministic Agent Kit refactoring capabilities';

    public function handle(RefactoringCapabilities $capabilities): int
    {
        $result = $capabilities->describeCapabilities();

        if ($this->option('json')) {
            $this->renderJson($result);

            return self::SUCCESS;
        }

        $this->table(
            ['Capability', 'Targets', 'MCP tool', 'CLI fallback', 'JSON'],
            array_map(static fn (array $item): array => [
                $item['name'],
                implode(', ', $item['targets']),
                $item['mcp_tool'],
                $item['cli_fallback'],
                $item['json'] ? 'yes' : 'no',
            ], $result->data['capabilities']),
        );

        return self::SUCCESS;
    }
}
