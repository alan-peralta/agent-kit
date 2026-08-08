<?php

namespace Peralta\AgentKit\Tests\Unit\Providers\Concerns;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Peralta\AgentKit\Contracts\Tool;
use Peralta\AgentKit\DTOs\Context;

trait MocksGuzzleHttp
{
    private function mockHandlerStack(array $responses): array
    {
        $history = new \ArrayObject();
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return [$stack, $history];
    }

    private function fakeTool(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'search'; }
            public function description(): string { return 'Busca coisas'; }
            public function schema(): array { return ['type' => 'object']; }
            public function authorize(Context $context): bool { return true; }
            public function handle(array $input, Context $context): mixed { return []; }
        };
    }
}
