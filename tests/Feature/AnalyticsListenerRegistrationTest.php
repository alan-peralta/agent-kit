<?php

namespace Peralta\AgentKit\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Peralta\AgentKit\Events\TokenUsageRecorded;
use Peralta\AgentKit\Tests\TestCase;

class AnalyticsListenerRegistrationTest extends TestCase
{
    protected function defineDatabaseMigrations()
    {
        $migration = require __DIR__ . '/../../database/migrations/2026_08_08_000003_create_agent_kit_metrics_table.php';
        $migration->up();
    }

    public function test_log_usage_listener_fires_when_logging_enabled()
    {
        config(['agent-kit.logging.enabled' => true, 'agent-kit.logging.channel' => 'stack']);
        Log::shouldReceive('channel')->with('stack')->andReturnSelf();
        Log::shouldReceive('info')->once();

        event(new TokenUsageRecorded(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4o',
            inputTokens: 1,
            outputTokens: 1,
        ));
    }

    public function test_persist_metrics_listener_fires_when_analytics_persist_enabled()
    {
        config(['agent-kit.analytics.persist' => true, 'agent-kit.logging.enabled' => false]);

        event(new TokenUsageRecorded(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4o',
            inputTokens: 1,
            outputTokens: 1,
        ));

        $this->assertDatabaseHas('agent_kit_metrics', ['conversation_id' => 'conv-1']);
    }

    public function test_persist_metrics_listener_does_not_fire_when_analytics_persist_disabled()
    {
        config(['agent-kit.analytics.persist' => false, 'agent-kit.logging.enabled' => false]);

        event(new TokenUsageRecorded(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4o',
            inputTokens: 1,
            outputTokens: 1,
        ));

        $this->assertDatabaseCount('agent_kit_metrics', 0);
    }
}
