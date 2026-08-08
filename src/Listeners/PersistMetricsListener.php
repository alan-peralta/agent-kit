<?php

namespace Peralta\AgentKit\Listeners;

use Illuminate\Support\Facades\Log;
use Peralta\AgentKit\Events\AgentKitEvent;
use Peralta\AgentKit\Events\AgentRequestCompleted;
use Peralta\AgentKit\Events\AgentRequestStarted;
use Peralta\AgentKit\Events\ProviderCallCompleted;
use Peralta\AgentKit\Events\RecoveryAttempted;
use Peralta\AgentKit\Events\RecoveryExhausted;
use Peralta\AgentKit\Events\TokenUsageRecorded;
use Peralta\AgentKit\Events\ToolCallExecuted;
use Peralta\AgentKit\Models\AgentMetric;

class PersistMetricsListener
{
    public function handle(AgentKitEvent $event): void
    {
        try {
            AgentMetric::create($this->mapEvent($event));
        } catch (\Throwable $e) {
            Log::warning('[agent-kit] Failed to persist metric', [
                'event' => get_class($event),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function mapEvent(AgentKitEvent $event): array
    {
        $props = get_object_vars($event);

        return match (true) {
            $event instanceof AgentRequestStarted => [
                'type' => 'request_started',
                'conversation_id' => $event->conversationId,
                'provider' => $event->provider,
                'model' => $event->model,
                'duration_ms' => null,
                'payload' => [],
            ],
            $event instanceof AgentRequestCompleted => [
                'type' => 'request_completed',
                'conversation_id' => $event->conversationId,
                'provider' => $event->provider,
                'model' => $event->model,
                'duration_ms' => $event->durationMs,
                'payload' => ['iterations' => $event->iterations],
            ],
            $event instanceof ProviderCallCompleted => [
                'type' => 'provider_call',
                'conversation_id' => $event->conversationId,
                'provider' => $event->provider,
                'model' => $event->model,
                'duration_ms' => $event->durationMs,
                'payload' => ['iterationNumber' => $event->iterationNumber],
            ],
            $event instanceof TokenUsageRecorded => [
                'type' => 'token_usage',
                'conversation_id' => $event->conversationId,
                'provider' => $event->provider,
                'model' => $event->model,
                'duration_ms' => null,
                'payload' => [
                    'inputTokens' => $event->inputTokens,
                    'outputTokens' => $event->outputTokens,
                ],
            ],
            $event instanceof ToolCallExecuted => [
                'type' => 'tool_call',
                'conversation_id' => $event->conversationId,
                'provider' => null,
                'model' => null,
                'duration_ms' => $event->durationMs,
                'payload' => [
                    'toolName' => $event->toolName,
                    'success' => $event->success,
                ],
            ],
            $event instanceof RecoveryAttempted => [
                'type' => 'recovery_attempted',
                'conversation_id' => $event->conversationId,
                'provider' => $event->provider,
                'model' => null,
                'duration_ms' => null,
                'payload' => [
                    'attemptNumber' => $event->attemptNumber,
                    'strategy' => $event->strategy,
                    'errorClass' => $event->errorClass,
                ],
            ],
            $event instanceof RecoveryExhausted => [
                'type' => 'recovery_exhausted',
                'conversation_id' => $event->conversationId,
                'provider' => null,
                'model' => null,
                'duration_ms' => null,
                'payload' => [
                    'attempts' => $event->attempts,
                    'finalErrorClass' => $event->finalErrorClass,
                ],
            ],
            default => [
                'type' => 'unknown',
                'conversation_id' => $props['conversationId'] ?? null,
                'provider' => null,
                'model' => null,
                'duration_ms' => null,
                'payload' => $props,
            ],
        };
    }
}
