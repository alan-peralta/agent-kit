<?php

namespace Peralta\AgentKit\Tests\Feature\ErrorRecovery;

use Mockery;
use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\ErrorRecovery\Contracts\AlertNotifier;
use Peralta\AgentKit\ErrorRecovery\Middleware\DiscordAlertMiddleware;
use Peralta\AgentKit\Exceptions\AllProvidersFailedException;
use Peralta\AgentKit\Tests\TestCase;

class DiscordAlertMiddlewareTest extends TestCase
{
    public function test_sends_fallback_alert_when_context_used_fallback()
    {
        $notifier = Mockery::mock(AlertNotifier::class);
        $notifier->shouldReceive('notifyFallback')
            ->once()
            ->with('anthropic', Mockery::type('string'));
        $notifier->shouldNotReceive('notifyFailure');

        $handler = function (RecoveryContext $ctx) {
            $ctx->switchProvider('anthropic');
            return 'ok';
        };

        $middleware = new DiscordAlertMiddleware($notifier);
        $result = $middleware->handle($handler, new RecoveryContext(provider: 'openai'));

        $this->assertEquals('ok', $result);
    }

    public function test_does_not_alert_when_no_fallback_used()
    {
        $notifier = Mockery::mock(AlertNotifier::class);
        $notifier->shouldNotReceive('notifyFallback');
        $notifier->shouldNotReceive('notifyFailure');

        $handler = fn(RecoveryContext $ctx) => 'ok';

        $middleware = new DiscordAlertMiddleware($notifier);
        $middleware->handle($handler, new RecoveryContext(provider: 'openai'));
    }

    public function test_sends_critical_alert_when_all_providers_failed()
    {
        $notifier = Mockery::mock(AlertNotifier::class);
        $notifier->shouldReceive('notifyFailure')
            ->once()
            ->with(Mockery::pattern('/CRÍTICO/'), Mockery::type('array'));

        $handler = function (RecoveryContext $ctx) {
            throw new AllProvidersFailedException('Todos os providers falharam: openai, anthropic', [
                'openai' => ['attempts' => 7, 'last_error' => 'NETWORK_TIMEOUT'],
            ]);
        };

        $middleware = new DiscordAlertMiddleware($notifier);

        $this->expectException(AllProvidersFailedException::class);
        $middleware->handle($handler, new RecoveryContext(provider: 'openai'));
    }
}
