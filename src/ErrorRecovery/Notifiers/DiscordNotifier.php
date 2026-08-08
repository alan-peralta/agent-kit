<?php

namespace Peralta\AgentKit\ErrorRecovery\Notifiers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Peralta\AgentKit\ErrorRecovery\Contracts\AlertNotifier;

class DiscordNotifier implements AlertNotifier
{
    public function __construct(
        protected string $webhookUrl,
        protected ?string $mention = null,
    ) {}

    public function notifyFailure(string $message, array $context): void
    {
        $this->send($this->formatMessage($message, $context));
    }

    public function notifyFallback(string $provider, string $reason): void
    {
        $this->send($this->formatMessage(
            "⚠️ Fallback ativado: usando '{$provider}' — {$reason}",
            [],
        ));
    }

    private function formatMessage(string $message, array $context): string
    {
        $lines = [$message];

        if ($this->mention) {
            $lines[] = $this->mention;
        }

        foreach ($context as $key => $value) {
            $lines[] = "**{$key}**: " . (is_scalar($value) ? $value : json_encode($value));
        }

        return implode("\n", $lines);
    }

    private function send(string $content): void
    {
        if (empty($this->webhookUrl)) {
            return;
        }

        try {
            Http::timeout(5)->post($this->webhookUrl, ['content' => $content]);
        } catch (\Throwable $e) {
            Log::warning('[agent-kit] Falha ao enviar alerta pro Discord', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
