<?php

namespace Peralta\AgentKit\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Peralta\AgentKit\Agent;
use Peralta\AgentKit\Contracts\Provider;
use Peralta\AgentKit\DTOs\AgentResponse;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\ErrorRecovery\Pipeline;
use Peralta\AgentKit\Events\AgentRequestCompleted;
use Peralta\AgentKit\Events\AgentRequestStarted;
use Peralta\AgentKit\Events\ProviderCallCompleted;
use Peralta\AgentKit\Tests\TestCase;

class AgentAnalyticsEventsTest extends TestCase
{
    public function test_send_dispatches_request_started_completed_and_provider_call_events()
    {
        Event::fake();

        $provider = \Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')->once()->andReturn(new AgentResponse(
            message: Message::assistant('oi'),
            stopReason: 'stop',
            inputTokens: 10,
            outputTokens: 5,
        ));

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $agent = $this->app->make(Agent::class);
        $agent->provider('openai')->send('olá');

        Event::assertDispatched(AgentRequestStarted::class, fn($e) => $e->provider === 'openai');
        Event::assertDispatched(ProviderCallCompleted::class, fn($e) => $e->iterationNumber === 1);
        Event::assertDispatched(AgentRequestCompleted::class, fn($e) => $e->iterations === 1);
    }

    public function test_send_does_not_dispatch_events_when_analytics_disabled()
    {
        Event::fake();

        config(['agent-kit.analytics.enabled' => false]);

        $provider = \Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')->once()->andReturn(new AgentResponse(
            message: Message::assistant('oi'),
            stopReason: 'stop',
            inputTokens: 10,
            outputTokens: 5,
        ));

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $agent = $this->app->make(Agent::class);
        $agent->provider('openai')->send('olá');

        Event::assertNotDispatched(AgentRequestStarted::class);
        Event::assertNotDispatched(ProviderCallCompleted::class);
        Event::assertNotDispatched(AgentRequestCompleted::class);
    }
}
