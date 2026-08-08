<?php

namespace Peralta\AgentKit\Tools;

use Peralta\AgentKit\Contracts\Tool;
use Peralta\AgentKit\DTOs\Context;

abstract class AbstractTool implements Tool
{
    abstract public function name(): string;
    abstract public function description(): string;
    abstract public function schema(): array;
    abstract public function handle(array $input, Context $context): mixed;

    /**
     * Sobrescreva pra controle de permissão.
     */
    public function authorize(Context $context): bool
    {
        return true;
    }
}
