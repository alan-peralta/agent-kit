<?php

namespace Peralta\AgentKit\Support;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Peralta\AgentKit\Events\AgentKitEvent;

class SafeEventDispatcher
{
    public static function dispatch(AgentKitEvent $event): void
    {
        if (!config('agent-kit.analytics.enabled', true)) {
            return;
        }

        try {
            Event::dispatch($event);
        } catch (\Throwable $e) {
            Log::warning('[agent-kit] Falha ao despachar evento de analytics', [
                'event' => get_class($event),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
