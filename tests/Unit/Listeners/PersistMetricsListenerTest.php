<?php

namespace Peralta\AgentKit\Tests\Unit\Listeners;

use Peralta\AgentKit\Events\AgentRequestCompleted;
use Peralta\AgentKit\Events\ToolCallExecuted;
use Peralta\AgentKit\Listeners\PersistMetricsListener;
use Peralta\AgentKit\Models\AgentMetric;
use Peralta\AgentKit\Tests\TestCase;

class PersistMetricsListenerTest extends TestCase
{
    protected function defineDatabaseMigrations()
    {
        $migration = require __DIR__ . '/../../../database/migrations/2026_08_08_000003_create_agent_kit_metrics_table.php';
        $migration->up();
    }

    public function test_persists_agent_request_completed_with_common_columns()
    {
        config(['agent-kit.analytics.persist' => true]);

        (new PersistMetricsListener())->handle(new AgentRequestCompleted(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4o',
            durationMs: 250,
            iterations: 2,
        ));

        $this->assertDatabaseHas('agent_kit_metrics', [
            'type' => 'request_completed',
            'conversation_id' => 'conv-1',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'duration_ms' => 250,
        ]);

        $row = AgentMetric::first();
        $this->assertEquals(2, $row->payload['iterations']);
    }

    public function test_persists_tool_call_executed_without_provider_or_duration()
    {
        config(['agent-kit.analytics.persist' => true]);

        (new PersistMetricsListener())->handle(new ToolCallExecuted(
            conversationId: 'conv-1',
            toolName: 'search',
            success: true,
        ));

        $this->assertDatabaseHas('agent_kit_metrics', [
            'type' => 'tool_call',
            'conversation_id' => 'conv-1',
        ]);

        $row = AgentMetric::first();
        $this->assertEquals('search', $row->payload['toolName']);
        $this->assertTrue($row->payload['success']);
    }

    public function test_swallows_exceptions_instead_of_throwing()
    {
        config(['agent-kit.analytics.persist' => true, 'agent-kit.analytics.table' => 'a_table_that_does_not_exist']);

        (new PersistMetricsListener())->handle(new ToolCallExecuted(
            conversationId: 'conv-1',
            toolName: 'search',
            success: true,
        ));

        $this->assertDatabaseCount('agent_kit_metrics', 0);
    }
}
