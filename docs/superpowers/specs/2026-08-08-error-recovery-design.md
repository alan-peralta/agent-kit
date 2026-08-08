# Error Recovery System - Design Spec

**Date**: 2026-08-08  
**Status**: Approved  
**Author**: Alan Peralta  

---

## 1. Overview

Implement a comprehensive error recovery system for Agent Kit that automatically handles failures across the entire AI agent pipeline: provider calls, tool execution, conversation persistence, and knowledge base operations.

**Goals:**
- Automatically retry failed operations with exponential backoff + jitter
- Fallback to alternative providers when primary fails
- Alert via Discord when critical failures occur
- Transparent to users (recovery happens silently)
- Adaptable strategies per error type

**Key Requirements:**
- Retry: 5-7 attempts with exponential backoff + jitter
- Fallback: any available provider (order configurable)
- Alerts: Discord webhook on fallback OR total failure
- Behavior: silent/transparent (no user-facing notifications during recovery)
- Strategies: adaptive by error type (timeout ≠ auth error ≠ invalid request)

---

## 2. Architecture: Middleware Chain

Error recovery is implemented as a middleware pipeline. Each middleware intercepts operations and adds capabilities:

```
User Request
    ↓
[RetryMiddleware] ← tenta N vezes com backoff
    ↓
[FallbackMiddleware] ← se falhar, tenta outro provider
    ↓
[DiscordAlertMiddleware] ← notifica Discord em caso crítico
    ↓
[Operação Real] (provider call, tool execution, etc)
    ↓
Response
```

**Middleware responsibilities:**

| Middleware | Input | Output | Decision |
|---|---|---|---|
| **RetryMiddleware** | Handler + Context | Result OR Exception | Retry now? Delay how long? |
| **FallbackMiddleware** | Handler + Context | Result OR Exception | Try next provider? Which one? |
| **DiscordAlertMiddleware** | Handler + Context | Result OR Exception | Send alert? What message? |

Each middleware:
- Receives a "handler" (callable that executes the operation)
- Can intercept before/after, or catch exceptions
- Decides whether to retry, fallback, alert, or re-throw
- Passes control to next middleware

---

## 3. Error Classification & Adaptive Strategies

### 3.1 Error Types

Errors are classified into 5 categories:

```php
enum ErrorType {
    case NETWORK_TIMEOUT;      // Connection timeout, DNS failure
    case RATE_LIMIT;           // 429 Too Many Requests
    case INVALID_REQUEST;      // 400 Bad Request (app error)
    case AUTH_ERROR;           // 401/403 Unauthorized
    case SERVER_ERROR;         // 5xx server errors
}
```

### 3.2 Retry Policies

**Matrix of behavior by error type:**

| Error Type | Retry? | Max Attempts | Backoff | Multiplier | Fallback? | Discord Alert? |
|---|---|---|---|---|---|---|
| NETWORK_TIMEOUT | ✅ | 7 | Exponential + Jitter | 2.0x | ✅ | Only if all fail |
| RATE_LIMIT | ✅ | 5 | Exponential + Jitter | 3.0x | ✅ | Only if all fail |
| SERVER_ERROR | ✅ | 5 | Exponential + Jitter | 2.0x | ✅ | Only if all fail |
| AUTH_ERROR | ❌ | 0 | — | — | ✅ | Immediate |
| INVALID_REQUEST | ❌ | 0 | — | — | ❌ | No |

**Backoff calculation:**

```
delay = min(
    initial_delay * (multiplier ^ (attempt - 1)),
    max_delay
) + random(0, jitter_range)

Example for NETWORK_TIMEOUT:
- Attempt 1: 1000ms + jitter
- Attempt 2: 2000ms + jitter
- Attempt 3: 4000ms + jitter
- Attempt 4: 8000ms + jitter
- ...up to max_delay (30000ms)
```

---

## 4. Component Structure

### 4.1 Directory Structure

```
src/ErrorRecovery/
├── Contracts/
│   ├── Middleware.php
│   ├── ErrorClassifier.php
│   ├── RetryStrategy.php
│   └── AlertNotifier.php
├── Middleware/
│   ├── RetryMiddleware.php
│   ├── FallbackMiddleware.php
│   └── DiscordAlertMiddleware.php
├── Classifiers/
│   └── DefaultErrorClassifier.php
├── Strategies/
│   └── AdaptiveRetryStrategy.php
├── Notifiers/
│   ├── DiscordNotifier.php
│   └── NullNotifier.php
├── Enums/
│   └── ErrorType.php
└── Pipeline.php
```

### 4.2 Core Interfaces

```php
// Contracts/Middleware.php
interface Middleware
{
    public function handle(callable $handler, Context $context): mixed;
}

// Contracts/ErrorClassifier.php
interface ErrorClassifier
{
    public function classify(Throwable $error): ErrorType;
}

// Contracts/RetryStrategy.php
interface RetryStrategy
{
    public function shouldRetry(ErrorType $type, int $attempt): bool;
    public function delayMs(ErrorType $type, int $attempt): int;
}

// Contracts/AlertNotifier.php
interface AlertNotifier
{
    public function notifyFailure(string $message, array $context): void;
    public function notifyFallback(string $provider, string $reason): void;
}
```

### 4.3 Key Classes

**ErrorType Enum** — Classifies errors into 5 categories

**DefaultErrorClassifier** — Maps exceptions → ErrorType
- Guzzle RequestException with timeout → NETWORK_TIMEOUT
- HTTP 429 response → RATE_LIMIT
- HTTP 5xx response → SERVER_ERROR
- HTTP 400 response → INVALID_REQUEST
- HTTP 401/403 response → AUTH_ERROR

**AdaptiveRetryStrategy** — Uses the error matrix above
- Consults ErrorType to decide retry count, backoff pattern
- Implements exponential backoff with jitter

**DiscordNotifier** — Implements AlertNotifier
- Sends webhook to Discord on fallback or total failure
- Includes context (provider, error type, attempt count)

**Pipeline** — Orchestrates middleware chain
- Applies RetryMiddleware → FallbackMiddleware → DiscordAlertMiddleware
- Returns result or throws exception

---

## 5. Call Flow Scenarios

### 5.1 Scenario 1: Success on First Try

```
User.send("Olá")
    → [RetryMiddleware]
        → [FallbackMiddleware]
            → [DiscordAlertMiddleware]
                → OpenAI API call → Success ✅
    → Response to user (completely transparent)
```

### 5.2 Scenario 2: Timeout, Retry Succeeds

```
User.send("Olá")
    → [RetryMiddleware]
        Attempt 1: OpenAI timeout (NETWORK_TIMEOUT)
        → Wait 1000ms + jitter
        Attempt 2: OpenAI timeout
        → Wait 2000ms + jitter
        Attempt 3: OpenAI success ✅
    → Response to user (user never knows there was retry)
```

### 5.3 Scenario 3: OpenAI Exhausted, Fallback to Anthropic

```
User.send("Olá")
    → [RetryMiddleware]
        Attempts 1-7: OpenAI fails (SERVER_ERROR)
    → [FallbackMiddleware]
        All retries exhausted
        → Switch to Anthropic
        → [DiscordAlertMiddleware]
            → Send to Discord: "⚠️ Fallback activated: OpenAI→Anthropic"
    → Anthropic API call → Success ✅
    → Response to user
```

### 5.4 Scenario 4: All Providers Exhausted

```
User.send("Olá")
    → [RetryMiddleware]
        OpenAI: attempts 1-7 fail (NETWORK_TIMEOUT)
    → [FallbackMiddleware]
        Anthropic: attempts 1-7 fail (NETWORK_TIMEOUT)
        Gemini: attempts 1-7 fail (AUTH_ERROR)
        DeepSeek: attempts 1-7 fail (NETWORK_TIMEOUT)
    → [DiscordAlertMiddleware]
        → Send to Discord: "🚨 CRITICAL: All providers exhausted"
        → Include: error types, attempt counts, providers tried
    → Throw AgentException to user
```

---

## 6. Configuration

### 6.1 Config File: `config/agent-kit.php`

```php
'error_recovery' => [
    'enabled' => env('AGENT_ERROR_RECOVERY_ENABLED', true),
    
    // Retry configuration
    'retry' => [
        'enabled' => true,
        'strategy' => 'adaptive', // or 'fixed', 'linear'
        
        // Per error type
        'policies' => [
            'NETWORK_TIMEOUT' => [
                'max_attempts' => 7,
                'initial_delay_ms' => 1000,
                'max_delay_ms' => 30000,
                'multiplier' => 2.0,
                'jitter' => true,
            ],
            'RATE_LIMIT' => [
                'max_attempts' => 5,
                'initial_delay_ms' => 2000,
                'max_delay_ms' => 60000,
                'multiplier' => 3.0,
                'jitter' => true,
            ],
            'SERVER_ERROR' => [
                'max_attempts' => 5,
                'initial_delay_ms' => 1000,
                'max_delay_ms' => 20000,
                'multiplier' => 2.0,
                'jitter' => true,
            ],
            'AUTH_ERROR' => [
                'max_attempts' => 0, // no retry, goes straight to fallback
            ],
            'INVALID_REQUEST' => [
                'max_attempts' => 0, // never retry (app error)
            ],
        ],
    ],
    
    // Fallback configuration
    'fallback' => [
        'enabled' => true,
        'strategy' => 'any_available', // or 'priority', 'round_robin'
        
        // Provider order (only for 'priority' strategy)
        'provider_order' => [
            'anthropic',
            'gemini',
            'openai',
            'deepseek',
        ],
        
        // Which errors trigger fallback
        'on_errors' => [
            'NETWORK_TIMEOUT',
            'RATE_LIMIT',
            'SERVER_ERROR',
            'AUTH_ERROR',
        ],
        
        // Which errors skip fallback
        'skip_on_errors' => [
            'INVALID_REQUEST', // app error, not API error
        ],
    ],
    
    // Discord alerting
    'alerts' => [
        'enabled' => env('AGENT_DISCORD_ALERTS_ENABLED', false),
        'notifier' => 'discord', // or 'null', 'log'
        
        'discord' => [
            'webhook_url' => env('AGENT_DISCORD_WEBHOOK_URL'),
            
            // When to send alerts
            'on_events' => [
                'fallback_activated' => true,    // when switching providers
                'all_providers_failed' => true,  // when everything fails
                'auth_error' => true,            // authentication error
            ],
            
            // Message customization
            'mention_on_critical' => env('AGENT_DISCORD_MENTION', '@oncall'),
            'include_context' => true,
            'include_stacktrace' => env('APP_DEBUG', false),
        ],
    ],
    
    // Logging
    'logging' => [
        'log_retries' => true,
        'log_fallbacks' => true,
        'log_all_errors' => env('APP_DEBUG', false),
    ],
],
```

### 6.2 Environment Variables

```env
AGENT_ERROR_RECOVERY_ENABLED=true
AGENT_DISCORD_ALERTS_ENABLED=true
AGENT_DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/YOUR_ID/YOUR_TOKEN
AGENT_DISCORD_MENTION=@oncall
```

---

## 7. Testing Strategy

### 7.1 Unit Tests

- **DefaultErrorClassifier**: Maps exceptions → ErrorType correctly
- **AdaptiveRetryStrategy**: Calculates delays, respects max attempts
- **DiscordNotifier**: Formats messages, handles API failures

### 7.2 Feature Tests

- **RetryMiddleware**: Retries on transient errors, stops on permanent errors
- **FallbackMiddleware**: Switches providers, preserves context
- **DiscordAlertMiddleware**: Sends alerts on critical events
- **Full integration**: Retry → Fallback → Alert flow

### 7.3 Test Helpers

- `MocksErrorRecovery` trait for mocking provider failures/successes
- HTTP client faking for Discord webhook calls
- Configurable error scenarios (timeout, rate limit, auth error, etc)

---

## 8. Integration Points

### 8.1 How It Integrates with Agent

The `Agent` class uses the Pipeline:

```php
public function send(string $message): AgentResponse
{
    $pipeline = new Pipeline(
        new RetryMiddleware(...),
        new FallbackMiddleware(...),
        new DiscordAlertMiddleware(...),
    );
    
    return $pipeline->execute(function(Context $ctx) {
        return $this->callProvider($ctx);
    }, $this->context);
}
```

### 8.2 Scope of Recovery

Error recovery applies to:
- ✅ Provider API calls (OpenAI, Anthropic, Gemini, DeepSeek)
- ✅ Tool execution (custom tools returning errors)
- ✅ Conversation persistence (Redis/Database load/save)
- ✅ Knowledge Base queries (pgvector/Qdrant searches)

### 8.3 What's NOT Recovered

- Invalid user input (validation errors) — fail immediately
- Malformed tool schemas — fail immediately
- Database migrations — handled separately
- Configuration errors — fail immediately

---

## 9. Success Criteria

- ✅ Retry works: failed calls retry automatically
- ✅ Fallback works: alternate provider is used when primary fails
- ✅ Alerts work: Discord webhook receives messages on fallback/critical failure
- ✅ Transparent: users don't see retry/fallback happening
- ✅ Configurable: each error type has customizable retry policy
- ✅ Tested: unit + feature + integration tests cover all scenarios
- ✅ Performant: backoff prevents thundering herd (jitter)
- ✅ Logged: all retries, fallbacks, alerts logged for debugging

---

## 10. Future Enhancements (Out of Scope)

- Circuit breaker pattern (disable provider if consistently failing)
- Provider health scoring (prefer healthier providers for fallback)
- Metrics/observability (Prometheus metrics for retries/fallbacks)
- Retry budget per conversation (limit total retry time)
- Custom retry strategies per tool
- Webhook for custom alerting systems (Slack, PagerDuty, etc)

