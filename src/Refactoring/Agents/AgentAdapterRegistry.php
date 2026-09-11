<?php

namespace Peralta\AgentKit\Refactoring\Agents;

use InvalidArgumentException;

final class AgentAdapterRegistry
{
    /** @var array<string, AgentAdapter> */
    private array $adapters = [];

    /** @param list<AgentAdapter> $adapters */
    public function __construct(array $adapters)
    {
        foreach ($adapters as $adapter) {
            $id = $adapter->id();

            if (trim($id) === '') {
                throw new InvalidArgumentException('Coding agent adapter ID must not be empty.');
            }

            if (isset($this->adapters[$id])) {
                throw new InvalidArgumentException("Duplicate coding agent adapter ID: {$id}");
            }

            $this->adapters[$id] = $adapter;
        }
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->adapters);
    }

    public function get(string $id): AgentAdapter
    {
        return $this->adapters[$id]
            ?? throw new InvalidArgumentException("Unsupported coding agent: {$id}");
    }
}
