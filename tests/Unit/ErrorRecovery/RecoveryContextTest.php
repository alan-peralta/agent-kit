<?php

namespace Peralta\AgentKit\Tests\Unit\ErrorRecovery;

use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\Tests\TestCase;

class RecoveryContextTest extends TestCase
{
    public function test_tracks_attempts_per_provider()
    {
        $ctx = new RecoveryContext(provider: 'openai');

        $ctx->recordAttempt('openai');
        $ctx->recordAttempt('openai');

        $this->assertEquals(2, $ctx->attemptsFor('openai'));
        $this->assertEquals(0, $ctx->attemptsFor('anthropic'));
    }

    public function test_switch_provider_resets_fallback_flag_tracking()
    {
        $ctx = new RecoveryContext(provider: 'openai');
        $this->assertFalse($ctx->fallbackUsed);

        $ctx->switchProvider('anthropic');

        $this->assertEquals('anthropic', $ctx->provider);
        $this->assertTrue($ctx->fallbackUsed);
    }

    public function test_attempt_log_summary()
    {
        $ctx = new RecoveryContext(provider: 'openai');
        $ctx->recordAttempt('openai');
        $ctx->recordFailure('openai', 'NETWORK_TIMEOUT');
        $ctx->switchProvider('anthropic');
        $ctx->recordAttempt('anthropic');
        $ctx->recordFailure('anthropic', 'AUTH_ERROR');

        $summary = $ctx->summary();

        $this->assertEquals([
            'openai' => ['attempts' => 1, 'last_error' => 'NETWORK_TIMEOUT'],
            'anthropic' => ['attempts' => 1, 'last_error' => 'AUTH_ERROR'],
        ], $summary);
    }
}
