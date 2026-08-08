<?php

namespace Peralta\AgentKit\Tests\Feature;

use Peralta\AgentKit\Agent;
use Peralta\AgentKit\Contracts\Provider;
use Peralta\AgentKit\DTOs\AgentResponse;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\Models\AgentMetric;
use Peralta\AgentKit\Tests\TestCase;

class AnalyticsIntegrationTest extends TestCase
{
    protected function defineDatabaseMigrations()
    {
        $migration = require __DIR__ . '/../../database/migrations/2026_08_08_000003_create_agent_kit_metrics_table.php';
        $migration->up();
    }

    public function test_full_send_call_persists_all_expected_metric_rows()
    {
        config([
            'agent-kit.analytics.enabled' => true,
            'agent-kit.analytics.persist' => true,
            'agent-kit.logging.enabled' => false,
        ]);

        $provider = \Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')->once()->andReturn(new AgentResponse(
            message: Message::assistant('oi'),
            stopReason: 'stop',
            inputTokens: 20,
            outputTokens: 8,
        ));

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $agent = $this->app->make(Agent::class);
        $agent->provider('openai')->conversation('conv-int-1')->send('olá');

        $types = AgentMetric::pluck('type')->all();

        $this->assertContains('request_started', $types);
        $this->assertContains('provider_call', $types);
        $this->assertContains('token_usage', $types);
        $this->assertContains('request_completed', $types);

        $usageRow = AgentMetric::where('type', 'token_usage')->first();
        $this->assertEquals(20, $usageRow->payload['inputTokens']);
        $this->assertEquals(8, $usageRow->payload['outputTokens']);
    }

    public function test_analytics_disabled_persists_nothing()
    {
        config([
            'agent-kit.analytics.enabled' => false,
            'agent-kit.analytics.persist' => true,
            'agent-kit.logging.enabled' => false,
        ]);

        $provider = \Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')->once()->andReturn(new AgentResponse(
            message: Message::assistant('oi'),
            stopReason: 'stop',
            inputTokens: 20,
            outputTokens: 8,
        ));

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $agent = $this->app->make(Agent::class);
        $agent->provider('openai')->send('olá');

        $this->assertDatabaseCount('agent_kit_metrics', 0);
    }
}
