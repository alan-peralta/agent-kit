<?php

namespace Peralta\AgentKit\Contracts;

use Peralta\AgentKit\DTOs\Context;

interface Tool
{
    public function name(): string;
    public function description(): string;
    public function schema(): array;
    public function authorize(Context $context): bool;
    public function handle(array $input, Context $context): mixed;
}
