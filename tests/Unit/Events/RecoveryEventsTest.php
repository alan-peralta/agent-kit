<?php

namespace Peralta\AgentKit\Tests\Unit\Events;

use Peralta\AgentKit\Events\AgentKitEvent;
use Peralta\AgentKit\Events\RecoveryAttempted;
use Peralta\AgentKit\Events\RecoveryExhausted;
use Peralta\AgentKit\Tests\TestCase;

class RecoveryEventsTest extends TestCase
{
    public function test_recovery_attempted_holds_strategy_and_error_class()
    {
        $event = new RecoveryAttempted(
            conversationId: 'conv-1',
            provider: 'openai',
            attemptNumber: 2,
            strategy: 'retry',
            errorClass: 'RATE_LIMIT',
        );

        $this->assertInstanceOf(AgentKitEvent::class, $event);
        $this->assertEquals('retry', $event->strategy);
        $this->assertEquals('RATE_LIMIT', $event->errorClass);
    }

    public function test_recovery_exhausted_holds_attempts_and_final_error()
    {
        $event = new RecoveryExhausted(
            conversationId: 'conv-1',
            attempts: 5,
            finalErrorClass: 'SERVER_ERROR',
        );

        $this->assertInstanceOf(AgentKitEvent::class, $event);
        $this->assertEquals(5, $event->attempts);
        $this->assertEquals('SERVER_ERROR', $event->finalErrorClass);
    }
}
