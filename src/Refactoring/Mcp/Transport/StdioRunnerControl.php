<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport;

use Mcp\Server\Transport\Stdio\RunnerControlInterface;
use Mcp\Server\Transport\Stdio\RunnerState;

final class StdioRunnerControl implements RunnerControlInterface
{
    private RunnerState $state = RunnerState::RUNNING;

    public function getState(): RunnerState
    {
        return $this->state;
    }

    public function stop(): void
    {
        $this->state = RunnerState::STOP_AND_END_SESSION;
    }
}
