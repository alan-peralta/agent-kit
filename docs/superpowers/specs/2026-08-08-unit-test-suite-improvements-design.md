# Unit Test Suite Improvements - Design Spec

**Date**: 2026-08-08
**Status**: Approved
**Author**: Alan Peralta

---

## 1. Overview

Close the major test coverage gaps in Agent Kit's unit test suite and fix a handful of existing tests that assert nothing meaningful. A prior survey found: **zero coverage** for `src/Providers/*` (all 4 LLM providers), `src/Conversation/*` (all 4 conversation stores + manager), and the core `Agent::send()` loop (only exercised indirectly through analytics-focused tests); minimal-to-no coverage for DTOs, `AbstractTool`, and the `Agent` facade; and 4 tests that reduce to `assertTrue(true)` without verifying real behavior (`PersistMetricsListenerTest`, `DiscordNotifierTest` x3). No code coverage measurement tool is configured at all.

**Goals:**
- Add unit test coverage for the highest-risk untested areas first: `Providers` (external API parsing — silent bugs here corrupt every agent response) and `Conversation` (message persistence — silent bugs here corrupt user data/history).
- Add a dedicated `AgentTest` covering the core `send()` iteration loop and tool-execution error paths that are today only exercised as a side effect of analytics tests.
- Add baseline coverage for DTOs, `AbstractTool`, and the `Agent` facade.
- Fix the 4 existing tests with no real assertions so they actually verify the behavior their names claim to test.
- Configure `pcov` + a `phpunit.xml` coverage block so test coverage becomes measurable going forward.

**Non-goals (out of scope for this iteration):**
- `src/Knowledge/*` gaps (`PgvectorStore`, `KnowledgeIndexer`, the 5 embedders, `KnowledgeSearchTool`) — `QdrantStore` is already thoroughly tested; the rest of Knowledge is deferred to a future pass.
- Enforcing a specific coverage percentage threshold in CI — this iteration only makes coverage measurable, it doesn't gate on it.
- Live integration tests against real provider APIs, real Redis, or real Postgres — everything here is a unit/mocked test, matching the existing `QdrantStoreTest` pattern (`QdrantStoreIntegrationTest` remains the only live-network test, skipped by default).
- Refactoring production code for testability beyond what's already there — `AbstractProvider`'s existing `config['handler']` injection point (already used by `QdrantStore`) is sufficient; no new seams need to be added to `src/Providers/*`.

---

## 2. Architecture: Additive Test Files, One Infra Change

This is a test-only effort — no production code changes except the 4 weak-assertion fixes (which touch only test files) and `phpunit.xml`/`composer.json` (coverage tooling config). The work breaks into 4 sequential blocks by risk:

```
Block 0: Test infrastructure (prerequisite for blocks 2-3)
    ↓
Block 1: Providers (AnthropicProvider, OpenAIProvider, GeminiProvider, DeepSeekProvider)
    ↓
Block 2: Conversation (ArrayStore, ConversationManager, DatabaseStore, RedisStore, HybridStore)
    ↓
Block 3: Agent core + DTOs + AbstractTool + Facade + weak-assertion fixes
```

Blocks 1 depends only on Block 0's coverage tooling (not the DB/Redis infra). Block 2 needs Block 0's sqlite/Redis-mock infra. Block 3 is independent of 0-2 except for reusing existing patterns.

---

## 3. Block 0: Test Infrastructure

**`tests/TestCase.php`** gains:
- `getEnvironmentSetUp($app)` configuring the default database connection as sqlite `:memory:`.
- A migration/schema step for the `agent_messages` table, reusing the package's existing `database/migrations/2026_05_05_000001_create_agent_messages_table.php` (loaded the same single-migration way established in the analytics effort — do NOT load the whole `database/migrations` directory, since it still contains the pgvector-only migration that breaks on sqlite).

**Redis testing approach:** `RedisStore`/`HybridStore` tests mock the `Illuminate\Support\Facades\Redis` facade directly (`Redis::shouldReceive('connection')->andReturnSelf()`, then `shouldReceive('rpush')`/`lrange`/`expire`/`del` as needed) — no real Redis connection required, consistent with how `Log`/`Event` facades are already mocked elsewhere in the suite.

**Coverage tooling:**
- Add `pcov/clobber` or plain `pcov` ext requirement note to `composer.json` require-dev (whichever is installable in this environment — implementer verifies during Block 0).
- Add to `phpunit.xml`:
```xml
<source>
    <include>
        <directory>src</directory>
    </include>
</source>
<coverage>
    <report>
        <text outputFile="php://stdout" showUncoveredFiles="false"/>
    </report>
</coverage>
```
- No coverage percentage threshold is enforced — this only makes `vendor/bin/phpunit --coverage-text` (or equivalent) produce a real report.

---

## 4. Block 1: Providers

For each of `AnthropicProvider`, `OpenAIProvider`, `GeminiProvider` (and a lighter `DeepSeekProvider` test since it's a thin `OpenAIProvider` subclass), create `tests/Unit/Providers/{Name}ProviderTest.php` using the same Guzzle `MockHandler` + request-history pattern already established in `tests/Unit/Knowledge/QdrantStoreTest.php` (a `client(array $responses)` helper builds a `HandlerStack`/`MockHandler`, injected via `$config['handler']`, with a `Middleware::history($history)` to inspect what was actually sent).

**Per-provider test coverage:**
- **Request shape:** correct endpoint URL, correct auth (Anthropic: `x-api-key` + `anthropic-version` headers; OpenAI/DeepSeek: `Authorization: Bearer {key}`; Gemini: `key` query parameter), correct JSON body (messages, formatted tools, `system` prompt, `options` like `model`/`max_tokens` merged in).
- **Response parsing — text-only:** a plain assistant text response maps to a `Message`/`AgentResponse` with the right `stopReason` ('stop'), `inputTokens`/`outputTokens`.
- **Response parsing — tool calls:** a response with one or more tool-use blocks maps to `ToolCall`s with correct `name`/`arguments`/`id`; `stopReason` is `'tool_use'`; `hasToolCalls()` is true. For OpenAI, verify `arguments` (a JSON string in the wire format) is correctly `json_decode`d. For Gemini, verify a synthetic tool-call ID is generated (format `gemini_*`) since Gemini's wire format doesn't provide one.
- **Error paths:** an HTTP error status, a malformed/non-JSON body, and a well-formed-but-missing-expected-field response (e.g. Anthropic response with no `content` key) all throw `ProviderException` with a useful message — do not let a raw Guzzle/JSON exception leak past the provider boundary.
- **Gemini-specific:** a dedicated test for `cleanSchemaForGemini()` (or the equivalent private/protected method, tested indirectly through `chat()`'s outgoing tool payload) confirming `default`, `additionalProperties`, `$schema`, and `examples` keys are stripped from a tool's JSON schema before being sent.
- **DeepSeekProvider:** one test confirming `name()` returns `'deepseek'` and that a `chat()` call produces the same request/response shape as `OpenAIProvider` (proving the subclass doesn't silently diverge) — does not need to repeat every OpenAI-level scenario.

---

## 5. Block 2: Conversation

- **`tests/Unit/Conversation/ArrayStoreTest.php`** — pure in-memory behavior: `load()` of an unknown conversation returns `[]`; `append()` then `load()` round-trips a message; `replace()` overwrites; `clear()` empties; two different `conversationId`s are isolated from each other.
- **`tests/Unit/Conversation/ConversationManagerTest.php`** — constructs with a mocked `ConversationStore` (interface mock, no real store). Verifies `load()`/`clear()` delegate directly to the store. Focuses on `append()`'s truncation logic: appending beyond `maxMessages` results in a `replace()` call with only the most recent `maxMessages` entries; if truncation would leave a leading `role === 'tool'` message (an orphaned tool result with no preceding assistant tool-call), that leading message is also dropped.
- **`tests/Unit/Conversation/DatabaseStoreTest.php`** — using the sqlite in-memory connection from Block 0, exercises real persistence: `append()` then `load()` round-trips a `Message` (including `toolCalls`/`tool_call_id`/`metadata` JSON columns), `replace()` deletes-and-reinserts for a given `conversationId`, `clear()` deletes all rows for that ID only (not other conversations').
- **`tests/Unit/Conversation/RedisStoreTest.php`** — using the mocked `Redis` facade, verifies: `append()` calls `rpush` with the correctly-prefixed key and a JSON-encoded message, plus `expire` with the configured TTL; `load()` calls `lrange` and JSON-decodes each entry back into `Message` objects; `clear()` calls `del`.
- **`tests/Unit/Conversation/HybridStoreTest.php`** — mocked Redis + sqlite DB. Verifies the fallback contract: `load()` reads from Redis first when Redis has data; when Redis is empty, falls back to reading from the DB store; `append()` writes to both Redis and DB (not one or the other).

---

## 6. Block 3: Agent Core, DTOs, AbstractTool, Facade, Weak-Assertion Fixes

**`tests/Unit/AgentTest.php`** — constructs an `Agent` directly (same pattern as `tests/Feature/AgentAnalyticsEventsTest.php`: a mocked `Contracts\Provider` bound into the container, plus a no-op `Pipeline`). Covers:
- Multi-iteration tool-call loop: a provider that returns a tool-call response, then (after the tool result is appended) a final text response — verify the loop runs exactly twice and returns the final `AgentResponse`.
- `MaxIterationsExceededException` thrown when the provider keeps returning tool-call responses past `config['safety']['max_iterations']`.
- `executeTool()`'s three error branches: tool not found in the resolved tool list (returns an `erro` key, no exception), `tool->authorize()` returns false (returns an `erro` key, `handle()` never called), and `tool->handle()` throwing (caught, returns an `erro` key with the exception message).
- Fluent builder methods (`provider()`, `model()`, `system()`, `context()`, `options()`, `conversation()`) each observably affect the provider call (verified via a Mockery expectation on the mocked `Provider`'s `chat()` call args, or via `RecoveryContext`/config inspection where more direct).
- `knowledgeBase()`: when set, `resolveTools()` includes a `KnowledgeSearchTool` instance in the tools passed to the provider.

**`tests/Unit/DTOs/`** — one file per DTO with existing logic worth testing:
- `AgentResponseTest.php` — `hasToolCalls()` true/false branches (based on `stopReason` and whether `message->toolCalls` is empty), `text()`, `toolCalls()`.
- `MessageTest.php` — static constructors `Message::user()`, `Message::assistant()`, `Message::tool()` produce the expected `role`/`content`/`toolCalls`/`toolCallId`.
- `ContextTest.php` — `Context::fromArray()` round-trips known keys correctly.

**`tests/Unit/Tools/AbstractToolTest.php`** — a minimal concrete test-double tool extending `AbstractTool`, verifying the default `authorize()` implementation returns `true` unless overridden.

**`tests/Unit/Facades/AgentFacadeTest.php`** — within a Testbench app context, `Agent::provider(...)` (facade call) resolves to and delegates through the underlying `Agent::class` container binding (mirrors the existing `QdrantStoreBindingTest` pattern of asserting container resolution).

**Weak-assertion fixes** (test-file-only changes, no production code touched):
- `tests/Unit/Listeners/PersistMetricsListenerTest.php` — `test_swallows_exceptions_instead_of_throwing` currently ends with `assertTrue(true)`. Replace with `assertDatabaseCount('agent_kit_metrics', 0)` (or the misconfigured-table's expected zero-row state) to actually prove nothing was silently persisted, alongside confirming no exception propagated.
- `tests/Unit/ErrorRecovery/DiscordNotifierTest.php` — the 3 tests reduced to `assertTrue(true) // não deve lançar exception` get real assertions appropriate to each scenario (e.g., using the same Guzzle `MockHandler`/history pattern as Block 1: assert the HTTP request WAS attempted with the expected payload, or assert it was NOT attempted when notifications are disabled — whichever matches each test's stated intent from its name/docblock). The implementer reads each test's current setup to determine the correct real assertion per case.

---

## 7. Testing the Tests

Every new/modified test file must be run individually during implementation and the full suite (`vendor/bin/phpunit`) run at the end of each block to confirm no regressions. Total test count is expected to grow substantially (survey found 159 existing tests; this effort adds roughly 60-90 new test methods across the 4 blocks, final count TBD by implementation — no fixed target, coverage of the listed scenarios is the actual bar).

---

## 8. Future Enhancements (Out of Scope)

- `src/Knowledge/*` gaps: `PgvectorStore`, `KnowledgeIndexer`, all 5 embedders, `KnowledgeSearchTool`.
- Enforcing a minimum coverage percentage in CI.
- Mutation testing (e.g. Infection) to validate test suite strength beyond line coverage.
- Live integration tests for Providers against real API sandboxes.
