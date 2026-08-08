<?php

namespace Peralta\AgentKit\Tests\Feature\ErrorRecovery;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Peralta\AgentKit\Agent;
use Peralta\AgentKit\ErrorRecovery\Contracts\AlertNotifier;
use Peralta\AgentKit\Exceptions\AllProvidersFailedException;
use Peralta\AgentKit\Tests\TestCase;

class ErrorRecoveryIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'agent-kit.providers.openai.api_key' => 'test-key',
            'agent-kit.providers.anthropic.api_key' => 'test-key',
            'agent-kit.providers.gemini.api_key' => 'test-key',
            'agent-kit.providers.deepseek.api_key' => 'test-key',
            'agent-kit.default' => 'openai',
            'agent-kit.error_recovery.retry.policies.NETWORK_TIMEOUT.initial_delay_ms' => 1,
            'agent-kit.error_recovery.retry.policies.NETWORK_TIMEOUT.max_delay_ms' => 2,
            'agent-kit.error_recovery.retry.policies.SERVER_ERROR.initial_delay_ms' => 1,
            'agent-kit.error_recovery.retry.policies.SERVER_ERROR.max_delay_ms' => 2,
        ]);
    }

    private function mockHandlerFor(string $provider, array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        config(["agent-kit.providers.{$provider}.handler" => $stack]);
    }

    public function test_retries_transparently_then_succeeds()
    {
        $this->mockHandlerFor('openai', [
            new Response(500, [], json_encode(['error' => 'boom'])),
            new Response(500, [], json_encode(['error' => 'boom'])),
            new Response(200, [], json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'oi'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ])),
        ]);

        $response = Agent::make()->provider('openai')->send('Olá');

        $this->assertEquals('oi', $response->text());
    }

    public function test_falls_back_to_anthropic_after_openai_exhausted()
    {
        // openai: exhaust all retry attempts (SERVER_ERROR max_attempts default = 5, plus margin)
        $openaiFailures = array_fill(0, 10, new Response(500, [], json_encode(['error' => 'boom'])));
        $this->mockHandlerFor('openai', $openaiFailures);

        $this->mockHandlerFor('anthropic', [
            new Response(200, [], json_encode([
                'content' => [['type' => 'text', 'text' => 'from anthropic']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ])),
        ]);

        $response = Agent::make()->provider('openai')->send('Olá');

        $this->assertStringContainsString('anthropic', $response->text());
    }

    public function test_all_providers_failing_throws_and_alerts()
    {
        $failures = array_fill(0, 10, new Response(500, [], json_encode(['error' => 'boom'])));
        $this->mockHandlerFor('openai', $failures);
        $this->mockHandlerFor('anthropic', $failures);
        $this->mockHandlerFor('gemini', $failures);
        $this->mockHandlerFor('deepseek', $failures);

        $notifier = \Mockery::mock(AlertNotifier::class);
        $notifier->shouldReceive('notifyFailure')->once();
        $this->app->instance(AlertNotifier::class, $notifier);

        $this->expectException(AllProvidersFailedException::class);

        Agent::make()->provider('openai')->send('Olá');
    }
}
