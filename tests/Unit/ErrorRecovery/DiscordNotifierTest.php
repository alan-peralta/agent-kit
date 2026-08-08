<?php

namespace Peralta\AgentKit\Tests\Unit\ErrorRecovery;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Peralta\AgentKit\ErrorRecovery\Notifiers\DiscordNotifier;
use Peralta\AgentKit\ErrorRecovery\Notifiers\NullNotifier;
use Peralta\AgentKit\Tests\TestCase;

class DiscordNotifierTest extends TestCase
{
    public function test_sends_webhook_with_message_and_mention()
    {
        Http::fake(['discord.com/*' => Http::response([], 204)]);

        $notifier = new DiscordNotifier(
            webhookUrl: 'https://discord.com/api/webhooks/123/abc',
            mention: '@oncall',
        );

        $notifier->notifyFailure('CRÍTICO: tudo falhou', ['providers' => ['openai', 'anthropic']]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://discord.com/api/webhooks/123/abc'
                && str_contains($request['content'], 'CRÍTICO: tudo falhou')
                && str_contains($request['content'], '@oncall');
        });
    }

    public function test_notify_fallback_sends_distinct_message()
    {
        Http::fake(['discord.com/*' => Http::response([], 204)]);

        $notifier = new DiscordNotifier(
            webhookUrl: 'https://discord.com/api/webhooks/123/abc',
            mention: null,
        );

        $notifier->notifyFallback('anthropic', 'openai NETWORK_TIMEOUT esgotado');

        Http::assertSent(function ($request) {
            return str_contains($request['content'], 'anthropic')
                && str_contains($request['content'], 'Fallback');
        });
    }

    public function test_does_not_throw_when_webhook_url_is_empty()
    {
        Http::fake();

        $notifier = new DiscordNotifier(webhookUrl: '', mention: null);
        $notifier->notifyFailure('should be a no-op', []);

        Http::assertNothingSent();
    }

    public function test_null_notifier_is_a_no_op()
    {
        Http::fake();

        $notifier = new NullNotifier();

        $notifier->notifyFailure('anything', []);
        $notifier->notifyFallback('anthropic', 'reason');

        Http::assertNothingSent();
    }

    public function test_does_not_throw_when_webhook_request_fails()
    {
        Http::fake(['discord.com/*' => Http::response(['error' => 'boom'], 500)]);

        $notifier = new DiscordNotifier(
            webhookUrl: 'https://discord.com/api/webhooks/123/abc',
            mention: null,
        );

        // não deve lançar exception mesmo com resposta de erro
        $notifier->notifyFailure('teste', []);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://discord.com/api/webhooks/123/abc'
                && str_contains($request['content'], 'teste');
        });
    }

    public function test_does_not_throw_when_connection_fails()
    {
        Log::spy();
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });

        $notifier = new DiscordNotifier(
            webhookUrl: 'https://discord.com/api/webhooks/123/abc',
            mention: null,
        );

        // não deve lançar exception mesmo com falha de conexão
        $notifier->notifyFailure('teste', []);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('[agent-kit] Falha ao enviar alerta pro Discord', Mockery::on(
                fn($context) => str_contains($context['error'], 'Connection refused')
            ));
    }
}
