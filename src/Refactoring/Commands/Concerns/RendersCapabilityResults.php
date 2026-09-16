<?php

namespace Peralta\AgentKit\Refactoring\Commands\Concerns;

use Peralta\AgentKit\Refactoring\Application\CapabilityException;
use Peralta\AgentKit\Refactoring\Application\CapabilityResult;

trait RendersCapabilityResults
{
    private function renderJson(CapabilityResult $result): void
    {
        $this->line(json_encode(
            $result->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function renderCapabilityFailure(CapabilityException $exception, bool $json): int
    {
        if ($json) {
            $this->line(json_encode(
                $exception->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
        } else {
            $this->error($exception->getMessage());
        }

        return self::FAILURE;
    }
}
