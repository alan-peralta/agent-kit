<?php

namespace Peralta\AgentKit\Tests\Unit\ErrorRecovery;

use Peralta\AgentKit\ErrorRecovery\Enums\ErrorType;
use Peralta\AgentKit\ErrorRecovery\Strategies\AdaptiveRetryStrategy;
use Peralta\AgentKit\Tests\TestCase;

class AdaptiveRetryStrategyTest extends TestCase
{
    private function policies(): array
    {
        return [
            'NETWORK_TIMEOUT' => [
                'max_attempts' => 7,
                'initial_delay_ms' => 1000,
                'max_delay_ms' => 30000,
                'multiplier' => 2.0,
                'jitter' => false,
            ],
            'AUTH_ERROR' => [
                'max_attempts' => 0,
            ],
        ];
    }

    public function test_should_retry_while_under_max_attempts()
    {
        $strategy = new AdaptiveRetryStrategy($this->policies());

        $this->assertTrue($strategy->shouldRetry(ErrorType::NETWORK_TIMEOUT, 1));
        $this->assertTrue($strategy->shouldRetry(ErrorType::NETWORK_TIMEOUT, 6));
        $this->assertFalse($strategy->shouldRetry(ErrorType::NETWORK_TIMEOUT, 7));
    }

    public function test_zero_max_attempts_never_retries()
    {
        $strategy = new AdaptiveRetryStrategy($this->policies());

        $this->assertFalse($strategy->shouldRetry(ErrorType::AUTH_ERROR, 1));
    }

    public function test_unknown_error_type_defaults_to_no_retry()
    {
        $strategy = new AdaptiveRetryStrategy([]);

        $this->assertFalse($strategy->shouldRetry(ErrorType::INVALID_REQUEST, 1));
    }

    public function test_delay_grows_exponentially_without_jitter()
    {
        $strategy = new AdaptiveRetryStrategy($this->policies());

        $this->assertEquals(1000, $strategy->delayMs(ErrorType::NETWORK_TIMEOUT, 1));
        $this->assertEquals(2000, $strategy->delayMs(ErrorType::NETWORK_TIMEOUT, 2));
        $this->assertEquals(4000, $strategy->delayMs(ErrorType::NETWORK_TIMEOUT, 3));
    }

    public function test_delay_is_capped_at_max_delay_ms()
    {
        $strategy = new AdaptiveRetryStrategy($this->policies());

        // 1000 * 2^9 = 512000, way above max_delay_ms of 30000
        $this->assertEquals(30000, $strategy->delayMs(ErrorType::NETWORK_TIMEOUT, 10));
    }

    public function test_jitter_adds_randomness_within_bounds()
    {
        $policies = $this->policies();
        $policies['NETWORK_TIMEOUT']['jitter'] = true;
        $strategy = new AdaptiveRetryStrategy($policies);

        $delay = $strategy->delayMs(ErrorType::NETWORK_TIMEOUT, 1);

        // base delay is 1000ms; jitter adds between 0 and base delay (see impl)
        $this->assertGreaterThanOrEqual(1000, $delay);
        $this->assertLessThanOrEqual(2000, $delay);
    }
}
