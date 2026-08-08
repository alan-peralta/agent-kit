<?php

namespace Peralta\AgentKit\Tests\Unit\Support;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Peralta\AgentKit\Events\TokenUsageRecorded;
use Peralta\AgentKit\Support\SafeEventDispatcher;
use Peralta\AgentKit\Tests\TestCase;

class SafeEventDispatcherTest extends TestCase
{
    public function test_dispatch_swallows_listener_exceptions()
    {
        config(['agent-kit.logging.enabled' => false]);

        Log::spy();

        Event::listen(TokenUsageRecorded::class, function () {
            throw new \RuntimeException('boom');
        });

        SafeEventDispatcher::dispatch(new TokenUsageRecorded(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4',
            inputTokens: 10,
            outputTokens: 5,
        ));

        Log::shouldHaveReceived('warning')->once()->with(
            '[agent-kit] Falha ao despachar evento de analytics',
            [
                'event' => TokenUsageRecorded::class,
                'error' => 'boom',
            ]
        );
    }

    public function test_dispatch_does_nothing_when_analytics_disabled()
    {
        config(['agent-kit.analytics.enabled' => false]);

        Event::fake();

        SafeEventDispatcher::dispatch(new TokenUsageRecorded(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4',
            inputTokens: 10,
            outputTokens: 5,
        ));

        Event::assertNotDispatched(TokenUsageRecorded::class);
    }

    public function test_dispatch_forwards_event_when_analytics_enabled()
    {
        config(['agent-kit.analytics.enabled' => true]);

        Event::fake();

        SafeEventDispatcher::dispatch(new TokenUsageRecorded(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4',
            inputTokens: 10,
            outputTokens: 5,
        ));

        Event::assertDispatched(TokenUsageRecorded::class);
    }
}
