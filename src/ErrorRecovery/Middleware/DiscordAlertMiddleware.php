<?php

namespace Peralta\AgentKit\ErrorRecovery\Middleware;

use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\ErrorRecovery\Contracts\AlertNotifier;
use Peralta\AgentKit\ErrorRecovery\Contracts\Middleware;
use Peralta\AgentKit\Exceptions\AllProvidersFailedException;

class DiscordAlertMiddleware implements Middleware
{
    public function __construct(
        protected AlertNotifier $notifier,
    ) {}

    public function handle(callable $handler, RecoveryContext $context): mixed
    {
        try {
            $result = $handler($context);

            if ($context->fallbackUsed) {
                $this->notifier->notifyFallback(
                    $context->provider,
                    'provider anterior esgotou tentativas',
                );
            }

            return $result;
        } catch (AllProvidersFailedException $e) {
            $this->notifier->notifyFailure(
                "🚨 CRÍTICO: {$e->getMessage()}",
                $e->providerSummary,
            );

            throw $e;
        }
    }
}
