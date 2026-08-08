# Analytics & Monitoring System - Design Spec

**Date**: 2026-08-08
**Status**: Approved
**Author**: Alan Peralta

---

## 1. Overview

Add observability to Agent Kit: operational metrics (errors, retries, fallbacks, latency), usage analytics (token counts), and an extensible event system so host applications can plug in their own listeners (Prometheus, Datadog, OpenTelemetry, etc. via their own code).

**Goals:**
- Emit typed Laravel events at key points of the agent lifecycle (request start/end, provider calls, tool calls, token usage, error recovery attempts).
- Let host applications listen to these events without any coupling to a specific export target.
- Optionally persist metrics to a database table for host apps that don't want to wire their own listener immediately.
- Replace the two existing ad-hoc `Log::info()` calls in `Agent.php` with event-driven logging, removing duplication.

**Non-goals (out of scope for this iteration):**
- Cost calculation in $ (pricing tables per model) — only raw token counts are tracked.
- Native OpenTelemetry / Prometheus / Datadog integration — host apps build this themselves on top of the emitted events.
- Dashboards or UI for viewing metrics.
- High-volume optimization (composite indexes, partitioning) — the single-table design can be revisited if volume becomes a problem.

---

## 2. Architecture: Events + Optional Listeners

```
Agent::send() / ErrorRecovery\Pipeline
    ↓ dispatch
[AgentKitEvent] (typed Laravel event)
    ↓
    ├── LogUsageListener        (active if logging.enabled)
    ├── LogToolCallListener     (active if logging.enabled && logging.log_tool_calls)
    └── PersistMetricsListener  (active if analytics.persist)
```

Events are dispatched unconditionally from `Agent::send()` and `ErrorRecovery\Pipeline` whenever `analytics.enabled` is true. Listeners are registered conditionally in the package's `ServiceProvider` based on config — this keeps event emission and consumption fully decoupled. A host app can ignore the built-in listeners entirely and register its own (e.g., forwarding to Prometheus) without touching package internals.

`analytics.enabled = false` skips dispatch entirely (zero overhead, e.g. for performance-sensitive environments).

---

## 3. Configuration

New `analytics` section in `config/agent-kit.php`, alongside `logging` and `error_recovery`:

```php
'analytics' => [
    'enabled' => env('AGENT_KIT_ANALYTICS_ENABLED', true), // dispatch events at all?
    'persist' => env('AGENT_KIT_ANALYTICS_PERSIST', false), // write to agent_kit_metrics table?
    'table' => 'agent_kit_metrics',
],
```

The existing `logging` section (`enabled`, `channel`, `log_tool_calls`, `log_messages`) is unchanged in shape and continues to control the two logging listeners described below — it now controls listeners instead of direct `Log::info()` calls.

---

## 4. Events

All events live in `src/Events/`, implement a marker interface `AgentKitEvent` (enables a generic catch-all listener like `PersistMetricsListener`), and carry data via `readonly` properties.

| Event | Dispatched from | Key data |
|---|---|---|
| `AgentRequestStarted` | Start of `Agent::send()` | conversation_id, provider, model |
| `AgentRequestCompleted` | End of `Agent::send()` | conversation_id, provider, model, duration_ms, iterations |
| `ProviderCallCompleted` | After each provider call inside the send() loop | conversation_id, provider, model, duration_ms, iteration_number |
| `TokenUsageRecorded` | Replaces current `logUsage()` call site | conversation_id, provider, model, input_tokens, output_tokens |
| `ToolCallExecuted` | Replaces current `logToolCall()` call site | conversation_id, tool_name, success, duration_ms |
| `RecoveryAttempted` | Inside `ErrorRecovery\Pipeline`, on each retry/fallback attempt | conversation_id, attempt_number, strategy (retry/fallback), error_class, provider |
| `RecoveryExhausted` | `ErrorRecovery\Pipeline`, when all attempts are exhausted | conversation_id, attempts, final_error_class |

---

## 5. Listeners

Registered conditionally in the package `ServiceProvider`, based on config:

- **`LogUsageListener`** — listens to `TokenUsageRecorded`, calls `Log::channel(...)->info(...)` with the same shape `logUsage()` produces today. Active when `logging.enabled`.
- **`LogToolCallListener`** — listens to `ToolCallExecuted`, same log shape as current `logToolCall()`. Active when `logging.enabled && logging.log_tool_calls`.
- **`PersistMetricsListener`** — listens to all `AgentKitEvent` instances (registered against the interface), writes one row per event to the `agent_kit_metrics` table. Active when `analytics.persist`.

This removes the direct `Log::info()` calls currently in `Agent.php` (`logUsage()`, `logToolCall()`) — those methods now just dispatch the corresponding event.

---

## 6. Persistence

Single polymorphic table, migration `create_agent_kit_metrics_table`:

```php
Schema::create('agent_kit_metrics', function (Blueprint $table) {
    $table->id();
    $table->string('type');              // e.g. 'token_usage', 'tool_call', 'request_completed', 'recovery_attempted'
    $table->string('conversation_id')->nullable()->index();
    $table->string('provider')->nullable();
    $table->string('model')->nullable();
    $table->unsignedInteger('duration_ms')->nullable();
    $table->json('payload')->nullable(); // event-specific remainder
    $table->timestamp('created_at');
});
```

Model `AgentMetric` (Eloquent) in `src/Models/`, no required relations. `PersistMetricsListener` maps each event to a row: common fields (`type`, `conversation_id`, `provider`, `model`, `duration_ms`) extracted directly, everything else goes into `payload`.

No composite index beyond `conversation_id` for this iteration — can be added later if production volume demands it.

---

## 7. Error Handling

- Listener failures (e.g., `PersistMetricsListener` hitting a DB error) must never break the agent request. Wrap listener bodies in try/catch and log failures via the standard Laravel log, swallowing the exception — analytics must be best-effort, not load-bearing.
- Event dispatch itself uses Laravel's synchronous `Event::dispatch()` (no queue) for this iteration, consistent with how the rest of the package operates. Host apps that need async dispatch can implement `ShouldQueue` on their own listeners.

---

## 8. Testing

- Unit tests per event: `Agent::send()` and `ErrorRecovery\Pipeline` dispatch the right events with the right data, using `Event::fake()`.
- Listener tests: `PersistMetricsListener` writes the correct row for each event type; `LogUsageListener` / `LogToolCallListener` produce the same log output as the current direct calls.
- Config tests: `analytics.enabled = false` dispatches nothing; `analytics.persist = false` persists nothing even when events fire.
- Migration/model tests via the package's existing SQLite in-memory test setup.

---

## 9. Future Enhancements (Out of Scope)

- Cost calculation from token counts (pricing table per model).
- Native OpenTelemetry exporter shipped with the package.
- Aggregation helpers / query builder for the `agent_kit_metrics` table (e.g., "average latency per provider last 24h").
- Async event dispatch (queued listeners) as a package default.
