<?php

namespace Peralta\AgentKit\Tests\Unit\ErrorRecovery;

use Mockery;
use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\ErrorRecovery\Contracts\Middleware;
use Peralta\AgentKit\ErrorRecovery\Pipeline;
use Peralta\AgentKit\Tests\TestCase;

class PipelineTest extends TestCase
{
    public function test_executes_middleware_in_order_outermost_first()
    {
        $calls = [];

        $outer = Mockery::mock(Middleware::class);
        $outer->shouldReceive('handle')->once()->andReturnUsing(function ($handler, $ctx) use (&$calls) {
            $calls[] = 'outer:before';
            $result = $handler($ctx);
            $calls[] = 'outer:after';
            return $result;
        });

        $inner = Mockery::mock(Middleware::class);
        $inner->shouldReceive('handle')->once()->andReturnUsing(function ($handler, $ctx) use (&$calls) {
            $calls[] = 'inner:before';
            $result = $handler($ctx);
            $calls[] = 'inner:after';
            return $result;
        });

        $pipeline = new Pipeline([$outer, $inner]);

        $result = $pipeline->execute(function (RecoveryContext $ctx) use (&$calls) {
            $calls[] = 'handler';
            return 'done';
        }, new RecoveryContext(provider: 'openai'));

        $this->assertEquals('done', $result);
        $this->assertEquals(
            ['outer:before', 'inner:before', 'handler', 'inner:after', 'outer:after'],
            $calls,
        );
    }
}
