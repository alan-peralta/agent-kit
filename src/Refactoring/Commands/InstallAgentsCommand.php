<?php

namespace Peralta\AgentKit\Refactoring\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Peralta\AgentKit\Refactoring\Agents\AgentAdapterRegistry;
use Peralta\AgentKit\Refactoring\Agents\AgentConfigurationInstaller;
use Peralta\AgentKit\Refactoring\Agents\InstallationResult;
use RuntimeException;

final class InstallAgentsCommand extends Command
{
    protected $signature = 'agent-kit:agents:install
        {agents?* : cursor and/or claude}
        {--all : Install every supported adapter}
        {--path= : Consumer project root}
        {--force : Overwrite conflicting Agent Kit-dedicated files}';

    protected $description = 'Install Agent Kit refactoring skills for coding agents';

    public function handle(
        AgentConfigurationInstaller $installer,
        AgentAdapterRegistry $registry,
    ): int {
        try {
            $agents = $this->selectedAgents($registry);
            if ($agents === []) {
                $this->error('Select at least one coding agent or use --all.');

                return self::FAILURE;
            }

            $this->validateAgents($agents, $registry);
            $path = $this->option('path');
            $result = $installer->install(
                is_string($path) && $path !== '' ? $path : base_path(),
                $agents,
                (bool) $this->option('force'),
            );

            $this->renderResult($result);

            return $result->successful() ? self::SUCCESS : self::FAILURE;
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return list<string> */
    private function selectedAgents(AgentAdapterRegistry $registry): array
    {
        if ((bool) $this->option('all')) {
            return $registry->ids();
        }

        $agents = $this->argument('agents');
        if (is_array($agents) && $agents !== []) {
            return array_values($agents);
        }

        if (!$this->input->isInteractive()) {
            return [];
        }

        $selected = $this->choice(
            'Select coding agents to install',
            $registry->ids(),
            default: null,
            attempts: null,
            multiple: true,
        );

        return is_array($selected) ? array_values($selected) : [$selected];
    }

    /** @param list<string> $agents */
    private function validateAgents(array $agents, AgentAdapterRegistry $registry): void
    {
        $seen = [];

        foreach ($agents as $agent) {
            if (isset($seen[$agent])) {
                throw new InvalidArgumentException("Duplicate coding agent: {$agent}");
            }

            $registry->get($agent);
            $seen[$agent] = true;
        }
    }

    private function renderResult(InstallationResult $result): void
    {
        foreach ($result->created as $path) {
            $this->line("CREATED {$path}");
        }

        foreach ($result->unchanged as $path) {
            $this->line("UNCHANGED {$path}");
        }

        foreach ($result->overwritten as $path) {
            $this->line("OVERWRITTEN {$path}");
        }

        foreach ($result->conflicts as $path) {
            $this->line("CONFLICTS {$path}");
        }
    }
}
