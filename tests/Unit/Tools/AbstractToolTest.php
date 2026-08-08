<?php

namespace Peralta\AgentKit\Tests\Unit\Tools;

use Peralta\AgentKit\DTOs\Context;
use Peralta\AgentKit\Tools\AbstractTool;
use PHPUnit\Framework\TestCase;

class AbstractToolTest extends TestCase
{
    public function test_default_authorize_returns_true()
    {
        $tool = new class extends AbstractTool {
            public function name(): string { return 'minimal'; }
            public function description(): string { return 'x'; }
            public function schema(): array { return []; }
            public function handle(array $input, Context $context): mixed { return null; }
        };

        $this->assertTrue($tool->authorize(new Context()));
    }

    public function test_authorize_can_be_overridden()
    {
        $tool = new class extends AbstractTool {
            public function name(): string { return 'restrita'; }
            public function description(): string { return 'x'; }
            public function schema(): array { return []; }
            public function handle(array $input, Context $context): mixed { return null; }
            public function authorize(Context $context): bool { return false; }
        };

        $this->assertFalse($tool->authorize(new Context()));
    }
}
