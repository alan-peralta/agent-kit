<?php

namespace Peralta\AgentKit\Tests\Unit\ErrorRecovery;

use Illuminate\Support\Facades\Http;
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
        $notifier = new NullNotifier();

        $notifier->notifyFailure('anything', []);
        $notifier->notifyFallback('anthropic', 'reason');

        $this->assertTrue(true); // não deve lançar exception
    }
}
