# Analytics & Monitoring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an event-driven observability layer to Agent Kit — typed Laravel events for the agent lifecycle, optional built-in listeners for logging and DB persistence — replacing the current ad-hoc `Log::info()` calls in `Agent.php`.

**Architecture:** `Agent::send()` and the `ErrorRecovery` middleware dispatch typed events (`AgentKitEvent` implementations) at key points. Three listeners — `LogUsageListener`, `LogToolCallListener`, `PersistMetricsListener` — are registered conditionally in `AgentKitServiceProvider` based on a new `analytics` config section. Persistence uses a single polymorphic `agent_kit_metrics` table via an `AgentMetric` Eloquent model.

**Tech Stack:** Laravel Events (`Illuminate\Support\Facades\Event`), Eloquent, PHPUnit (Orchestra Testbench), Mockery.

**Spec:** `docs/superpowers/specs/2026-08-08-analytics-monitoring-design.md`

---

## Task 1: Add `analytics` config section

**Files:**
- Modify: `config/agent-kit.php`

- [ ] **Step 1: Add the `analytics` section**

Add this block right after the `'logging'` section (end of file, before the closing `];`):

```php
    /*
    |--------------------------------------------------------------------------
    | Analytics & Monitoring
    |--------------------------------------------------------------------------
    | Eventos de observabilidade (uso de tokens, tool calls, latência, retries).
    | 'enabled' controla o dispatch dos eventos. 'persist' controla se o listener
    | interno grava cada evento na tabela 'agent_kit_metrics'.
    */
    'analytics' => [
        'enabled' => env('AGENT_KIT_ANALYTICS_ENABLED', true),
        'persist' => env('AGENT_KIT_ANALYTICS_PERSIST', false),
        'table' => 'agent_kit_metrics',
    ],
```

- [ ] **Step 2: Commit**

```bash
git add config/agent-kit.php
git commit -m "feat: add analytics config section"
```

---

## Task 2: Create the `AgentKitEvent` marker interface and lifecycle events

**Files:**
- Create: `src/Events/AgentKitEvent.php`
- Create: `src/Events/AgentRequestStarted.php`
- Create: `src/Events/AgentRequestCompleted.php`
- Create: `src/Events/ProviderCallCompleted.php`
- Test: `tests/Unit/Events/AgentKitEventTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Events;

use Peralta\AgentKit\Events\AgentKitEvent;
use Peralta\AgentKit\Events\AgentRequestCompleted;
use Peralta\AgentKit\Events\AgentRequestStarted;
use Peralta\AgentKit\Events\ProviderCallCompleted;
use Peralta\AgentKit\Tests\TestCase;

class AgentKitEventTest extends TestCase
{
    public function test_agent_request_started_implements_marker_interface_and_holds_data()
    {
        $event = new AgentRequestStarted(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4o',
        );

        $this->assertInstanceOf(AgentKitEvent::class, $event);
        $this->assertEquals('conv-1', $event->conversationId);
        $this->assertEquals('openai', $event->provider);
        $this->assertEquals('gpt-4o', $event->model);
    }

    public function test_agent_request_completed_holds_duration_and_iterations()
    {
        $event = new AgentRequestCompleted(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4o',
            durationMs: 1234,
            iterations: 3,
        );

        $this->assertInstanceOf(AgentKitEvent::class, $event);
        $this->assertEquals(1234, $event->durationMs);
        $this->assertEquals(3, $event->iterations);
    }

    public function test_provider_call_completed_holds_iteration_number()
    {
        $event = new ProviderCallCompleted(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4o',
            durationMs: 500,
            iterationNumber: 2,
        );

        $this->assertInstanceOf(AgentKitEvent::class, $event);
        $this->assertEquals(2, $event->iterationNumber);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Events/AgentKitEventTest.php`
Expected: FAIL — `Class "Peralta\AgentKit\Events\AgentKitEvent" not found`

- [ ] **Step 3: Create the marker interface**

```php
<?php

namespace Peralta\AgentKit\Events;

interface AgentKitEvent
{
}
```

- [ ] **Step 4: Create `AgentRequestStarted`**

```php
<?php

namespace Peralta\AgentKit\Events;

class AgentRequestStarted implements AgentKitEvent
{
    public function __construct(
        public readonly ?string $conversationId,
        public readonly string $provider,
        public readonly ?string $model,
    ) {}
}
```

- [ ] **Step 5: Create `AgentRequestCompleted`**

```php
<?php

namespace Peralta\AgentKit\Events;

class AgentRequestCompleted implements AgentKitEvent
{
    public function __construct(
        public readonly ?string $conversationId,
        public readonly string $provider,
        public readonly ?string $model,
        public readonly int $durationMs,
        public readonly int $iterations,
    ) {}
}
```

- [ ] **Step 6: Create `ProviderCallCompleted`**

```php
<?php

namespace Peralta\AgentKit\Events;

class ProviderCallCompleted implements AgentKitEvent
{
    public function __construct(
        public readonly ?string $conversationId,
        public readonly string $provider,
        public readonly ?string $model,
        public readonly int $durationMs,
        public readonly int $iterationNumber,
    ) {}
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Events/AgentKitEventTest.php`
Expected: PASS (3 tests)

- [ ] **Step 8: Commit**

```bash
git add src/Events/AgentKitEvent.php src/Events/AgentRequestStarted.php src/Events/AgentRequestCompleted.php src/Events/ProviderCallCompleted.php tests/Unit/Events/AgentKitEventTest.php
git commit -m "feat: add agent request lifecycle events"
```

---

## Task 3: Create `TokenUsageRecorded` and `ToolCallExecuted` events

**Files:**
- Create: `src/Events/TokenUsageRecorded.php`
- Create: `src/Events/ToolCallExecuted.php`
- Test: `tests/Unit/Events/UsageAndToolEventsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Events;

use Peralta\AgentKit\Events\AgentKitEvent;
use Peralta\AgentKit\Events\ToolCallExecuted;
use Peralta\AgentKit\Events\TokenUsageRecorded;
use Peralta\AgentKit\Tests\TestCase;

class UsageAndToolEventsTest extends TestCase
{
    public function test_token_usage_recorded_holds_token_counts()
    {
        $event = new TokenUsageRecorded(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4o',
            inputTokens: 100,
            outputTokens: 50,
        );

        $this->assertInstanceOf(AgentKitEvent::class, $event);
        $this->assertEquals(100, $event->inputTokens);
        $this->assertEquals(50, $event->outputTokens);
    }

    public function test_tool_call_executed_holds_success_and_duration()
    {
        $event = new ToolCallExecuted(
            conversationId: 'conv-1',
            toolName: 'search',
            success: true,
            durationMs: 42,
        );

        $this->assertInstanceOf(AgentKitEvent::class, $event);
        $this->assertEquals('search', $event->toolName);
        $this->assertTrue($event->success);
        $this->assertEquals(42, $event->durationMs);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Events/UsageAndToolEventsTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create `TokenUsageRecorded`**

```php
<?php

namespace Peralta\AgentKit\Events;

class TokenUsageRecorded implements AgentKitEvent
{
    public function __construct(
        public readonly ?string $conversationId,
        public readonly string $provider,
        public readonly ?string $model,
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
    ) {}
}
```

- [ ] **Step 4: Create `ToolCallExecuted`**

```php
<?php

namespace Peralta\AgentKit\Events;

class ToolCallExecuted implements AgentKitEvent
{
    public function __construct(
        public readonly ?string $conversationId,
        public readonly string $toolName,
        public readonly bool $success,
        public readonly ?int $durationMs = null,
    ) {}
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Events/UsageAndToolEventsTest.php`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add src/Events/TokenUsageRecorded.php src/Events/ToolCallExecuted.php tests/Unit/Events/UsageAndToolEventsTest.php
git commit -m "feat: add token usage and tool call events"
```

---

## Task 4: Create `RecoveryAttempted` and `RecoveryExhausted` events

**Files:**
- Create: `src/Events/RecoveryAttempted.php`
- Create: `src/Events/RecoveryExhausted.php`
- Test: `tests/Unit/Events/RecoveryEventsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Events;

use Peralta\AgentKit\Events\AgentKitEvent;
use Peralta\AgentKit\Events\RecoveryAttempted;
use Peralta\AgentKit\Events\RecoveryExhausted;
use Peralta\AgentKit\Tests\TestCase;

class RecoveryEventsTest extends TestCase
{
    public function test_recovery_attempted_holds_strategy_and_error_class()
    {
        $event = new RecoveryAttempted(
            conversationId: 'conv-1',
            provider: 'openai',
            attemptNumber: 2,
            strategy: 'retry',
            errorClass: 'RATE_LIMIT',
        );

        $this->assertInstanceOf(AgentKitEvent::class, $event);
        $this->assertEquals('retry', $event->strategy);
        $this->assertEquals('RATE_LIMIT', $event->errorClass);
    }

    public function test_recovery_exhausted_holds_attempts_and_final_error()
    {
        $event = new RecoveryExhausted(
            conversationId: 'conv-1',
            attempts: 5,
            finalErrorClass: 'SERVER_ERROR',
        );

        $this->assertInstanceOf(AgentKitEvent::class, $event);
        $this->assertEquals(5, $event->attempts);
        $this->assertEquals('SERVER_ERROR', $event->finalErrorClass);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Events/RecoveryEventsTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create `RecoveryAttempted`**

```php
<?php

namespace Peralta\AgentKit\Events;

class RecoveryAttempted implements AgentKitEvent
{
    public function __construct(
        public readonly ?string $conversationId,
        public readonly string $provider,
        public readonly int $attemptNumber,
        public readonly string $strategy, // 'retry' | 'fallback'
        public readonly string $errorClass,
    ) {}
}
```

- [ ] **Step 4: Create `RecoveryExhausted`**

```php
<?php

namespace Peralta\AgentKit\Events;

class RecoveryExhausted implements AgentKitEvent
{
    public function __construct(
        public readonly ?string $conversationId,
        public readonly int $attempts,
        public readonly string $finalErrorClass,
    ) {}
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Events/RecoveryEventsTest.php`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add src/Events/RecoveryAttempted.php src/Events/RecoveryExhausted.php tests/Unit/Events/RecoveryEventsTest.php
git commit -m "feat: add error recovery events"
```

---

## Task 5: Add `conversationId` to `RecoveryContext`

**Files:**
- Modify: `src/DTOs/RecoveryContext.php`
- Modify: `src/Agent.php:137` (pass conversationId when constructing `RecoveryContext`)
- Test: `tests/Unit/ErrorRecovery/RecoveryContextTest.php`

`RecoveryContext` currently only carries `provider`. The recovery events need `conversationId` to correlate with the rest of a request's events, so it needs to travel through the pipeline.

- [ ] **Step 1: Read the existing test file to preserve its assertions**

Run: `cat tests/Unit/ErrorRecovery/RecoveryContextTest.php`

- [ ] **Step 2: Add a failing test for the new constructor param**

Append this test method to `tests/Unit/ErrorRecovery/RecoveryContextTest.php` (inside the existing test class):

```php
    public function test_holds_optional_conversation_id()
    {
        $ctx = new RecoveryContext(provider: 'openai', conversationId: 'conv-42');

        $this->assertEquals('conv-42', $ctx->conversationId);
    }

    public function test_conversation_id_defaults_to_null()
    {
        $ctx = new RecoveryContext(provider: 'openai');

        $this->assertNull($ctx->conversationId);
    }
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ErrorRecovery/RecoveryContextTest.php`
Expected: FAIL — unknown named parameter `conversationId`

- [ ] **Step 4: Add the property to `RecoveryContext`**

In `src/DTOs/RecoveryContext.php`, change the constructor:

```php
    public function __construct(
        public string $provider,
        public readonly ?string $conversationId = null,
    ) {}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ErrorRecovery/RecoveryContextTest.php`
Expected: PASS (all tests, including the 2 new ones)

- [ ] **Step 6: Pass `conversationId` from `Agent::send()`**

In `src/Agent.php`, line 137, change:

```php
            $recoveryContext = new RecoveryContext(provider: $providerName);
```

to:

```php
            $recoveryContext = new RecoveryContext(
                provider: $providerName,
                conversationId: $this->conversationId,
            );
```

- [ ] **Step 7: Run the full Agent-related test suite to confirm nothing broke**

Run: `vendor/bin/phpunit tests/Unit/ErrorRecovery tests/Feature/ErrorRecovery`
Expected: PASS (all tests)

- [ ] **Step 8: Commit**

```bash
git add src/DTOs/RecoveryContext.php src/Agent.php tests/Unit/ErrorRecovery/RecoveryContextTest.php
git commit -m "feat: carry conversationId through RecoveryContext"
```

---

## Task 6: Dispatch `AgentRequestStarted`, `AgentRequestCompleted`, `ProviderCallCompleted` from `Agent::send()`

**Files:**
- Modify: `src/Agent.php`
- Test: `tests/Feature/AgentAnalyticsEventsTest.php`

This task wires the three request-lifecycle events into the real `send()` loop. Duration is measured with `microtime(true)`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AgentAnalyticsEventsTest.php`. This test builds an `Agent` directly (mirroring the pattern used in `tests/Feature/ErrorRecovery/ErrorRecoveryIntegrationTest.php` — read that file first for the exact provider/tool mocking pattern used in this codebase) and asserts events fire. Use a fake provider bound in the container:

```php
<?php

namespace Peralta\AgentKit\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Peralta\AgentKit\Agent;
use Peralta\AgentKit\Contracts\Provider;
use Peralta\AgentKit\DTOs\AgentResponse;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\ErrorRecovery\Pipeline;
use Peralta\AgentKit\Events\AgentRequestCompleted;
use Peralta\AgentKit\Events\AgentRequestStarted;
use Peralta\AgentKit\Events\ProviderCallCompleted;
use Peralta\AgentKit\Tests\TestCase;

class AgentAnalyticsEventsTest extends TestCase
{
    public function test_send_dispatches_request_started_completed_and_provider_call_events()
    {
        Event::fake();

        $provider = \Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')->once()->andReturn(new AgentResponse(
            message: Message::assistant('oi'),
            stopReason: 'stop',
            inputTokens: 10,
            outputTokens: 5,
        ));

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $agent = $this->app->make(Agent::class);
        $agent->provider('openai')->send('olá');

        Event::assertDispatched(AgentRequestStarted::class, fn($e) => $e->provider === 'openai');
        Event::assertDispatched(ProviderCallCompleted::class, fn($e) => $e->iterationNumber === 1);
        Event::assertDispatched(AgentRequestCompleted::class, fn($e) => $e->iterations === 1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/AgentAnalyticsEventsTest.php`
Expected: FAIL — events never dispatched

- [ ] **Step 3: Instrument `Agent::send()`**

In `src/Agent.php`, add the `Event` facade and event class imports at the top:

```php
use Illuminate\Support\Facades\Event;
use Peralta\AgentKit\Events\AgentRequestCompleted;
use Peralta\AgentKit\Events\AgentRequestStarted;
use Peralta\AgentKit\Events\ProviderCallCompleted;
```

Then rewrite the `send()` method body to measure duration and dispatch events. Replace the full method (lines 109-178) with:

```php
    public function send(string $userMessage): AgentResponse
    {
        // Fail-fast: valida o provider inicial antes de carregar histórico e persistir
        // a mensagem do usuário. O retorno não é usado — o provider efetivo é resolvido
        // novamente dentro do pipeline (que pode trocar de provider em caso de fallback).
        $this->resolveProvider();
        $tools = $this->resolveTools();
        $context = $this->context ?? new Context();
        $convo = $this->resolveConversation();

        $startedAt = microtime(true);
        $initialProviderName = $this->providerName ?? $this->config['default'];

        $this->dispatchEvent(new AgentRequestStarted(
            conversationId: $this->conversationId,
            provider: $initialProviderName,
            model: $this->model,
        ));

        // Carrega histórico (se houver) e adiciona nova mensagem do usuário
        $messages = $convo && $this->conversationId ? $convo->load($this->conversationId) : [];

        $userMsg = Message::user($userMessage);
        $messages[] = $userMsg;
        if ($convo && $this->conversationId) {
            $convo->append($this->conversationId, $userMsg);
        }

        $iteration = 0;
        while (true) {
            if (++$iteration > $this->maxIterations) {
                throw new MaxIterationsExceededException(
                    "Limite de {$this->maxIterations} iterações atingido."
                );
            }

            $providerName = $this->providerName ?? $this->config['default'];
            $recoveryContext = new RecoveryContext(
                provider: $providerName,
                conversationId: $this->conversationId,
            );

            $callStartedAt = microtime(true);

            $response = $this->pipeline->execute(
                function (RecoveryContext $context) use ($messages, $tools) {
                    $provider = $this->container->make("agent-kit.provider.{$context->provider}");

                    return $provider->chat(
                        messages: $messages,
                        tools: $tools,
                        system: $this->systemPrompt,
                        options: $this->resolveOptions(),
                    );
                },
                $recoveryContext,
            );

            $provider = $this->container->make("agent-kit.provider.{$recoveryContext->provider}");

            $this->dispatchEvent(new ProviderCallCompleted(
                conversationId: $this->conversationId,
                provider: $recoveryContext->provider,
                model: $this->model,
                durationMs: (int) round((microtime(true) - $callStartedAt) * 1000),
                iterationNumber: $iteration,
            ));

            $messages[] = $response->message;
            if ($convo && $this->conversationId) {
                $convo->append($this->conversationId, $response->message);
            }

            if (!$response->hasToolCalls()) {
                $this->logUsage($provider, $response);

                $this->dispatchEvent(new AgentRequestCompleted(
                    conversationId: $this->conversationId,
                    provider: $recoveryContext->provider,
                    model: $this->model,
                    durationMs: (int) round((microtime(true) - $startedAt) * 1000),
                    iterations: $iteration,
                ));

                return $response;
            }

            // Executa cada tool pedida
            foreach ($response->toolCalls() as $call) {
                $tool = $this->findTool($tools, $call->name);

                $result = $this->executeTool($tool, $call, $context);

                $toolResultMsg = Message::tool($call->id, $result);
                $messages[] = $toolResultMsg;
                if ($convo && $this->conversationId) {
                    $convo->append($this->conversationId, $toolResultMsg);
                }
            }
        }
    }

    protected function dispatchEvent(\Peralta\AgentKit\Events\AgentKitEvent $event): void
    {
        if (!($this->config['analytics']['enabled'] ?? true)) return;

        Event::dispatch($event);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/AgentAnalyticsEventsTest.php`
Expected: PASS

- [ ] **Step 5: Run the full test suite to confirm no regressions**

Run: `vendor/bin/phpunit`
Expected: PASS (all existing tests still green)

- [ ] **Step 6: Commit**

```bash
git add src/Agent.php tests/Feature/AgentAnalyticsEventsTest.php
git commit -m "feat: dispatch request lifecycle events from Agent::send()"
```

---

## Task 7: Replace `logUsage()`/`logToolCall()` with `TokenUsageRecorded`/`ToolCallExecuted` events + built-in log listeners

**Files:**
- Modify: `src/Agent.php`
- Create: `src/Listeners/LogUsageListener.php`
- Create: `src/Listeners/LogToolCallListener.php`
- Test: `tests/Unit/Listeners/LogUsageListenerTest.php`
- Test: `tests/Unit/Listeners/LogToolCallListenerTest.php`
- Test: `tests/Feature/AgentAnalyticsEventsTest.php` (extend)

- [ ] **Step 1: Write failing tests for the listeners**

Create `tests/Unit/Listeners/LogUsageListenerTest.php`:

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Listeners;

use Illuminate\Support\Facades\Log;
use Peralta\AgentKit\Events\TokenUsageRecorded;
use Peralta\AgentKit\Listeners\LogUsageListener;
use Peralta\AgentKit\Tests\TestCase;

class LogUsageListenerTest extends TestCase
{
    public function test_logs_when_logging_enabled()
    {
        config(['agent-kit.logging.enabled' => true, 'agent-kit.logging.channel' => 'stack']);
        Log::shouldReceive('channel')->with('stack')->andReturnSelf();
        Log::shouldReceive('info')->once()->with('[agent-kit] Resposta finalizada', [
            'provider' => 'openai',
            'input_tokens' => 10,
            'output_tokens' => 5,
            'conversation_id' => 'conv-1',
        ]);

        (new LogUsageListener())->handle(new TokenUsageRecorded(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4o',
            inputTokens: 10,
            outputTokens: 5,
        ));
    }

    public function test_does_nothing_when_logging_disabled()
    {
        config(['agent-kit.logging.enabled' => false]);
        Log::shouldReceive('channel')->never();

        (new LogUsageListener())->handle(new TokenUsageRecorded(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4o',
            inputTokens: 10,
            outputTokens: 5,
        ));
    }
}
```

Create `tests/Unit/Listeners/LogToolCallListenerTest.php`:

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Listeners;

use Illuminate\Support\Facades\Log;
use Peralta\AgentKit\Events\ToolCallExecuted;
use Peralta\AgentKit\Listeners\LogToolCallListener;
use Peralta\AgentKit\Tests\TestCase;

class LogToolCallListenerTest extends TestCase
{
    public function test_logs_when_enabled_and_log_tool_calls_true()
    {
        config([
            'agent-kit.logging.enabled' => true,
            'agent-kit.logging.log_tool_calls' => true,
            'agent-kit.logging.channel' => 'stack',
        ]);
        Log::shouldReceive('channel')->with('stack')->andReturnSelf();
        Log::shouldReceive('info')->once()->with('[agent-kit] Tool executada', [
            'tool' => 'search',
            'success' => true,
            'conversation_id' => 'conv-1',
        ]);

        (new LogToolCallListener())->handle(new ToolCallExecuted(
            conversationId: 'conv-1',
            toolName: 'search',
            success: true,
        ));
    }

    public function test_does_nothing_when_log_tool_calls_disabled()
    {
        config(['agent-kit.logging.enabled' => true, 'agent-kit.logging.log_tool_calls' => false]);
        Log::shouldReceive('channel')->never();

        (new LogToolCallListener())->handle(new ToolCallExecuted(
            conversationId: 'conv-1',
            toolName: 'search',
            success: true,
        ));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Listeners`
Expected: FAIL — listener classes not found

- [ ] **Step 3: Create `LogUsageListener`**

Note: the listener no longer receives the `$input` arguments that `logToolCall()` used to log — the original code logged `'input' => $input`, but `ToolCallExecuted` (Task 3) intentionally does not carry raw tool input to avoid encouraging PII leakage through events (host apps that want that can capture it via `Agent::executeTool()` themselves later). The log line drops the `input` key.

```php
<?php

namespace Peralta\AgentKit\Listeners;

use Illuminate\Support\Facades\Log;
use Peralta\AgentKit\Events\TokenUsageRecorded;

class LogUsageListener
{
    public function handle(TokenUsageRecorded $event): void
    {
        if (!config('agent-kit.logging.enabled', false)) return;

        Log::channel(config('agent-kit.logging.channel', 'stack'))->info(
            '[agent-kit] Resposta finalizada',
            [
                'provider' => $event->provider,
                'input_tokens' => $event->inputTokens,
                'output_tokens' => $event->outputTokens,
                'conversation_id' => $event->conversationId,
            ],
        );
    }
}
```

- [ ] **Step 4: Create `LogToolCallListener`**

```php
<?php

namespace Peralta\AgentKit\Listeners;

use Illuminate\Support\Facades\Log;
use Peralta\AgentKit\Events\ToolCallExecuted;

class LogToolCallListener
{
    public function handle(ToolCallExecuted $event): void
    {
        if (!config('agent-kit.logging.enabled', false)) return;
        if (!config('agent-kit.logging.log_tool_calls', false)) return;

        Log::channel(config('agent-kit.logging.channel', 'stack'))->info(
            '[agent-kit] Tool executada',
            [
                'tool' => $event->toolName,
                'success' => $event->success,
                'conversation_id' => $event->conversationId,
            ],
        );
    }
}
```

- [ ] **Step 5: Run listener tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Listeners`
Expected: PASS (4 tests)

- [ ] **Step 6: Replace `logUsage()`/`logToolCall()` bodies in `Agent.php` with event dispatch**

In `src/Agent.php`, add imports:

```php
use Peralta\AgentKit\Events\ToolCallExecuted;
use Peralta\AgentKit\Events\TokenUsageRecorded;
```

Replace the two methods (previously lines 263-292) with:

```php
    protected function logUsage(Provider $provider, AgentResponse $response): void
    {
        $this->dispatchEvent(new TokenUsageRecorded(
            conversationId: $this->conversationId,
            provider: $provider->name(),
            model: $this->model,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
        ));
    }

    protected function logToolCall(string $name, array $input, mixed $result, bool $success): void
    {
        $this->dispatchEvent(new ToolCallExecuted(
            conversationId: $this->conversationId,
            toolName: $name,
            success: $success,
        ));
    }
```

- [ ] **Step 7: Update `AgentAnalyticsEventsTest` to assert the usage event**

Add this test method to `tests/Feature/AgentAnalyticsEventsTest.php`:

```php
    public function test_send_dispatches_token_usage_recorded()
    {
        Event::fake();

        $provider = \Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')->once()->andReturn(new AgentResponse(
            message: Message::assistant('oi'),
            stopReason: 'stop',
            inputTokens: 10,
            outputTokens: 5,
        ));

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $agent = $this->app->make(Agent::class);
        $agent->provider('openai')->send('olá');

        Event::assertDispatched(\Peralta\AgentKit\Events\TokenUsageRecorded::class, fn($e) => $e->inputTokens === 10 && $e->outputTokens === 5);
    }
```

- [ ] **Step 8: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS (all tests including the two new ones; no more direct `Log::` calls left in `Agent.php`)

- [ ] **Step 9: Commit**

```bash
git add src/Agent.php src/Listeners/LogUsageListener.php src/Listeners/LogToolCallListener.php tests/Unit/Listeners tests/Feature/AgentAnalyticsEventsTest.php
git commit -m "refactor: replace direct Log calls with events + log listeners"
```

---

## Task 8: Dispatch `RecoveryAttempted`/`RecoveryExhausted` from `RetryMiddleware` and `FallbackMiddleware`

**Files:**
- Modify: `src/ErrorRecovery/Middleware/RetryMiddleware.php`
- Modify: `src/ErrorRecovery/Middleware/FallbackMiddleware.php`
- Test: `tests/Unit/ErrorRecovery/RetryMiddlewareTest.php` (extend)
- Test: `tests/Feature/ErrorRecovery/FallbackMiddlewareTest.php` (extend)

- [ ] **Step 1: Read the existing middleware tests to match their mocking conventions**

Run: `cat tests/Unit/ErrorRecovery/RetryMiddlewareTest.php tests/Feature/ErrorRecovery/FallbackMiddlewareTest.php`

- [ ] **Step 2: Add a failing test to `RetryMiddlewareTest`**

Append this test method (adjust the constructor args of `RetryMiddleware`/mocks to match exactly what the existing tests in that file already set up — reuse the same `$strategy`/`$classifier` mocks pattern already present):

```php
    public function test_dispatches_recovery_attempted_on_each_retry_and_recovery_exhausted_on_final_failure()
    {
        \Illuminate\Support\Facades\Event::fake();

        $classifier = Mockery::mock(\Peralta\AgentKit\ErrorRecovery\Contracts\ErrorClassifier::class);
        $classifier->shouldReceive('classify')->andReturn(\Peralta\AgentKit\ErrorRecovery\Enums\ErrorType::SERVER_ERROR);

        $strategy = Mockery::mock(\Peralta\AgentKit\ErrorRecovery\Contracts\RetryStrategy::class);
        $strategy->shouldReceive('shouldRetry')->once()->with(\Peralta\AgentKit\ErrorRecovery\Enums\ErrorType::SERVER_ERROR, 1)->andReturn(true);
        $strategy->shouldReceive('delayMs')->once()->andReturn(0);
        $strategy->shouldReceive('shouldRetry')->once()->with(\Peralta\AgentKit\ErrorRecovery\Enums\ErrorType::SERVER_ERROR, 2)->andReturn(false);

        $middleware = new \Peralta\AgentKit\ErrorRecovery\Middleware\RetryMiddleware($strategy, $classifier);

        $context = new \Peralta\AgentKit\DTOs\RecoveryContext(provider: 'openai', conversationId: 'conv-1');

        try {
            $middleware->handle(function () {
                throw new \RuntimeException('boom');
            }, $context);
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException) {
            // esperado
        }

        \Illuminate\Support\Facades\Event::assertDispatched(\Peralta\AgentKit\Events\RecoveryAttempted::class, fn($e) => $e->strategy === 'retry' && $e->attemptNumber === 1);
        \Illuminate\Support\Facades\Event::assertDispatched(\Peralta\AgentKit\Events\RecoveryExhausted::class, fn($e) => $e->attempts === 2);
    }
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ErrorRecovery/RetryMiddlewareTest.php`
Expected: FAIL — no events dispatched

- [ ] **Step 4: Instrument `RetryMiddleware`**

Replace `src/ErrorRecovery/Middleware/RetryMiddleware.php` entirely with:

```php
<?php

namespace Peralta\AgentKit\ErrorRecovery\Middleware;

use Illuminate\Support\Facades\Event;
use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\ErrorRecovery\Contracts\ErrorClassifier;
use Peralta\AgentKit\ErrorRecovery\Contracts\Middleware;
use Peralta\AgentKit\ErrorRecovery\Contracts\RetryStrategy;
use Peralta\AgentKit\Events\RecoveryAttempted;
use Peralta\AgentKit\Events\RecoveryExhausted;
use Throwable;

class RetryMiddleware implements Middleware
{
    public function __construct(
        protected RetryStrategy $strategy,
        protected ErrorClassifier $classifier,
    ) {}

    public function handle(callable $handler, RecoveryContext $context): mixed
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $handler($context);
            } catch (Throwable $e) {
                $errorType = $this->classifier->classify($e);
                $context->recordFailure($context->provider, $errorType->value);

                Event::dispatch(new RecoveryAttempted(
                    conversationId: $context->conversationId,
                    provider: $context->provider,
                    attemptNumber: $attempt,
                    strategy: 'retry',
                    errorClass: $errorType->value,
                ));

                if (!$this->strategy->shouldRetry($errorType, $attempt)) {
                    Event::dispatch(new RecoveryExhausted(
                        conversationId: $context->conversationId,
                        attempts: $attempt,
                        finalErrorClass: $errorType->value,
                    ));

                    throw $e;
                }

                usleep($this->strategy->delayMs($errorType, $attempt) * 1000);
            }
        }
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ErrorRecovery/RetryMiddlewareTest.php`
Expected: PASS (all tests including the new one)

- [ ] **Step 6: Add a failing test to `FallbackMiddlewareTest`**

Read the file first (`cat tests/Feature/ErrorRecovery/FallbackMiddlewareTest.php`) to match its existing setup for `$classifier`/`$availableProviders`/`$config`, then append:

```php
    public function test_dispatches_recovery_attempted_on_switch_and_recovery_exhausted_when_all_fail()
    {
        \Illuminate\Support\Facades\Event::fake();

        $classifier = Mockery::mock(\Peralta\AgentKit\ErrorRecovery\Contracts\ErrorClassifier::class);
        $classifier->shouldReceive('classify')->andReturn(\Peralta\AgentKit\ErrorRecovery\Enums\ErrorType::SERVER_ERROR);

        $middleware = new \Peralta\AgentKit\ErrorRecovery\Middleware\FallbackMiddleware(
            $classifier,
            ['openai', 'anthropic'],
            ['on_errors' => ['SERVER_ERROR'], 'skip_on_errors' => []],
        );

        $context = new \Peralta\AgentKit\DTOs\RecoveryContext(provider: 'openai', conversationId: 'conv-1');

        try {
            $middleware->handle(function () {
                throw new \RuntimeException('boom');
            }, $context);
            $this->fail('Expected exception was not thrown');
        } catch (\Peralta\AgentKit\Exceptions\AllProvidersFailedException) {
            // esperado
        }

        \Illuminate\Support\Facades\Event::assertDispatched(\Peralta\AgentKit\Events\RecoveryAttempted::class, fn($e) => $e->strategy === 'fallback' && $e->provider === 'anthropic');
        \Illuminate\Support\Facades\Event::assertDispatched(\Peralta\AgentKit\Events\RecoveryExhausted::class);
    }
```

- [ ] **Step 7: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ErrorRecovery/FallbackMiddlewareTest.php`
Expected: FAIL — no events dispatched

- [ ] **Step 8: Instrument `FallbackMiddleware`**

Replace `src/ErrorRecovery/Middleware/FallbackMiddleware.php` entirely with:

```php
<?php

namespace Peralta\AgentKit\ErrorRecovery\Middleware;

use Illuminate\Support\Facades\Event;
use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\ErrorRecovery\Contracts\ErrorClassifier;
use Peralta\AgentKit\ErrorRecovery\Contracts\Middleware;
use Peralta\AgentKit\Events\RecoveryAttempted;
use Peralta\AgentKit\Events\RecoveryExhausted;
use Peralta\AgentKit\Exceptions\AllProvidersFailedException;
use Throwable;

class FallbackMiddleware implements Middleware
{
    /**
     * @param  string[]  $availableProviders  Todos os providers configurados, na ordem de fallback.
     * @param  array{on_errors?: string[], skip_on_errors?: string[]}  $config
     */
    public function __construct(
        protected ErrorClassifier $classifier,
        protected array $availableProviders,
        protected array $config = [],
    ) {}

    public function handle(callable $handler, RecoveryContext $context): mixed
    {
        $originalProvider = $context->provider;
        $tried = [$originalProvider];
        $attempt = 1;

        try {
            return $handler($context);
        } catch (Throwable $e) {
            $errorType = $this->classifier->classify($e);
            $context->recordFailure($originalProvider, $errorType->value);

            if (!$this->isFallbackEligible($errorType->value)) {
                throw $e;
            }

            $lastException = $e;
            $lastErrorType = $errorType->value;

            foreach ($this->remainingProviders($tried) as $provider) {
                $tried[] = $provider;
                $attempt++;
                $context->switchProvider($provider);

                Event::dispatch(new RecoveryAttempted(
                    conversationId: $context->conversationId,
                    provider: $provider,
                    attemptNumber: $attempt,
                    strategy: 'fallback',
                    errorClass: $lastErrorType,
                ));

                try {
                    return $handler($context);
                } catch (Throwable $inner) {
                    $innerType = $this->classifier->classify($inner);
                    $context->recordFailure($provider, $innerType->value);
                    $lastException = $inner;
                    $lastErrorType = $innerType->value;
                    continue;
                }
            }

            Event::dispatch(new RecoveryExhausted(
                conversationId: $context->conversationId,
                attempts: $attempt,
                finalErrorClass: $lastErrorType,
            ));

            throw new AllProvidersFailedException(
                "Todos os providers falharam: " . implode(', ', $tried),
                $context->summary(),
                $lastException,
            );
        }
    }

    private function isFallbackEligible(string $errorType): bool
    {
        $onErrors = $this->config['on_errors'] ?? [];
        $skipErrors = $this->config['skip_on_errors'] ?? [];

        if (in_array($errorType, $skipErrors, true)) {
            return false;
        }

        return in_array($errorType, $onErrors, true);
    }

    /**
     * @param  string[]  $tried
     * @return string[]
     */
    private function remainingProviders(array $tried): array
    {
        return array_values(array_diff($this->availableProviders, $tried));
    }
}
```

- [ ] **Step 9: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ErrorRecovery/FallbackMiddlewareTest.php`
Expected: PASS (all tests including the new one)

- [ ] **Step 10: Run the whole ErrorRecovery suite to confirm no regressions**

Run: `vendor/bin/phpunit tests/Unit/ErrorRecovery tests/Feature/ErrorRecovery`
Expected: PASS (all tests)

- [ ] **Step 11: Commit**

```bash
git add src/ErrorRecovery/Middleware/RetryMiddleware.php src/ErrorRecovery/Middleware/FallbackMiddleware.php tests/Unit/ErrorRecovery/RetryMiddlewareTest.php tests/Feature/ErrorRecovery/FallbackMiddlewareTest.php
git commit -m "feat: dispatch recovery events from retry and fallback middleware"
```

---

## Task 9: Create the `agent_kit_metrics` migration and `AgentMetric` model

**Files:**
- Create: `database/migrations/2026_08_08_000003_create_agent_kit_metrics_table.php`
- Create: `src/Models/AgentMetric.php`
- Test: `tests/Unit/Models/AgentMetricTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Models;

use Peralta\AgentKit\Models\AgentMetric;
use Peralta\AgentKit\Tests\TestCase;

class AgentMetricTest extends TestCase
{
    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    public function test_can_create_and_read_a_metric_row()
    {
        $metric = AgentMetric::create([
            'type' => 'token_usage',
            'conversation_id' => 'conv-1',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'duration_ms' => 120,
            'payload' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);

        $this->assertDatabaseHas('agent_kit_metrics', [
            'id' => $metric->id,
            'type' => 'token_usage',
            'conversation_id' => 'conv-1',
        ]);

        $fresh = AgentMetric::find($metric->id);
        $this->assertEquals(['input_tokens' => 10, 'output_tokens' => 5], $fresh->payload);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Models/AgentMetricTest.php`
Expected: FAIL — class `Peralta\AgentKit\Models\AgentMetric` not found

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = config('agent-kit.analytics.table', 'agent_kit_metrics');

        Schema::create($table, function (Blueprint $t) {
            $t->id();
            $t->string('type', 40);
            $t->string('conversation_id', 100)->nullable()->index();
            $t->string('provider', 40)->nullable();
            $t->string('model', 80)->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->json('payload')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        $table = config('agent-kit.analytics.table', 'agent_kit_metrics');
        Schema::dropIfExists($table);
    }
};
```

- [ ] **Step 4: Create the `AgentMetric` model**

```php
<?php

namespace Peralta\AgentKit\Models;

use Illuminate\Database\Eloquent\Model;

class AgentMetric extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'type',
        'conversation_id',
        'provider',
        'model',
        'duration_ms',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('agent-kit.analytics.table', 'agent_kit_metrics');
    }

    protected static function booted(): void
    {
        static::creating(function (self $metric) {
            $metric->created_at ??= now();
        });
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Models/AgentMetricTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_08_000003_create_agent_kit_metrics_table.php src/Models/AgentMetric.php tests/Unit/Models/AgentMetricTest.php
git commit -m "feat: add agent_kit_metrics migration and AgentMetric model"
```

---

## Task 10: Create `PersistMetricsListener`

**Files:**
- Create: `src/Listeners/PersistMetricsListener.php`
- Test: `tests/Unit/Listeners/PersistMetricsListenerTest.php`

The listener maps each `AgentKitEvent` to a row. It extracts common columns (`type`, `conversation_id`, `provider`, `model`, `duration_ms`) via a small match on the event's class, and puts the rest of the event's public properties into `payload`. Failures must never bubble up (best-effort persistence, per spec section 7).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Listeners;

use Peralta\AgentKit\Events\AgentRequestCompleted;
use Peralta\AgentKit\Events\ToolCallExecuted;
use Peralta\AgentKit\Listeners\PersistMetricsListener;
use Peralta\AgentKit\Models\AgentMetric;
use Peralta\AgentKit\Tests\TestCase;

class PersistMetricsListenerTest extends TestCase
{
    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    public function test_persists_agent_request_completed_with_common_columns()
    {
        (new PersistMetricsListener())->handle(new AgentRequestCompleted(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4o',
            durationMs: 250,
            iterations: 2,
        ));

        $this->assertDatabaseHas('agent_kit_metrics', [
            'type' => 'request_completed',
            'conversation_id' => 'conv-1',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'duration_ms' => 250,
        ]);

        $row = AgentMetric::first();
        $this->assertEquals(2, $row->payload['iterations']);
    }

    public function test_persists_tool_call_executed_without_provider_or_duration()
    {
        (new PersistMetricsListener())->handle(new ToolCallExecuted(
            conversationId: 'conv-1',
            toolName: 'search',
            success: true,
        ));

        $this->assertDatabaseHas('agent_kit_metrics', [
            'type' => 'tool_call',
            'conversation_id' => 'conv-1',
        ]);

        $row = AgentMetric::first();
        $this->assertEquals('search', $row->payload['toolName']);
        $this->assertTrue($row->payload['success']);
    }

    public function test_swallows_exceptions_instead_of_throwing()
    {
        config(['agent-kit.analytics.table' => 'a_table_that_does_not_exist']);

        (new PersistMetricsListener())->handle(new ToolCallExecuted(
            conversationId: 'conv-1',
            toolName: 'search',
            success: true,
        ));

        $this->assertTrue(true); // não deve lançar
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Listeners/PersistMetricsListenerTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create the listener**

```php
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
            Log::warning('[agent-kit] Falha ao persistir métrica', [
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Listeners/PersistMetricsListenerTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Listeners/PersistMetricsListener.php tests/Unit/Listeners/PersistMetricsListenerTest.php
git commit -m "feat: add PersistMetricsListener for agent_kit_metrics table"
```

---

## Task 11: Register listeners conditionally in `AgentKitServiceProvider`

**Files:**
- Modify: `src/AgentKitServiceProvider.php`
- Test: `tests/Feature/AnalyticsListenerRegistrationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Peralta\AgentKit\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Peralta\AgentKit\Events\TokenUsageRecorded;
use Peralta\AgentKit\Models\AgentMetric;
use Peralta\AgentKit\Tests\TestCase;

class AnalyticsListenerRegistrationTest extends TestCase
{
    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    public function test_log_usage_listener_fires_when_logging_enabled()
    {
        config(['agent-kit.logging.enabled' => true, 'agent-kit.logging.channel' => 'stack']);
        Log::shouldReceive('channel')->with('stack')->andReturnSelf();
        Log::shouldReceive('info')->once();

        event(new TokenUsageRecorded(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4o',
            inputTokens: 1,
            outputTokens: 1,
        ));
    }

    public function test_persist_metrics_listener_fires_when_analytics_persist_enabled()
    {
        config(['agent-kit.analytics.persist' => true, 'agent-kit.logging.enabled' => false]);

        event(new TokenUsageRecorded(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4o',
            inputTokens: 1,
            outputTokens: 1,
        ));

        $this->assertDatabaseHas('agent_kit_metrics', ['conversation_id' => 'conv-1']);
    }

    public function test_persist_metrics_listener_does_not_fire_when_analytics_persist_disabled()
    {
        config(['agent-kit.analytics.persist' => false, 'agent-kit.logging.enabled' => false]);

        event(new TokenUsageRecorded(
            conversationId: 'conv-1',
            provider: 'openai',
            model: 'gpt-4o',
            inputTokens: 1,
            outputTokens: 1,
        ));

        $this->assertDatabaseCount('agent_kit_metrics', 0);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/AnalyticsListenerRegistrationTest.php`
Expected: FAIL — `test_persist_metrics_listener_fires_when_analytics_persist_enabled` fails because nothing is registered to write the row (and possibly `test_log_usage_listener_fires_when_logging_enabled` fails because `Log::info` is never called)

- [ ] **Step 3: Register the listeners in the service provider**

In `src/AgentKitServiceProvider.php`, add imports:

```php
use Illuminate\Support\Facades\Event;
use Peralta\AgentKit\Events\AgentKitEvent;
use Peralta\AgentKit\Events\ToolCallExecuted;
use Peralta\AgentKit\Events\TokenUsageRecorded;
use Peralta\AgentKit\Listeners\LogToolCallListener;
use Peralta\AgentKit\Listeners\LogUsageListener;
use Peralta\AgentKit\Listeners\PersistMetricsListener;
```

Add a new method `registerAnalytics()` and call it from `register()`:

```php
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/agent-kit.php', 'agent-kit');

        $this->registerProviders();
        $this->registerConversation();
        $this->registerKnowledge();
        $this->registerErrorRecovery();
        $this->registerAgent();
        $this->registerAnalytics();
    }
```

```php
    protected function registerAnalytics(): void
    {
        if (config('agent-kit.logging.enabled', false)) {
            Event::listen(TokenUsageRecorded::class, LogUsageListener::class);

            if (config('agent-kit.logging.log_tool_calls', false)) {
                Event::listen(ToolCallExecuted::class, LogToolCallListener::class);
            }
        }

        if (config('agent-kit.analytics.persist', false)) {
            Event::listen(AgentKitEvent::class, PersistMetricsListener::class);
        }
    }
```

Note: `Event::listen(AgentKitEvent::class, ...)` relies on Laravel's interface-based event listening (it matches any event implementing the interface) — this is the same mechanism used for `Illuminate\Foundation\Events\*` interface listeners.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/AnalyticsListenerRegistrationTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Run the entire test suite**

Run: `vendor/bin/phpunit`
Expected: PASS (all tests green, no regressions)

- [ ] **Step 6: Commit**

```bash
git add src/AgentKitServiceProvider.php tests/Feature/AnalyticsListenerRegistrationTest.php
git commit -m "feat: conditionally register analytics listeners in service provider"
```

---

## Task 12: End-to-end integration test

**Files:**
- Test: `tests/Feature/AnalyticsIntegrationTest.php`

Verifies the whole chain works together on a realistic `Agent::send()` call: events fire, get logged, and get persisted, matching what's described in the spec's overview.

- [ ] **Step 1: Write the test**

```php
<?php

namespace Peralta\AgentKit\Tests\Feature;

use Peralta\AgentKit\Agent;
use Peralta\AgentKit\Contracts\Provider;
use Peralta\AgentKit\DTOs\AgentResponse;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\Models\AgentMetric;
use Peralta\AgentKit\Tests\TestCase;

class AnalyticsIntegrationTest extends TestCase
{
    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    public function test_full_send_call_persists_all_expected_metric_rows()
    {
        config([
            'agent-kit.analytics.enabled' => true,
            'agent-kit.analytics.persist' => true,
            'agent-kit.logging.enabled' => false,
        ]);

        $provider = \Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')->once()->andReturn(new AgentResponse(
            message: Message::assistant('oi'),
            stopReason: 'stop',
            inputTokens: 20,
            outputTokens: 8,
        ));

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $agent = $this->app->make(Agent::class);
        $agent->provider('openai')->conversation('conv-int-1')->send('olá');

        $types = AgentMetric::pluck('type')->all();

        $this->assertContains('request_started', $types);
        $this->assertContains('provider_call', $types);
        $this->assertContains('token_usage', $types);
        $this->assertContains('request_completed', $types);

        $usageRow = AgentMetric::where('type', 'token_usage')->first();
        $this->assertEquals(20, $usageRow->payload['inputTokens']);
        $this->assertEquals(8, $usageRow->payload['outputTokens']);
    }

    public function test_analytics_disabled_persists_nothing()
    {
        config([
            'agent-kit.analytics.enabled' => false,
            'agent-kit.analytics.persist' => true,
            'agent-kit.logging.enabled' => false,
        ]);

        $provider = \Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')->once()->andReturn(new AgentResponse(
            message: Message::assistant('oi'),
            stopReason: 'stop',
            inputTokens: 20,
            outputTokens: 8,
        ));

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $agent = $this->app->make(Agent::class);
        $agent->provider('openai')->send('olá');

        $this->assertDatabaseCount('agent_kit_metrics', 0);
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/AnalyticsIntegrationTest.php`
Expected: PASS (2 tests) — if it fails, re-check Task 11's `registerAnalytics()` is being picked up (config set via `config([...])` in a test happens after `ServiceProvider::register()` ran during app boot, so listener registration must read config lazily — verify `Event::listen` calls in `registerAnalytics()` are wrapped correctly; if this test fails due to config timing, move the `if` checks inside the listener's `handle()` method instead of around `Event::listen()`, matching the pattern already used by `LogUsageListener`/`LogToolCallListener`, and register listeners unconditionally)

- [ ] **Step 3: If Step 2 needed the config-timing fix, apply it now**

If listener registration must be config-independent (registered always, gated inside `handle()`), update `PersistMetricsListener::handle()` to add this check at the top:

```php
    public function handle(AgentKitEvent $event): void
    {
        if (!config('agent-kit.analytics.persist', false)) return;

        try {
            AgentMetric::create($this->mapEvent($event));
        } catch (\Throwable $e) {
            Log::warning('[agent-kit] Falha ao persistir métrica', [
                'event' => get_class($event),
                'error' => $e->getMessage(),
            ]);
        }
    }
```

And simplify `registerAnalytics()` in `src/AgentKitServiceProvider.php` to always register (removing the `if` wrappers), since each listener now gates itself internally — consistent with how `LogUsageListener`/`LogToolCallListener` already self-gate on `logging.enabled`:

```php
    protected function registerAnalytics(): void
    {
        Event::listen(TokenUsageRecorded::class, LogUsageListener::class);
        Event::listen(ToolCallExecuted::class, LogToolCallListener::class);
        Event::listen(AgentKitEvent::class, PersistMetricsListener::class);
    }
```

Then re-run: `vendor/bin/phpunit tests/Feature/AnalyticsListenerRegistrationTest.php tests/Feature/AnalyticsIntegrationTest.php`
Expected: PASS (all tests)

- [ ] **Step 4: Run the full test suite one final time**

Run: `vendor/bin/phpunit`
Expected: PASS (every test in the suite green)

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/AnalyticsIntegrationTest.php src/Listeners/PersistMetricsListener.php src/Listeners/LogUsageListener.php src/Listeners/LogToolCallListener.php src/AgentKitServiceProvider.php
git commit -m "test: add end-to-end analytics integration test"
```

---

## Task 13: Update README (if it documents config sections)

**Files:**
- Modify: `README.md` (only if it enumerates config sections like `logging` / `error_recovery`)

- [ ] **Step 1: Check whether README documents config sections**

Run: `grep -n "logging\|error_recovery" README.md`

- [ ] **Step 2: If matches are found, add an `analytics` entry following the same format as the existing `logging`/`error_recovery` entries**

Read the matched section with `Read` first, then add a short subsection documenting: `analytics.enabled`, `analytics.persist`, `analytics.table`, and the list of 7 events from the spec (Section 4) with one line each. Match the existing README's heading level and language (Portuguese, consistent with the rest of the file).

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: document analytics config and events"
```

(Skip this task's commit if Step 1 found no matches — the README doesn't document config sections and nothing needs to change.)

---

## Final Verification

- [ ] Run the complete suite once more: `vendor/bin/phpunit`
- [ ] Confirm no remaining `Log::` calls exist directly in `src/Agent.php`: `grep -n "Log::" src/Agent.php` should return nothing
- [ ] Confirm all 7 event classes exist: `ls src/Events/`
- [ ] Confirm the 3 listeners exist: `ls src/Listeners/`
