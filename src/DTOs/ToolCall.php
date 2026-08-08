<?php

namespace Peralta\AgentKit\DTOs;

class ToolCall
{
    public function __construct(
        public readonly string $id,        // ID único da chamada (gerado pelo provider)
        public readonly string $name,      // nome da tool
        public readonly array $arguments,  // argumentos parseados (já decodificados de JSON)
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'arguments' => $this->arguments,
        ];
    }
}
