<?php

namespace Peralta\AgentKit\Tests\Unit\Events;

use Peralta\AgentKit\Events\AgentKitEvent;
use Peralta\AgentKit\Events\AgentRequestCompleted;
use Peralta\AgentKit\Events\AgentRequestStarted;
use Peralta\AgentKit\Events\ProviderCallCompleted;
use Peralta\AgentKit\Tests\TestCase;

class AgentKitEventTest extends TestCase
{
    public function test_agent_request_started_implements_marker_interface_and_holds_data()
    {
        $event = new AgentRequestStarted(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4o',
        );

        $this->assertInstanceOf(AgentKitEvent::class, $event);
        $this->assertEquals('conv-1', $event->conversationId);
        $this->assertEquals('openai', $event->provider);
        $this->assertEquals('gpt-4o', $event->model);
    }

    public function test_agent_request_completed_holds_duration_and_iterations()
    {
        $event = new AgentRequestCompleted(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4o',
            durationMs: 1234,
            iterations: 3,
        );

        $this->assertInstanceOf(AgentKitEvent::class, $event);
        $this->assertEquals(1234, $event->durationMs);
        $this->assertEquals(3, $event->iterations);
    }

    public function test_provider_call_completed_holds_iteration_number()
    {
        $event = new ProviderCallCompleted(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4o',
            durationMs: 500,
            iterationNumber: 2,
        );

        $this->assertInstanceOf(AgentKitEvent::class, $event);
        $this->assertEquals(2, $event->iterationNumber);
    }
}
