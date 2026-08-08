<?php

namespace Peralta\AgentKit\Tests\Unit\Models;

use Peralta\AgentKit\Models\AgentMetric;
use Peralta\AgentKit\Tests\TestCase;

class AgentMetricTest extends TestCase
{
    protected function defineDatabaseMigrations()
    {
        $migration = require __DIR__ . '/../../../database/migrations/2026_08_08_000003_create_agent_kit_metrics_table.php';
        $migration->up();
    }

    public function test_can_create_and_read_a_metric_row()
    {
        $metric = AgentMetric::create([
            'type' => 'token_usage',
            'conversation_id' => 'conv-1',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'duration_ms' => 120,
            'payload' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);

        $this->assertDatabaseHas('agent_kit_metrics', [
            'id' => $metric->id,
            'type' => 'token_usage',
            'conversation_id' => 'conv-1',
        ]);

        $fresh = AgentMetric::find($metric->id);
        $this->assertEquals(['input_tokens' => 10, 'output_tokens' => 5], $fresh->payload);
    }
}
