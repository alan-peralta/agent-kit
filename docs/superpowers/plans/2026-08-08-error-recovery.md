# Error Recovery System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add automatic retry, provider fallback, and Discord alerting to Agent Kit so that transient provider/tool/persistence failures are recovered transparently, and critical failures are surfaced to the team.

**Architecture:** A middleware chain (`RetryMiddleware` → `FallbackMiddleware` → `DiscordAlertMiddleware`) wraps provider calls inside `Agent::send()`. Errors are classified into 5 types (`ErrorType` enum) by a `DefaultErrorClassifier`. An `AdaptiveRetryStrategy` reads per-type policies from config to decide retry count and exponential backoff+jitter delay. `FallbackMiddleware` retries the same operation against alternative configured providers. `DiscordAlertMiddleware` sends a webhook notification on fallback activation or total exhaustion.

**Tech Stack:** PHP 8.2, Laravel (illuminate/support, illuminate/http), Guzzle (via existing `AbstractProvider`), PHPUnit + Orchestra Testbench (existing test setup).

---

## Spec Reference

Full design: `docs/superpowers/specs/2026-08-08-error-recovery-design.md`

---

## File Structure

```
src/ErrorRecovery/
├── Enums/
│   └── ErrorType.php                    # NEW
├── Contracts/
│   ├── Middleware.php                   # NEW
│   ├── ErrorClassifier.php              # NEW
│   ├── RetryStrategy.php                # NEW
│   └── AlertNotifier.php                # NEW
├── Classifiers/
│   └── DefaultErrorClassifier.php       # NEW
├── Strategies/
│   └── AdaptiveRetryStrategy.php        # NEW
├── Notifiers/
│   ├── DiscordNotifier.php              # NEW
│   └── NullNotifier.php                 # NEW
├── Middleware/
│   ├── RetryMiddleware.php              # NEW
│   ├── FallbackMiddleware.php           # NEW
│   └── DiscordAlertMiddleware.php       # NEW
└── Pipeline.php                          # NEW

src/DTOs/
└── RecoveryContext.php                  # NEW - carries provider name + attempt metadata through the pipeline

config/agent-kit.php                     # MODIFY - add 'error_recovery' section
src/AgentKitServiceProvider.php          # MODIFY - bind AlertNotifier, wire Pipeline into Agent
src/Agent.php                            # MODIFY - resolveProvider() call wrapped by Pipeline
src/Exceptions/AllProvidersFailedException.php  # NEW

tests/Unit/ErrorRecovery/
├── DefaultErrorClassifierTest.php       # NEW
├── AdaptiveRetryStrategyTest.php        # NEW
└── RetryMiddlewareTest.php              # NEW

tests/Feature/ErrorRecovery/
├── FallbackMiddlewareTest.php           # NEW
├── DiscordAlertMiddlewareTest.php       # NEW
└── ErrorRecoveryIntegrationTest.php     # NEW
```

**Responsibilities:**
- `ErrorType`: pure enum, no logic.
- `DefaultErrorClassifier`: inspects a `Throwable` (specifically Guzzle exceptions wrapped in `ProviderException`) and returns an `ErrorType`.
- `AdaptiveRetryStrategy`: reads `config('agent-kit.error_recovery.retry.policies')`, computes `shouldRetry()` / `delayMs()`.
- `RetryMiddleware`: loops calling the handler, sleeping between attempts, using classifier + strategy.
- `FallbackMiddleware`: on exhausted retry, iterates other configured providers, re-invoking the handler with a mutated `RecoveryContext`.
- `DiscordAlertMiddleware`: outermost middleware; catches fallback/exhaustion signals attached to `RecoveryContext` and notifies.
- `Pipeline`: composes the three middleware in order and exposes `execute(callable $handler, RecoveryContext $context)`.
- `RecoveryContext`: mutable DTO holding `provider` (current provider name), `attempts` (per-provider attempt log), `fallbackUsed` (bool).

---

## Task 1: ErrorType enum and RecoveryContext DTO

**Files:**
- Create: `src/ErrorRecovery/Enums/ErrorType.php`
- Create: `src/DTOs/RecoveryContext.php`
- Test: `tests/Unit/ErrorRecovery/RecoveryContextTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\ErrorRecovery;

use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\Tests\TestCase;

class RecoveryContextTest extends TestCase
{
    public function test_tracks_attempts_per_provider()
    {
        $ctx = new RecoveryContext(provider: 'openai');

        $ctx->recordAttempt('openai');
        $ctx->recordAttempt('openai');

        $this->assertEquals(2, $ctx->attemptsFor('openai'));
        $this->assertEquals(0, $ctx->attemptsFor('anthropic'));
    }

    public function test_switch_provider_resets_fallback_flag_tracking()
    {
        $ctx = new RecoveryContext(provider: 'openai');
        $this->assertFalse($ctx->fallbackUsed);

        $ctx->switchProvider('anthropic');

        $this->assertEquals('anthropic', $ctx->provider);
        $this->assertTrue($ctx->fallbackUsed);
    }

    public function test_attempt_log_summary()
    {
        $ctx = new RecoveryContext(provider: 'openai');
        $ctx->recordAttempt('openai');
        $ctx->recordFailure('openai', 'NETWORK_TIMEOUT');
        $ctx->switchProvider('anthropic');
        $ctx->recordAttempt('anthropic');
        $ctx->recordFailure('anthropic', 'AUTH_ERROR');

        $summary = $ctx->summary();

        $this->assertEquals([
            'openai' => ['attempts' => 1, 'last_error' => 'NETWORK_TIMEOUT'],
            'anthropic' => ['attempts' => 1, 'last_error' => 'AUTH_ERROR'],
        ], $summary);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ErrorRecovery/RecoveryContextTest.php`
Expected: FAIL with "Class RecoveryContext not found"

- [ ] **Step 3: Write the ErrorType enum**

```php
<?php

namespace Peralta\AgentKit\ErrorRecovery\Enums;

enum ErrorType: string
{
    case NETWORK_TIMEOUT = 'NETWORK_TIMEOUT';
    case RATE_LIMIT = 'RATE_LIMIT';
    case INVALID_REQUEST = 'INVALID_REQUEST';
    case AUTH_ERROR = 'AUTH_ERROR';
    case SERVER_ERROR = 'SERVER_ERROR';
}
```

- [ ] **Step 4: Write RecoveryContext**

```php
<?php

namespace Peralta\AgentKit\DTOs;

class RecoveryContext
{
    /** @var array<string, int> */
    protected array $attempts = [];

    /** @var array<string, string> */
    protected array $lastErrors = [];

    public bool $fallbackUsed = false;

    public function __construct(
        public string $provider,
    ) {}

    public function recordAttempt(string $provider): void
    {
        $this->attempts[$provider] = ($this->attempts[$provider] ?? 0) + 1;
    }

    public function recordFailure(string $provider, string $errorType): void
    {
        $this->lastErrors[$provider] = $errorType;
    }

    public function attemptsFor(string $provider): int
    {
        return $this->attempts[$provider] ?? 0;
    }

    public function switchProvider(string $provider): void
    {
        $this->provider = $provider;
        $this->fallbackUsed = true;
    }

    /**
     * @return array<string, array{attempts: int, last_error: string|null}>
     */
    public function summary(): array
    {
        $providers = array_unique(array_merge(
            array_keys($this->attempts),
            array_keys($this->lastErrors),
        ));

        $summary = [];
        foreach ($providers as $provider) {
            $summary[$provider] = [
                'attempts' => $this->attempts[$provider] ?? 0,
                'last_error' => $this->lastErrors[$provider] ?? null,
            ];
        }

        return $summary;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ErrorRecovery/RecoveryContextTest.php`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add src/ErrorRecovery/Enums/ErrorType.php src/DTOs/RecoveryContext.php tests/Unit/ErrorRecovery/RecoveryContextTest.php
git commit -m "feat: add ErrorType enum and RecoveryContext DTO"
```

---

## Task 2: ErrorClassifier contract + DefaultErrorClassifier

**Files:**
- Create: `src/ErrorRecovery/Contracts/ErrorClassifier.php`
- Create: `src/ErrorRecovery/Classifiers/DefaultErrorClassifier.php`
- Test: `tests/Unit/ErrorRecovery/DefaultErrorClassifierTest.php`

The classifier inspects `ProviderException` (thrown by `AbstractProvider::request()`, see `src/Providers/AbstractProvider.php:40`). That exception wraps a Guzzle `GuzzleException`; when the underlying exception has a `getResponse()` (i.e. it's a `RequestException` with a response), we read the HTTP status code. When there's no response (connect timeout, DNS failure), we treat it as `NETWORK_TIMEOUT`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\ErrorRecovery;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Peralta\AgentKit\ErrorRecovery\Classifiers\DefaultErrorClassifier;
use Peralta\AgentKit\ErrorRecovery\Enums\ErrorType;
use Peralta\AgentKit\Exceptions\ProviderException;
use Peralta\AgentKit\Tests\TestCase;

class DefaultErrorClassifierTest extends TestCase
{
    private DefaultErrorClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new DefaultErrorClassifier();
    }

    public function test_connect_exception_is_network_timeout()
    {
        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions');
        $previous = new ConnectException('Connection timed out', $request);
        $error = new ProviderException('Erro chamando openai: Connection timed out', 0, $previous);

        $this->assertEquals(ErrorType::NETWORK_TIMEOUT, $this->classifier->classify($error));
    }

    public function test_429_is_rate_limit()
    {
        $error = $this->providerExceptionWithStatus(429);
        $this->assertEquals(ErrorType::RATE_LIMIT, $this->classifier->classify($error));
    }

    public function test_400_is_invalid_request()
    {
        $error = $this->providerExceptionWithStatus(400);
        $this->assertEquals(ErrorType::INVALID_REQUEST, $this->classifier->classify($error));
    }

    public function test_401_is_auth_error()
    {
        $error = $this->providerExceptionWithStatus(401);
        $this->assertEquals(ErrorType::AUTH_ERROR, $this->classifier->classify($error));
    }

    public function test_403_is_auth_error()
    {
        $error = $this->providerExceptionWithStatus(403);
        $this->assertEquals(ErrorType::AUTH_ERROR, $this->classifier->classify($error));
    }

    public function test_500_is_server_error()
    {
        $error = $this->providerExceptionWithStatus(500);
        $this->assertEquals(ErrorType::SERVER_ERROR, $this->classifier->classify($error));
    }

    public function test_unknown_throwable_defaults_to_server_error()
    {
        $error = new \RuntimeException('something weird');
        $this->assertEquals(ErrorType::SERVER_ERROR, $this->classifier->classify($error));
    }

    private function providerExceptionWithStatus(int $status): ProviderException
    {
        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions');
        $response = new Response($status, [], '{"error":"boom"}');
        $previous = new RequestException('HTTP error', $request, $response);

        return new ProviderException("Erro chamando openai: HTTP error", 0, $previous);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ErrorRecovery/DefaultErrorClassifierTest.php`
Expected: FAIL with "Class DefaultErrorClassifier not found"

- [ ] **Step 3: Write the ErrorClassifier contract**

```php
<?php

namespace Peralta\AgentKit\ErrorRecovery\Contracts;

use Peralta\AgentKit\ErrorRecovery\Enums\ErrorType;
use Throwable;

interface ErrorClassifier
{
    public function classify(Throwable $error): ErrorType;
}
```

- [ ] **Step 4: Write DefaultErrorClassifier**

```php
<?php

namespace Peralta\AgentKit\ErrorRecovery\Classifiers;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Peralta\AgentKit\ErrorRecovery\Contracts\ErrorClassifier;
use Peralta\AgentKit\ErrorRecovery\Enums\ErrorType;
use Throwable;

class DefaultErrorClassifier implements ErrorClassifier
{
    public function classify(Throwable $error): ErrorType
    {
        $previous = $error->getPrevious();

        if ($previous instanceof ConnectException) {
            return ErrorType::NETWORK_TIMEOUT;
        }

        if ($previous instanceof RequestException && $previous->hasResponse()) {
            return $this->classifyStatusCode($previous->getResponse()->getStatusCode());
        }

        if ($previous instanceof RequestException) {
            // Sem response = timeout de conexão ou erro de rede
            return ErrorType::NETWORK_TIMEOUT;
        }

        return ErrorType::SERVER_ERROR;
    }

    private function classifyStatusCode(int $status): ErrorType
    {
        return match (true) {
            $status === 429 => ErrorType::RATE_LIMIT,
            $status === 400 => ErrorType::INVALID_REQUEST,
            $status === 401, $status === 403 => ErrorType::AUTH_ERROR,
            $status >= 500 => ErrorType::SERVER_ERROR,
            default => ErrorType::SERVER_ERROR,
        };
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ErrorRecovery/DefaultErrorClassifierTest.php`
Expected: PASS (7 tests)

- [ ] **Step 6: Commit**

```bash
git add src/ErrorRecovery/Contracts/ErrorClassifier.php src/ErrorRecovery/Classifiers/DefaultErrorClassifier.php tests/Unit/ErrorRecovery/DefaultErrorClassifierTest.php
git commit -m "feat: add ErrorClassifier contract and DefaultErrorClassifier"
```

---

## Task 3: RetryStrategy contract + AdaptiveRetryStrategy

**Files:**
- Create: `src/ErrorRecovery/Contracts/RetryStrategy.php`
- Create: `src/ErrorRecovery/Strategies/AdaptiveRetryStrategy.php`
- Test: `tests/Unit/ErrorRecovery/AdaptiveRetryStrategyTest.php`

Config shape consumed (see spec section 6.1):

```php
'policies' => [
    'NETWORK_TIMEOUT' => ['max_attempts' => 7, 'initial_delay_ms' => 1000, 'max_delay_ms' => 30000, 'multiplier' => 2.0, 'jitter' => true],
    ...
]
```

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\ErrorRecovery;

use Peralta\AgentKit\ErrorRecovery\Enums\ErrorType;
use Peralta\AgentKit\ErrorRecovery\Strategies\AdaptiveRetryStrategy;
use Peralta\AgentKit\Tests\TestCase;

class AdaptiveRetryStrategyTest extends TestCase
{
    private function policies(): array
    {
        return [
            'NETWORK_TIMEOUT' => [
                'max_attempts' => 7,
                'initial_delay_ms' => 1000,
                'max_delay_ms' => 30000,
                'multiplier' => 2.0,
                'jitter' => false,
            ],
            'AUTH_ERROR' => [
                'max_attempts' => 0,
            ],
        ];
    }

    public function test_should_retry_while_under_max_attempts()
    {
        $strategy = new AdaptiveRetryStrategy($this->policies());

        $this->assertTrue($strategy->shouldRetry(ErrorType::NETWORK_TIMEOUT, 1));
        $this->assertTrue($strategy->shouldRetry(ErrorType::NETWORK_TIMEOUT, 6));
        $this->assertFalse($strategy->shouldRetry(ErrorType::NETWORK_TIMEOUT, 7));
    }

    public function test_zero_max_attempts_never_retries()
    {
        $strategy = new AdaptiveRetryStrategy($this->policies());

        $this->assertFalse($strategy->shouldRetry(ErrorType::AUTH_ERROR, 1));
    }

    public function test_unknown_error_type_defaults_to_no_retry()
    {
        $strategy = new AdaptiveRetryStrategy([]);

        $this->assertFalse($strategy->shouldRetry(ErrorType::INVALID_REQUEST, 1));
    }

    public function test_delay_grows_exponentially_without_jitter()
    {
        $strategy = new AdaptiveRetryStrategy($this->policies());

        $this->assertEquals(1000, $strategy->delayMs(ErrorType::NETWORK_TIMEOUT, 1));
        $this->assertEquals(2000, $strategy->delayMs(ErrorType::NETWORK_TIMEOUT, 2));
        $this->assertEquals(4000, $strategy->delayMs(ErrorType::NETWORK_TIMEOUT, 3));
    }

    public function test_delay_is_capped_at_max_delay_ms()
    {
        $strategy = new AdaptiveRetryStrategy($this->policies());

        // 1000 * 2^9 = 512000, way above max_delay_ms of 30000
        $this->assertEquals(30000, $strategy->delayMs(ErrorType::NETWORK_TIMEOUT, 10));
    }

    public function test_jitter_adds_randomness_within_bounds()
    {
        $policies = $this->policies();
        $policies['NETWORK_TIMEOUT']['jitter'] = true;
        $strategy = new AdaptiveRetryStrategy($policies);

        $delay = $strategy->delayMs(ErrorType::NETWORK_TIMEOUT, 1);

        // base delay is 1000ms; jitter adds between 0 and base delay (see impl)
        $this->assertGreaterThanOrEqual(1000, $delay);
        $this->assertLessThanOrEqual(2000, $delay);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ErrorRecovery/AdaptiveRetryStrategyTest.php`
Expected: FAIL with "Class AdaptiveRetryStrategy not found"

- [ ] **Step 3: Write the RetryStrategy contract**

```php
<?php

namespace Peralta\AgentKit\ErrorRecovery\Contracts;

use Peralta\AgentKit\ErrorRecovery\Enums\ErrorType;

interface RetryStrategy
{
    public function shouldRetry(ErrorType $type, int $attempt): bool;

    public function delayMs(ErrorType $type, int $attempt): int;
}
```

- [ ] **Step 4: Write AdaptiveRetryStrategy**

```php
<?php

namespace Peralta\AgentKit\ErrorRecovery\Strategies;

use Peralta\AgentKit\ErrorRecovery\Contracts\RetryStrategy;
use Peralta\AgentKit\ErrorRecovery\Enums\ErrorType;

class AdaptiveRetryStrategy implements RetryStrategy
{
    /**
     * @param  array<string, array{max_attempts?: int, initial_delay_ms?: int, max_delay_ms?: int, multiplier?: float, jitter?: bool}>  $policies
     */
    public function __construct(
        protected array $policies = [],
    ) {}

    public function shouldRetry(ErrorType $type, int $attempt): bool
    {
        $maxAttempts = $this->policyFor($type)['max_attempts'] ?? 0;

        return $attempt < $maxAttempts;
    }

    public function delayMs(ErrorType $type, int $attempt): int
    {
        $policy = $this->policyFor($type);

        $initial = $policy['initial_delay_ms'] ?? 1000;
        $max = $policy['max_delay_ms'] ?? 30000;
        $multiplier = $policy['multiplier'] ?? 2.0;
        $jitter = $policy['jitter'] ?? false;

        $delay = (int) min($initial * ($multiplier ** ($attempt - 1)), $max);

        if ($jitter) {
            $delay += random_int(0, $delay);
        }

        return $delay;
    }

    /**
     * @return array{max_attempts?: int, initial_delay_ms?: int, max_delay_ms?: int, multiplier?: float, jitter?: bool}
     */
    protected function policyFor(ErrorType $type): array
    {
        return $this->policies[$type->value] ?? [];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ErrorRecovery/AdaptiveRetryStrategyTest.php`
Expected: PASS (6 tests)

- [ ] **Step 6: Commit**

```bash
git add src/ErrorRecovery/Contracts/RetryStrategy.php src/ErrorRecovery/Strategies/AdaptiveRetryStrategy.php tests/Unit/ErrorRecovery/AdaptiveRetryStrategyTest.php
git commit -m "feat: add RetryStrategy contract and AdaptiveRetryStrategy"
```

---

## Task 4: Middleware contract + RetryMiddleware

**Files:**
- Create: `src/ErrorRecovery/Contracts/Middleware.php`
- Create: `src/ErrorRecovery/Middleware/RetryMiddleware.php`
- Test: `tests/Unit/ErrorRecovery/RetryMiddlewareTest.php`

`RetryMiddleware::handle()` sleeps for real between attempts using `usleep()`. To keep tests fast, the test uses a policy with `initial_delay_ms => 1` so total sleep time stays under a few milliseconds.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\ErrorRecovery;

use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\ErrorRecovery\Classifiers\DefaultErrorClassifier;
use Peralta\AgentKit\ErrorRecovery\Middleware\RetryMiddleware;
use Peralta\AgentKit\ErrorRecovery\Strategies\AdaptiveRetryStrategy;
use Peralta\AgentKit\Exceptions\ProviderException;
use Peralta\AgentKit\Tests\TestCase;

class RetryMiddlewareTest extends TestCase
{
    private function fastPolicies(int $maxAttempts): array
    {
        return [
            'NETWORK_TIMEOUT' => [
                'max_attempts' => $maxAttempts,
                'initial_delay_ms' => 1,
                'max_delay_ms' => 5,
                'multiplier' => 1.0,
                'jitter' => false,
            ],
            'INVALID_REQUEST' => ['max_attempts' => 0],
        ];
    }

    public function test_retries_until_success()
    {
        $attempts = 0;
        $handler = function (RecoveryContext $ctx) use (&$attempts) {
            $attempts++;
            $ctx->recordAttempt($ctx->provider);
            if ($attempts < 3) {
                throw $this->networkTimeoutException();
            }
            return 'success';
        };

        $middleware = new RetryMiddleware(new AdaptiveRetryStrategy($this->fastPolicies(7)), new DefaultErrorClassifier());

        $result = $middleware->handle($handler, new RecoveryContext(provider: 'openai'));

        $this->assertEquals('success', $result);
        $this->assertEquals(3, $attempts);
    }

    public function test_stops_after_max_attempts_and_rethrows()
    {
        $attempts = 0;
        $handler = function (RecoveryContext $ctx) use (&$attempts) {
            $attempts++;
            throw $this->networkTimeoutException();
        };

        $middleware = new RetryMiddleware(new AdaptiveRetryStrategy($this->fastPolicies(3)), new DefaultErrorClassifier());

        $this->expectException(ProviderException::class);

        try {
            $middleware->handle($handler, new RecoveryContext(provider: 'openai'));
        } finally {
            $this->assertEquals(3, $attempts);
        }
    }

    public function test_does_not_retry_non_retryable_error()
    {
        $attempts = 0;
        $handler = function (RecoveryContext $ctx) use (&$attempts) {
            $attempts++;
            throw $this->invalidRequestException();
        };

        $middleware = new RetryMiddleware(new AdaptiveRetryStrategy($this->fastPolicies(7)), new DefaultErrorClassifier());

        $this->expectException(ProviderException::class);

        try {
            $middleware->handle($handler, new RecoveryContext(provider: 'openai'));
        } finally {
            $this->assertEquals(1, $attempts);
        }
    }

    private function networkTimeoutException(): ProviderException
    {
        $request = new \GuzzleHttp\Psr7\Request('POST', 'https://api.openai.com/v1/chat/completions');
        $previous = new \GuzzleHttp\Exception\ConnectException('timeout', $request);
        return new ProviderException('Erro chamando openai: timeout', 0, $previous);
    }

    private function invalidRequestException(): ProviderException
    {
        $request = new \GuzzleHttp\Psr7\Request('POST', 'https://api.openai.com/v1/chat/completions');
        $response = new \GuzzleHttp\Psr7\Response(400, [], '{"error":"bad"}');
        $previous = new \GuzzleHttp\Exception\RequestException('bad request', $request, $response);
        return new ProviderException('Erro chamando openai: bad request', 0, $previous);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ErrorRecovery/RetryMiddlewareTest.php`
Expected: FAIL with "Class RetryMiddleware not found"

- [ ] **Step 3: Write the Middleware contract**

```php
<?php

namespace Peralta\AgentKit\ErrorRecovery\Contracts;

use Peralta\AgentKit\DTOs\RecoveryContext;

interface Middleware
{
    /**
     * @param  callable(RecoveryContext): mixed  $handler
     */
    public function handle(callable $handler, RecoveryContext $context): mixed;
}
```

- [ ] **Step 4: Write RetryMiddleware**

```php
<?php

namespace Peralta\AgentKit\ErrorRecovery\Middleware;

use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\ErrorRecovery\Contracts\ErrorClassifier;
use Peralta\AgentKit\ErrorRecovery\Contracts\Middleware;
use Peralta\AgentKit\ErrorRecovery\Contracts\RetryStrategy;
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

                if (!$this->strategy->shouldRetry($errorType, $attempt)) {
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
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add src/ErrorRecovery/Contracts/Middleware.php src/ErrorRecovery/Middleware/RetryMiddleware.php tests/Unit/ErrorRecovery/RetryMiddlewareTest.php
git commit -m "feat: add Middleware contract and RetryMiddleware"
```

---

## Task 5: AllProvidersFailedException + FallbackMiddleware

**Files:**
- Create: `src/Exceptions/AllProvidersFailedException.php`
- Create: `src/ErrorRecovery/Middleware/FallbackMiddleware.php`
- Test: `tests/Feature/ErrorRecovery/FallbackMiddlewareTest.php`

`FallbackMiddleware` wraps `$handler` execution. On failure it checks (a) whether the classified error type is in `on_errors` config and not in `skip_on_errors`, and (b) whether other providers remain untried. It mutates `context->provider` via `switchProvider()` and re-invokes `$handler`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Peralta\AgentKit\Tests\Feature\ErrorRecovery;

use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\ErrorRecovery\Classifiers\DefaultErrorClassifier;
use Peralta\AgentKit\ErrorRecovery\Middleware\FallbackMiddleware;
use Peralta\AgentKit\Exceptions\AllProvidersFailedException;
use Peralta\AgentKit\Exceptions\ProviderException;
use Peralta\AgentKit\Tests\TestCase;

class FallbackMiddlewareTest extends TestCase
{
    private function config(): array
    {
        return [
            'on_errors' => ['NETWORK_TIMEOUT', 'SERVER_ERROR', 'RATE_LIMIT', 'AUTH_ERROR'],
            'skip_on_errors' => ['INVALID_REQUEST'],
        ];
    }

    public function test_falls_back_to_next_available_provider()
    {
        $calls = [];
        $handler = function (RecoveryContext $ctx) use (&$calls) {
            $calls[] = $ctx->provider;
            if ($ctx->provider === 'openai') {
                throw $this->networkTimeoutException();
            }
            return "success from {$ctx->provider}";
        };

        $middleware = new FallbackMiddleware(
            new DefaultErrorClassifier(),
            ['openai', 'anthropic'],
            $this->config(),
        );

        $result = $middleware->handle($handler, new RecoveryContext(provider: 'openai'));

        $this->assertEquals('success from anthropic', $result);
        $this->assertEquals(['openai', 'anthropic'], $calls);
    }

    public function test_throws_all_providers_failed_when_every_provider_fails()
    {
        $handler = function (RecoveryContext $ctx) {
            throw $this->networkTimeoutException();
        };

        $middleware = new FallbackMiddleware(
            new DefaultErrorClassifier(),
            ['openai', 'anthropic'],
            $this->config(),
        );

        $this->expectException(AllProvidersFailedException::class);

        $middleware->handle($handler, new RecoveryContext(provider: 'openai'));
    }

    public function test_skips_fallback_for_invalid_request_errors()
    {
        $calls = [];
        $handler = function (RecoveryContext $ctx) use (&$calls) {
            $calls[] = $ctx->provider;
            throw $this->invalidRequestException();
        };

        $middleware = new FallbackMiddleware(
            new DefaultErrorClassifier(),
            ['openai', 'anthropic'],
            $this->config(),
        );

        $this->expectException(ProviderException::class);

        try {
            $middleware->handle($handler, new RecoveryContext(provider: 'openai'));
        } finally {
            $this->assertEquals(['openai'], $calls);
        }
    }

    private function networkTimeoutException(): ProviderException
    {
        $request = new \GuzzleHttp\Psr7\Request('POST', 'https://api.openai.com/v1/chat/completions');
        $previous = new \GuzzleHttp\Exception\ConnectException('timeout', $request);
        return new ProviderException('Erro chamando openai: timeout', 0, $previous);
    }

    private function invalidRequestException(): ProviderException
    {
        $request = new \GuzzleHttp\Psr7\Request('POST', 'https://api.openai.com/v1/chat/completions');
        $response = new \GuzzleHttp\Psr7\Response(400, [], '{"error":"bad"}');
        $previous = new \GuzzleHttp\Exception\RequestException('bad request', $request, $response);
        return new ProviderException('Erro chamando openai: bad request', 0, $previous);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ErrorRecovery/FallbackMiddlewareTest.php`
Expected: FAIL with "Class FallbackMiddleware not found"

- [ ] **Step 3: Write AllProvidersFailedException**

```php
<?php

namespace Peralta\AgentKit\Exceptions;

class AllProvidersFailedException extends AgentException
{
    public function __construct(
        string $message,
        public readonly array $providerSummary = [],
    ) {
        parent::__construct($message);
    }
}
```

- [ ] **Step 4: Write FallbackMiddleware**

```php
<?php

namespace Peralta\AgentKit\ErrorRecovery\Middleware;

use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\ErrorRecovery\Contracts\ErrorClassifier;
use Peralta\AgentKit\ErrorRecovery\Contracts\Middleware;
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

        try {
            return $handler($context);
        } catch (Throwable $e) {
            $errorType = $this->classifier->classify($e);

            if (!$this->isFallbackEligible($errorType->value)) {
                throw $e;
            }

            foreach ($this->remainingProviders($tried) as $provider) {
                $tried[] = $provider;
                $context->switchProvider($provider);

                try {
                    return $handler($context);
                } catch (Throwable $inner) {
                    $innerType = $this->classifier->classify($inner);
                    $context->recordFailure($provider, $innerType->value);
                    continue;
                }
            }

            throw new AllProvidersFailedException(
                "Todos os providers falharam: " . implode(', ', $tried),
                $context->summary(),
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

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ErrorRecovery/FallbackMiddlewareTest.php`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add src/Exceptions/AllProvidersFailedException.php src/ErrorRecovery/Middleware/FallbackMiddleware.php tests/Feature/ErrorRecovery/FallbackMiddlewareTest.php
git commit -m "feat: add FallbackMiddleware and AllProvidersFailedException"
```

---

## Task 6: AlertNotifier contract + DiscordNotifier + NullNotifier

**Files:**
- Create: `src/ErrorRecovery/Contracts/AlertNotifier.php`
- Create: `src/ErrorRecovery/Notifiers/DiscordNotifier.php`
- Create: `src/ErrorRecovery/Notifiers/NullNotifier.php`
- Test: `tests/Unit/ErrorRecovery/DiscordNotifierTest.php`

`DiscordNotifier` uses Laravel's `Http` facade (already a dependency via `illuminate/http`) to POST to the configured webhook URL. Tests use `Http::fake()`.

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ErrorRecovery/DiscordNotifierTest.php`
Expected: FAIL with "Class DiscordNotifier not found"

- [ ] **Step 3: Write the AlertNotifier contract**

```php
<?php

namespace Peralta\AgentKit\ErrorRecovery\Contracts;

interface AlertNotifier
{
    public function notifyFailure(string $message, array $context): void;

    public function notifyFallback(string $provider, string $reason): void;
}
```

- [ ] **Step 4: Write DiscordNotifier**

```php
<?php

namespace Peralta\AgentKit\ErrorRecovery\Notifiers;

use Illuminate\Support\Facades\Http;
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

        Http::post($this->webhookUrl, ['content' => $content]);
    }
}
```

- [ ] **Step 5: Write NullNotifier**

```php
<?php

namespace Peralta\AgentKit\ErrorRecovery\Notifiers;

use Peralta\AgentKit\ErrorRecovery\Contracts\AlertNotifier;

class NullNotifier implements AlertNotifier
{
    public function notifyFailure(string $message, array $context): void
    {
        // no-op
    }

    public function notifyFallback(string $provider, string $reason): void
    {
        // no-op
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ErrorRecovery/DiscordNotifierTest.php`
Expected: PASS (4 tests)

- [ ] **Step 7: Commit**

```bash
git add src/ErrorRecovery/Contracts/AlertNotifier.php src/ErrorRecovery/Notifiers/DiscordNotifier.php src/ErrorRecovery/Notifiers/NullNotifier.php tests/Unit/ErrorRecovery/DiscordNotifierTest.php
git commit -m "feat: add AlertNotifier contract, DiscordNotifier and NullNotifier"
```

---

## Task 7: DiscordAlertMiddleware

**Files:**
- Create: `src/ErrorRecovery/Middleware/DiscordAlertMiddleware.php`
- Test: `tests/Feature/ErrorRecovery/DiscordAlertMiddlewareTest.php`

This middleware sits outermost. It calls `$handler($context)`. On success, if `$context->fallbackUsed` is true, it fires `notifyFallback()` (since `FallbackMiddleware`, which runs *inside* this middleware, already switched provider transparently before returning). On `AllProvidersFailedException`, it fires `notifyFailure()` before re-throwing.

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ErrorRecovery/DiscordAlertMiddlewareTest.php`
Expected: FAIL with "Class DiscordAlertMiddleware not found"

- [ ] **Step 3: Write DiscordAlertMiddleware**

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ErrorRecovery/DiscordAlertMiddlewareTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/ErrorRecovery/Middleware/DiscordAlertMiddleware.php tests/Feature/ErrorRecovery/DiscordAlertMiddlewareTest.php
git commit -m "feat: add DiscordAlertMiddleware"
```

---

## Task 8: Pipeline

**Files:**
- Create: `src/ErrorRecovery/Pipeline.php`
- Test: `tests/Unit/ErrorRecovery/PipelineTest.php`

`Pipeline` composes middleware in a fixed order: `RetryMiddleware` is innermost (closest to the real handler), `FallbackMiddleware` wraps it, `DiscordAlertMiddleware` is outermost. This matches the spec's flow diagram (Section 5): retries happen per-provider first, then fallback switches provider, then alerts observe the final outcome.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\ErrorRecovery;

use Mockery;
use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\ErrorRecovery\Contracts\Middleware;
use Peralta\AgentKit\ErrorRecovery\Pipeline;
use Peralta\AgentKit\Tests\TestCase;

class PipelineTest extends TestCase
{
    public function test_executes_middleware_in_order_outermost_first()
    {
        $calls = [];

        $outer = Mockery::mock(Middleware::class);
        $outer->shouldReceive('handle')->once()->andReturnUsing(function ($handler, $ctx) use (&$calls) {
            $calls[] = 'outer:before';
            $result = $handler($ctx);
            $calls[] = 'outer:after';
            return $result;
        });

        $inner = Mockery::mock(Middleware::class);
        $inner->shouldReceive('handle')->once()->andReturnUsing(function ($handler, $ctx) use (&$calls) {
            $calls[] = 'inner:before';
            $result = $handler($ctx);
            $calls[] = 'inner:after';
            return $result;
        });

        $pipeline = new Pipeline([$outer, $inner]);

        $result = $pipeline->execute(function (RecoveryContext $ctx) use (&$calls) {
            $calls[] = 'handler';
            return 'done';
        }, new RecoveryContext(provider: 'openai'));

        $this->assertEquals('done', $result);
        $this->assertEquals(
            ['outer:before', 'inner:before', 'handler', 'inner:after', 'outer:after'],
            $calls,
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ErrorRecovery/PipelineTest.php`
Expected: FAIL with "Class Pipeline not found"

- [ ] **Step 3: Write Pipeline**

```php
<?php

namespace Peralta\AgentKit\ErrorRecovery;

use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\ErrorRecovery\Contracts\Middleware;

class Pipeline
{
    /**
     * @param  Middleware[]  $middleware  Ordenados do mais externo pro mais interno.
     */
    public function __construct(
        protected array $middleware,
    ) {}

    public function execute(callable $handler, RecoveryContext $context): mixed
    {
        $chain = array_reduce(
            array_reverse($this->middleware),
            fn(callable $next, Middleware $mw) => fn(RecoveryContext $ctx) => $mw->handle($next, $ctx),
            $handler,
        );

        return $chain($context);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ErrorRecovery/PipelineTest.php`
Expected: PASS (1 test)

- [ ] **Step 5: Commit**

```bash
git add src/ErrorRecovery/Pipeline.php tests/Unit/ErrorRecovery/PipelineTest.php
git commit -m "feat: add error recovery Pipeline"
```

---

## Task 9: Config section

**Files:**
- Modify: `config/agent-kit.php`

- [ ] **Step 1: Add the `error_recovery` section**

Insert this new top-level key into `config/agent-kit.php`, right before the closing `'logging'` section (after line 191, `],` that closes `'knowledge'`):

```php
    /*
    |--------------------------------------------------------------------------
    | Error Recovery
    |--------------------------------------------------------------------------
    | Retry automático, fallback entre providers e alertas via Discord.
    */
    'error_recovery' => [
        'enabled' => env('AGENT_ERROR_RECOVERY_ENABLED', true),

        'retry' => [
            'enabled' => true,

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
                    'max_attempts' => 0,
                ],
                'INVALID_REQUEST' => [
                    'max_attempts' => 0,
                ],
            ],
        ],

        'fallback' => [
            'enabled' => true,

            'on_errors' => [
                'NETWORK_TIMEOUT',
                'RATE_LIMIT',
                'SERVER_ERROR',
                'AUTH_ERROR',
            ],

            'skip_on_errors' => [
                'INVALID_REQUEST',
            ],
        ],

        'alerts' => [
            'enabled' => env('AGENT_DISCORD_ALERTS_ENABLED', false),

            'discord' => [
                'webhook_url' => env('AGENT_DISCORD_WEBHOOK_URL'),
                'mention_on_critical' => env('AGENT_DISCORD_MENTION'),
            ],
        ],
    ],

```

- [ ] **Step 2: Verify config loads without errors**

Run: `php -r "var_dump(require 'config/agent-kit.php');" | grep -A2 error_recovery`
Expected: no PHP parse errors; output includes the `error_recovery` array.

- [ ] **Step 3: Commit**

```bash
git add config/agent-kit.php
git commit -m "feat: add error_recovery config section"
```

---

## Task 10: Wire Pipeline into AgentKitServiceProvider and Agent

**Files:**
- Modify: `src/AgentKitServiceProvider.php`
- Modify: `src/Agent.php`
- Test: `tests/Feature/ErrorRecovery/ErrorRecoveryIntegrationTest.php`

This is the integration task. `Agent::send()` currently calls `$provider->chat(...)` directly (see `src/Agent.php:130`). We wrap that single call site with the `Pipeline`, injected into `Agent`'s constructor. `resolveProvider()` becomes provider-name-aware so `FallbackMiddleware` can ask the container for a different provider by name mid-flow.

- [ ] **Step 1: Write the failing integration test**

```php
<?php

namespace Peralta\AgentKit\Tests\Feature\ErrorRecovery;

use Illuminate\Support\Facades\Http;
use Peralta\AgentKit\Agent;
use Peralta\AgentKit\ErrorRecovery\Contracts\AlertNotifier;
use Peralta\AgentKit\Exceptions\AllProvidersFailedException;
use Peralta\AgentKit\Tests\TestCase;

class ErrorRecoveryIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'agent-kit.providers.openai.api_key' => 'test-key',
            'agent-kit.providers.anthropic.api_key' => 'test-key',
            'agent-kit.default' => 'openai',
            'agent-kit.error_recovery.retry.policies.NETWORK_TIMEOUT.initial_delay_ms' => 1,
            'agent-kit.error_recovery.retry.policies.NETWORK_TIMEOUT.max_delay_ms' => 2,
            'agent-kit.error_recovery.retry.policies.SERVER_ERROR.initial_delay_ms' => 1,
            'agent-kit.error_recovery.retry.policies.SERVER_ERROR.max_delay_ms' => 2,
        ]);
    }

    public function test_retries_transparently_then_succeeds()
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                return Http::response(['error' => 'boom'], 500);
            }
            return Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'oi'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ], 200);
        });

        $response = Agent::make()->provider('openai')->send('Olá');

        $this->assertEquals('oi', $response->text());
        $this->assertEquals(3, $attempts);
    }

    public function test_falls_back_to_anthropic_after_openai_exhausted()
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => 'boom'], 500),
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'from anthropic']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ], 200),
        ]);

        $response = Agent::make()->provider('openai')->send('Olá');

        $this->assertStringContainsString('anthropic', $response->text());
    }

    public function test_all_providers_failing_throws_and_alerts()
    {
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $notifier = \Mockery::mock(AlertNotifier::class);
        $notifier->shouldReceive('notifyFailure')->once();
        $this->app->instance(AlertNotifier::class, $notifier);

        $this->expectException(AllProvidersFailedException::class);

        Agent::make()->provider('openai')->send('Olá');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ErrorRecovery/ErrorRecoveryIntegrationTest.php`
Expected: FAIL (Agent does not use the Pipeline yet — `Http::fake` is never engaged since providers use raw Guzzle, or the 500 response causes a raw `ProviderException` bubbling up on first attempt)

- [ ] **Step 3: Bind AlertNotifier and Pipeline in AgentKitServiceProvider**

Add these `use` statements at the top of `src/AgentKitServiceProvider.php` (after existing ones):

```php
use Peralta\AgentKit\ErrorRecovery\Classifiers\DefaultErrorClassifier;
use Peralta\AgentKit\ErrorRecovery\Contracts\AlertNotifier;
use Peralta\AgentKit\ErrorRecovery\Middleware\DiscordAlertMiddleware;
use Peralta\AgentKit\ErrorRecovery\Middleware\FallbackMiddleware;
use Peralta\AgentKit\ErrorRecovery\Middleware\RetryMiddleware;
use Peralta\AgentKit\ErrorRecovery\Notifiers\DiscordNotifier;
use Peralta\AgentKit\ErrorRecovery\Notifiers\NullNotifier;
use Peralta\AgentKit\ErrorRecovery\Pipeline;
use Peralta\AgentKit\ErrorRecovery\Strategies\AdaptiveRetryStrategy;
```

Add a `registerErrorRecovery()` method and call it from `register()`:

```php
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/agent-kit.php', 'agent-kit');

        $this->registerProviders();
        $this->registerConversation();
        $this->registerKnowledge();
        $this->registerErrorRecovery();
        $this->registerAgent();
    }
```

```php
    protected function registerErrorRecovery(): void
    {
        $this->app->bind(AlertNotifier::class, function ($app) {
            if (!config('agent-kit.error_recovery.alerts.enabled', false)) {
                return new NullNotifier();
            }

            return new DiscordNotifier(
                webhookUrl: config('agent-kit.error_recovery.alerts.discord.webhook_url', ''),
                mention: config('agent-kit.error_recovery.alerts.discord.mention_on_critical'),
            );
        });

        $this->app->bind(Pipeline::class, function ($app) {
            $classifier = new DefaultErrorClassifier();
            $strategy = new AdaptiveRetryStrategy(
                config('agent-kit.error_recovery.retry.policies', []),
            );

            $providerNames = array_keys(config('agent-kit.providers', []));

            return new Pipeline([
                new DiscordAlertMiddleware($app->make(AlertNotifier::class)),
                new FallbackMiddleware(
                    $classifier,
                    $providerNames,
                    config('agent-kit.error_recovery.fallback', []),
                ),
                new RetryMiddleware($strategy, $classifier),
            ]);
        });
    }
```

- [ ] **Step 4: Pass Pipeline into Agent and wrap the provider call**

Modify `registerAgent()` in `src/AgentKitServiceProvider.php`:

```php
    protected function registerAgent(): void
    {
        $this->app->bind(Agent::class, function ($app) {
            return new Agent(
                container: $app,
                config: config('agent-kit'),
                pipeline: $app->make(Pipeline::class),
            );
        });
    }
```

Modify `src/Agent.php`: add the `use` statement, constructor parameter, and property:

```php
use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\ErrorRecovery\Pipeline;
```

```php
    public function __construct(
        protected Container $container,
        protected array $config,
        protected Pipeline $pipeline,
    ) {
        $this->maxIterations = $config['safety']['max_iterations'] ?? 10;
    }
```

Replace the single provider-call line inside `send()`'s `while (true)` loop (`src/Agent.php:130-135`):

```php
            $response = $provider->chat(
                messages: $messages,
                tools: $tools,
                system: $this->systemPrompt,
                options: $this->resolveOptions(),
            );
```

with:

```php
            $providerName = $this->providerName ?? $this->config['default'];
            $recoveryContext = new RecoveryContext(provider: $providerName);

            $response = $this->pipeline->execute(
                function (RecoveryContext $ctx) use ($messages, $tools) {
                    $provider = $this->container->make("agent-kit.provider.{$ctx->provider}");

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
```

This keeps `$provider` valid for the existing `$this->logUsage($provider, $response);` call further down in the loop. The `$provider = $this->resolveProvider();` line near the top of `send()` (`src/Agent.php:108`) is no longer needed for the chat call itself but is still used to fail fast if the *initial* provider name is invalid — leave it in place before the loop starts.

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ErrorRecovery/ErrorRecoveryIntegrationTest.php`
Expected: PASS (3 tests)

- [ ] **Step 6: Run the full test suite to check for regressions**

Run: `vendor/bin/phpunit`
Expected: PASS, all suites green (existing Knowledge/Qdrant tests + new ErrorRecovery tests)

- [ ] **Step 7: Commit**

```bash
git add src/AgentKitServiceProvider.php src/Agent.php tests/Feature/ErrorRecovery/ErrorRecoveryIntegrationTest.php
git commit -m "feat: wire error recovery pipeline into Agent::send()"
```

---

## Task 11: Documentation

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Add an "Error Recovery" section to README.md**

Insert after the "Trocando de provider" section at the end of `README.md`:

```markdown
## Error Recovery

O Agent Kit tenta recuperar automaticamente de falhas transitórias nos providers:

1. **Retry** com backoff exponencial + jitter (configurável por tipo de erro)
2. **Fallback** para outro provider configurado se o atual esgotar as tentativas
3. **Alerta no Discord** quando um fallback é ativado ou quando todos os providers falham

Configure em `.env`:

```env
AGENT_ERROR_RECOVERY_ENABLED=true
AGENT_DISCORD_ALERTS_ENABLED=true
AGENT_DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/SEU_ID/SEU_TOKEN
AGENT_DISCORD_MENTION=@oncall
```

Políticas de retry por tipo de erro (timeouts, rate limit, erros de servidor, auth,
requisições inválidas) ficam em `config/agent-kit.php` → `error_recovery`. Erros de
`INVALID_REQUEST` (400) nunca são retentados nem geram fallback, pois indicam erro
da própria aplicação.

Todo o processo é transparente: o usuário final recebe a resposta normalmente,
sem saber que houve retry ou troca de provider.
```

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs: document error recovery configuration and behavior"
```

---

## Task 12: Final verification

- [ ] **Step 1: Run the entire test suite**

Run: `vendor/bin/phpunit`
Expected: All tests pass, 0 failures, 0 errors.

- [ ] **Step 2: Sanity-check config publishing still works**

Run: `php -r "require 'vendor/autoload.php'; \$c = require 'config/agent-kit.php'; assert(isset(\$c['error_recovery']['retry']['policies']['NETWORK_TIMEOUT'])); echo 'OK';"`
Expected: prints `OK`

- [ ] **Step 3: Push branch (if working on a feature branch) or confirm main is green**

Run: `git log --oneline -15`
Expected: shows all commits from Tasks 1-11 in order.

