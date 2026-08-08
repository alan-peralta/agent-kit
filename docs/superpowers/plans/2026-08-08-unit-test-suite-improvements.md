# Unit Test Suite Improvements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the major test coverage gaps in Agent Kit — Providers, Conversation, core `Agent::send()` loop, DTOs, `AbstractTool`, the `Agent` facade — and fix 4 existing tests that assert nothing meaningful.

**Architecture:** Test-only effort in 4 sequential blocks by risk (Providers → Conversation → Agent core/DTOs/misc), preceded by a small infrastructure block (sqlite test DB, Redis facade mocking convention, pcov coverage tooling). Providers are tested via the same Guzzle `MockHandler` + request-history pattern already used in `tests/Unit/Knowledge/QdrantStoreTest.php`. Conversation DB-backed stores use a real sqlite `:memory:` connection; Redis-backed stores mock the `Redis` facade.

**Tech Stack:** PHPUnit, Mockery, Guzzle `MockHandler`, Orchestra Testbench, sqlite in-memory, `pcov`.

**Spec:** `docs/superpowers/specs/2026-08-08-unit-test-suite-improvements-design.md`

---

## Task 1: Test infrastructure — sqlite DB + coverage tooling

**Files:**
- Modify: `tests/TestCase.php`
- Modify: `composer.json`
- Modify: `phpunit.xml`

- [ ] **Step 1: Add sqlite in-memory DB config to `TestCase`**

Replace `tests/TestCase.php` with:

```php
<?php

namespace Peralta\AgentKit\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Peralta\AgentKit\AgentKitServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            AgentKitServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
```

- [ ] **Step 2: Verify existing DB-touching tests still pass**

Run: `vendor/bin/phpunit tests/Unit/Models/AgentMetricTest.php tests/Unit/Listeners/PersistMetricsListenerTest.php tests/Feature/AnalyticsIntegrationTest.php tests/Feature/AnalyticsListenerRegistrationTest.php`
Expected: PASS — these already `require`+`->up()` their own migration directly, unaffected by the new default connection, but must keep working since sqlite is now the default connection instead of whatever Testbench used before.

- [ ] **Step 3: Add pcov to composer.json**

Read `composer.json` first. In the `require-dev` section, add:

```json
"pcov/clobber": "^2.0"
```

If `pcov/clobber` fails to install (check with `composer require --dev pcov/clobber --dry-run` first — it requires the `pcov` PHP extension to already be loaded, which this package doesn't control), instead just document the requirement: add a comment-less `"ext-pcov": "*"` is NOT required (extensions aren't always available in every dev environment) — skip the composer.json change entirely if `pcov` the PHP extension isn't loaded in this environment (check with `php -m | grep -i pcov`), and note this in your report. Do not fail the task over unavailable tooling — the `phpunit.xml` change (Step 4) is what matters; it produces a report IF a coverage driver is available, and is a harmless no-op otherwise.

- [ ] **Step 4: Add coverage config to phpunit.xml**

Read `phpunit.xml` first. Add a `<source>` and `<coverage>` block as siblings of `<testsuites>`:

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

- [ ] **Step 5: Confirm the full suite still passes**

Run: `vendor/bin/phpunit`
Expected: PASS, same test count as before this task (159 tests). If a coverage driver (pcov or xdebug) happens to be available in this environment, `vendor/bin/phpunit --coverage-text` will also produce a report — try it and note the result in your report, but a missing coverage driver is not a failure condition for this task.

- [ ] **Step 6: Commit**

```bash
git add tests/TestCase.php composer.json phpunit.xml composer.lock
git commit -m "test: add sqlite test DB config and coverage tooling"
```

---

## Task 2: `ArrayStoreTest`

**Files:**
- Create: `tests/Unit/Conversation/ArrayStoreTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Conversation;

use Peralta\AgentKit\Conversation\Drivers\ArrayStore;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\Tests\TestCase;

class ArrayStoreTest extends TestCase
{
    public function test_load_of_unknown_conversation_returns_empty_array()
    {
        $store = new ArrayStore();

        $this->assertSame([], $store->load('unknown'));
    }

    public function test_append_then_load_round_trips_a_message()
    {
        $store = new ArrayStore();
        $msg = Message::user('olá');

        $store->append('conv-1', $msg);

        $this->assertSame([$msg], $store->load('conv-1'));
    }

    public function test_append_accumulates_multiple_messages_in_order()
    {
        $store = new ArrayStore();
        $first = Message::user('primeira');
        $second = Message::assistant('segunda');

        $store->append('conv-1', $first);
        $store->append('conv-1', $second);

        $this->assertSame([$first, $second], $store->load('conv-1'));
    }

    public function test_replace_overwrites_existing_messages()
    {
        $store = new ArrayStore();
        $store->append('conv-1', Message::user('original'));

        $replacement = [Message::user('novo')];
        $store->replace('conv-1', $replacement);

        $this->assertSame($replacement, $store->load('conv-1'));
    }

    public function test_clear_empties_the_conversation()
    {
        $store = new ArrayStore();
        $store->append('conv-1', Message::user('olá'));

        $store->clear('conv-1');

        $this->assertSame([], $store->load('conv-1'));
    }

    public function test_different_conversation_ids_are_isolated()
    {
        $store = new ArrayStore();
        $store->append('conv-1', Message::user('conversa 1'));
        $store->append('conv-2', Message::user('conversa 2'));

        $this->assertCount(1, $store->load('conv-1'));
        $this->assertCount(1, $store->load('conv-2'));
        $this->assertNotSame($store->load('conv-1'), $store->load('conv-2'));
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Conversation/ArrayStoreTest.php`
Expected: PASS (6 tests) — `ArrayStore` is a trivial in-memory class, no implementation changes needed.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Conversation/ArrayStoreTest.php
git commit -m "test: add ArrayStore unit tests"
```

---

## Task 3: `ConversationManagerTest`

**Files:**
- Create: `tests/Unit/Conversation/ConversationManagerTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Conversation;

use Mockery;
use Peralta\AgentKit\Conversation\Contracts\ConversationStore;
use Peralta\AgentKit\Conversation\ConversationManager;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\Tests\TestCase;

class ConversationManagerTest extends TestCase
{
    public function test_load_delegates_to_store()
    {
        $store = Mockery::mock(ConversationStore::class);
        $messages = [Message::user('oi')];
        $store->shouldReceive('load')->once()->with('conv-1')->andReturn($messages);

        $manager = new ConversationManager($store, maxMessages: 50);

        $this->assertSame($messages, $manager->load('conv-1'));
    }

    public function test_clear_delegates_to_store()
    {
        $store = Mockery::mock(ConversationStore::class);
        $store->shouldReceive('clear')->once()->with('conv-1');

        $manager = new ConversationManager($store, maxMessages: 50);
        $manager->clear('conv-1');
    }

    public function test_append_calls_store_append_then_checks_truncation()
    {
        $store = Mockery::mock(ConversationStore::class);
        $msg = Message::user('oi');

        $store->shouldReceive('append')->once()->with('conv-1', $msg);
        $store->shouldReceive('load')->once()->with('conv-1')->andReturn([$msg]);

        $manager = new ConversationManager($store, maxMessages: 50);
        $manager->append('conv-1', $msg);
    }

    public function test_append_does_not_truncate_when_under_the_limit()
    {
        $store = Mockery::mock(ConversationStore::class);
        $messages = array_fill(0, 5, Message::user('oi'));

        $store->shouldReceive('append')->once();
        $store->shouldReceive('load')->once()->andReturn($messages);
        $store->shouldNotReceive('replace');

        $manager = new ConversationManager($store, maxMessages: 50);
        $manager->append('conv-1', Message::user('oi'));
    }

    public function test_append_truncates_to_max_messages_when_over_the_limit()
    {
        $store = Mockery::mock(ConversationStore::class);
        $messages = array_map(fn($i) => Message::user("msg-{$i}"), range(1, 5));

        $store->shouldReceive('append')->once();
        $store->shouldReceive('load')->once()->andReturn($messages);
        $store->shouldReceive('replace')->once()->with('conv-1', Mockery::on(
            fn($kept) => count($kept) === 3 && $kept[0]->content === 'msg-3'
        ));

        $manager = new ConversationManager($store, maxMessages: 3);
        $manager->append('conv-1', Message::user('irrelevant, load() is mocked'));
    }

    public function test_truncation_drops_leading_orphan_tool_message()
    {
        $store = Mockery::mock(ConversationStore::class);
        // After slicing to the last 3, the first would be a 'tool' message — must be dropped too.
        $messages = [
            Message::user('msg-1'),
            Message::assistant('msg-2'),
            Message::tool('call-id', 'resultado'), // role=tool, would become the leading kept message
            Message::user('msg-4'),
        ];

        $store->shouldReceive('append')->once();
        $store->shouldReceive('load')->once()->andReturn($messages);
        $store->shouldReceive('replace')->once()->with('conv-1', Mockery::on(
            fn($kept) => count($kept) === 1 && $kept[0]->content === 'msg-4'
        ));

        $manager = new ConversationManager($store, maxMessages: 2);
        $manager->append('conv-1', Message::user('irrelevant, load() is mocked'));
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Conversation/ConversationManagerTest.php`
Expected: PASS (6 tests) — no production code changes needed, `ConversationManager` already implements this behavior.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Conversation/ConversationManagerTest.php
git commit -m "test: add ConversationManager unit tests"
```

---

## Task 4: `DatabaseStoreTest`

**Files:**
- Create: `tests/Unit/Conversation/DatabaseStoreTest.php`

Depends on Task 1's sqlite config being in place.

- [ ] **Step 1: Write the test**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Conversation;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Peralta\AgentKit\Conversation\Drivers\DatabaseStore;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\DTOs\ToolCall;
use Peralta\AgentKit\Tests\TestCase;

class DatabaseStoreTest extends TestCase
{
    protected function defineDatabaseMigrations()
    {
        Schema::create('agent_messages', function (Blueprint $t) {
            $t->id();
            $t->string('conversation_id', 100)->index();
            $t->string('role', 20);
            $t->longText('content')->nullable();
            $t->json('tool_calls')->nullable();
            $t->string('tool_call_id')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->index(['conversation_id', 'id']);
        });
    }

    public function test_load_of_unknown_conversation_returns_empty_array()
    {
        $store = new DatabaseStore();

        $this->assertSame([], $store->load('unknown'));
    }

    public function test_append_then_load_round_trips_a_plain_message()
    {
        $store = new DatabaseStore();
        $store->append('conv-1', Message::user('olá mundo'));

        $loaded = $store->load('conv-1');

        $this->assertCount(1, $loaded);
        $this->assertSame('user', $loaded[0]->role);
        $this->assertSame('olá mundo', $loaded[0]->content);
    }

    public function test_append_then_load_round_trips_tool_calls_and_metadata()
    {
        $store = new DatabaseStore();
        $msg = Message::assistant('usando tool', [new ToolCall('call-1', 'search', ['q' => 'php'])]);

        $store->append('conv-1', $msg);
        $loaded = $store->load('conv-1');

        $this->assertCount(1, $loaded[0]->toolCalls);
        $this->assertSame('call-1', $loaded[0]->toolCalls[0]->id);
        $this->assertSame('search', $loaded[0]->toolCalls[0]->name);
        $this->assertSame(['q' => 'php'], $loaded[0]->toolCalls[0]->arguments);
    }

    public function test_load_preserves_insertion_order()
    {
        $store = new DatabaseStore();
        $store->append('conv-1', Message::user('primeira'));
        $store->append('conv-1', Message::assistant('segunda'));

        $loaded = $store->load('conv-1');

        $this->assertSame('primeira', $loaded[0]->content);
        $this->assertSame('segunda', $loaded[1]->content);
    }

    public function test_replace_deletes_and_reinserts_for_the_given_conversation_only()
    {
        $store = new DatabaseStore();
        $store->append('conv-1', Message::user('antiga'));
        $store->append('conv-2', Message::user('outra conversa'));

        $store->replace('conv-1', [Message::user('nova')]);

        $conv1 = $store->load('conv-1');
        $this->assertCount(1, $conv1);
        $this->assertSame('nova', $conv1[0]->content);

        $conv2 = $store->load('conv-2');
        $this->assertCount(1, $conv2);
        $this->assertSame('outra conversa', $conv2[0]->content);
    }

    public function test_clear_deletes_only_the_given_conversation()
    {
        $store = new DatabaseStore();
        $store->append('conv-1', Message::user('a apagar'));
        $store->append('conv-2', Message::user('a manter'));

        $store->clear('conv-1');

        $this->assertSame([], $store->load('conv-1'));
        $this->assertCount(1, $store->load('conv-2'));
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Conversation/DatabaseStoreTest.php`
Expected: PASS (6 tests) — `DatabaseStore` already implements this behavior correctly against a real DB connection; no production code changes expected. If any test fails, investigate whether it's a real bug (report before fixing blindly) or a test setup issue (e.g. missing `updated_at`/`created_at` handling — `DatabaseStore::append()` sets both via `now()`, which requires the sqlite connection to support Carbon's `now()`, which it does by default in Laravel).

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Conversation/DatabaseStoreTest.php
git commit -m "test: add DatabaseStore unit tests"
```

---

## Task 5: `RedisStoreTest`

**Files:**
- Create: `tests/Unit/Conversation/RedisStoreTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Conversation;

use Illuminate\Support\Facades\Redis;
use Mockery;
use Peralta\AgentKit\Conversation\Drivers\RedisStore;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\Tests\TestCase;

class RedisStoreTest extends TestCase
{
    public function test_append_pushes_serialized_message_and_sets_expiry()
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->with('default')->andReturn($connection);

        $connection->shouldReceive('rpush')->once()->with(
            'agent:conv:conv-1',
            Mockery::on(fn($json) => json_decode($json, true)['content'] === 'olá'),
        );
        $connection->shouldReceive('expire')->once()->with('agent:conv:conv-1', 2592000);

        $store = new RedisStore();
        $store->append('conv-1', Message::user('olá'));
    }

    public function test_load_calls_lrange_and_deserializes_each_entry()
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->with('default')->andReturn($connection);

        $serialized = json_encode(['role' => 'user', 'content' => 'oi', 'tool_calls' => [], 'tool_call_id' => null, 'metadata' => []]);
        $connection->shouldReceive('lrange')->once()->with('agent:conv:conv-1', 0, -1)->andReturn([$serialized]);

        $store = new RedisStore();
        $loaded = $store->load('conv-1');

        $this->assertCount(1, $loaded);
        $this->assertSame('user', $loaded[0]->role);
        $this->assertSame('oi', $loaded[0]->content);
    }

    public function test_clear_calls_del_with_prefixed_key()
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('del')->once()->with('agent:conv:conv-1');

        $store = new RedisStore();
        $store->clear('conv-1');
    }

    public function test_uses_configured_connection_prefix_and_ttl()
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->with('custom')->andReturn($connection);

        $connection->shouldReceive('rpush')->once()->with('custom:prefix:conv-1', Mockery::type('string'));
        $connection->shouldReceive('expire')->once()->with('custom:prefix:conv-1', 60);

        $store = new RedisStore(connection: 'custom', prefix: 'custom:prefix:', ttl: 60);
        $store->append('conv-1', Message::user('oi'));
    }

    public function test_replace_deletes_then_rpushes_each_message_and_sets_expiry()
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->with('default')->andReturn($connection);

        $connection->shouldReceive('del')->once()->with('agent:conv:conv-1');
        $connection->shouldReceive('rpush')->twice()->with('agent:conv:conv-1', Mockery::type('string'));
        $connection->shouldReceive('expire')->once()->with('agent:conv:conv-1', 2592000);

        $store = new RedisStore();
        $store->replace('conv-1', [Message::user('um'), Message::user('dois')]);
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Conversation/RedisStoreTest.php`
Expected: PASS (5 tests) — no production code changes expected.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Conversation/RedisStoreTest.php
git commit -m "test: add RedisStore unit tests"
```

---

## Task 6: `HybridStoreTest`

**Files:**
- Create: `tests/Unit/Conversation/HybridStoreTest.php`

Depends on Task 1 (sqlite) and reuses the Redis-mocking convention from Task 5.

- [ ] **Step 1: Write the test**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Conversation;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Peralta\AgentKit\Conversation\Drivers\HybridStore;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\Tests\TestCase;

class HybridStoreTest extends TestCase
{
    protected function defineDatabaseMigrations()
    {
        Schema::create('agent_messages', function (Blueprint $t) {
            $t->id();
            $t->string('conversation_id', 100)->index();
            $t->string('role', 20);
            $t->longText('content')->nullable();
            $t->json('tool_calls')->nullable();
            $t->string('tool_call_id')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->index(['conversation_id', 'id']);
        });
    }

    public function test_load_reads_from_redis_when_redis_has_data()
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->with('default')->andReturn($connection);

        $serialized = json_encode(['role' => 'user', 'content' => 'do redis', 'tool_calls' => [], 'tool_call_id' => null, 'metadata' => []]);
        $connection->shouldReceive('lrange')->once()->andReturn([$serialized]);

        $store = new HybridStore();
        $loaded = $store->load('conv-1');

        $this->assertCount(1, $loaded);
        $this->assertSame('do redis', $loaded[0]->content);
    }

    public function test_load_falls_back_to_database_when_redis_is_empty()
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('lrange')->once()->andReturn([]);

        $store = new HybridStore();
        // Seed the DB directly to simulate a prior write, bypassing Redis.
        \Illuminate\Support\Facades\DB::table('agent_messages')->insert([
            'conversation_id' => 'conv-1',
            'role' => 'user',
            'content' => 'do banco',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $loaded = $store->load('conv-1');

        $this->assertCount(1, $loaded);
        $this->assertSame('do banco', $loaded[0]->content);
    }

    public function test_append_writes_to_both_redis_and_database()
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('rpush')->once();
        $connection->shouldReceive('expire')->once();

        $store = new HybridStore();
        $store->append('conv-1', Message::user('em ambos'));

        $dbRow = \Illuminate\Support\Facades\DB::table('agent_messages')->where('conversation_id', 'conv-1')->first();
        $this->assertNotNull($dbRow);
        $this->assertSame('em ambos', $dbRow->content);
    }

    public function test_clear_removes_from_both_redis_and_database()
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('rpush')->once();
        $connection->shouldReceive('expire')->once();
        $connection->shouldReceive('del')->once();

        $store = new HybridStore();
        $store->append('conv-1', Message::user('a apagar'));
        $store->clear('conv-1');

        $dbRow = \Illuminate\Support\Facades\DB::table('agent_messages')->where('conversation_id', 'conv-1')->first();
        $this->assertNull($dbRow);
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Conversation/HybridStoreTest.php`
Expected: PASS (4 tests) — no production code changes expected.

- [ ] **Step 3: Run the full Conversation block together**

Run: `vendor/bin/phpunit tests/Unit/Conversation`
Expected: PASS (21 tests total across Tasks 2-6)

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/Conversation/HybridStoreTest.php
git commit -m "test: add HybridStore unit tests"
```

---

## Task 7: `AnthropicProviderTest`

**Files:**
- Create: `tests/Unit/Providers/AnthropicProviderTest.php`

Uses the same Guzzle `MockHandler` pattern as `tests/Unit/Knowledge/QdrantStoreTest.php`, but injected via `AbstractProvider`'s `config['handler']` constructor key (a `HandlerStack`, not a `Client` instance).

- [ ] **Step 1: Write the test**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Providers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Peralta\AgentKit\Contracts\Tool;
use Peralta\AgentKit\DTOs\Context;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\DTOs\ToolCall;
use Peralta\AgentKit\Exceptions\ProviderException;
use Peralta\AgentKit\Providers\AnthropicProvider;
use PHPUnit\Framework\TestCase;

class AnthropicProviderTest extends TestCase
{
    public function test_chat_sends_correct_url_headers_and_payload()
    {
        [$provider, $history] = $this->provider([
            new Response(200, [], json_encode([
                'content' => [['type' => 'text', 'text' => 'oi']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])),
        ]);

        $provider->chat(messages: [Message::user('olá')], system: 'seja breve');

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('http://api.test/messages', (string) $request->getUri());
        $this->assertSame('secret-key', $request->getHeaderLine('x-api-key'));
        $this->assertSame('2023-06-01', $request->getHeaderLine('anthropic-version'));

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('claude-x', $body['model']);
        $this->assertSame('seja breve', $body['system']);
        $this->assertSame([['role' => 'user', 'content' => 'olá']], $body['messages']);
    }

    public function test_chat_parses_text_response()
    {
        [$provider] = $this->provider([
            new Response(200, [], json_encode([
                'content' => [['type' => 'text', 'text' => 'resposta']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
            ])),
        ]);

        $response = $provider->chat(messages: [Message::user('oi')]);

        $this->assertSame('resposta', $response->text());
        $this->assertSame('stop', $response->stopReason);
        $this->assertFalse($response->hasToolCalls());
        $this->assertSame(3, $response->inputTokens);
        $this->assertSame(2, $response->outputTokens);
    }

    public function test_chat_parses_tool_use_response()
    {
        [$provider] = $this->provider([
            new Response(200, [], json_encode([
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'call-1',
                    'name' => 'search',
                    'input' => ['q' => 'php'],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ])),
        ]);

        $response = $provider->chat(messages: [Message::user('busque')], tools: [$this->fakeTool()]);

        $this->assertTrue($response->hasToolCalls());
        $this->assertSame('tool_use', $response->stopReason);
        $this->assertSame('call-1', $response->toolCalls()[0]->id);
        $this->assertSame('search', $response->toolCalls()[0]->name);
        $this->assertSame(['q' => 'php'], $response->toolCalls()[0]->arguments);
    }

    public function test_chat_includes_formatted_tools_in_payload()
    {
        [$provider, $history] = $this->provider([
            new Response(200, [], json_encode([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'stop_reason' => 'end_turn',
            ])),
        ]);

        $provider->chat(messages: [Message::user('oi')], tools: [$this->fakeTool()]);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame([[
            'name' => 'search',
            'description' => 'Busca coisas',
            'input_schema' => ['type' => 'object'],
        ]], $body['tools']);
    }

    public function test_throws_provider_exception_on_missing_content_key()
    {
        [$provider] = $this->provider([
            new Response(200, [], json_encode(['stop_reason' => 'end_turn'])),
        ]);

        $this->expectException(ProviderException::class);
        $provider->chat(messages: [Message::user('oi')]);
    }

    public function test_throws_provider_exception_on_malformed_json()
    {
        [$provider] = $this->provider([
            new Response(200, [], 'not json at all'),
        ]);

        $this->expectException(ProviderException::class);
        $provider->chat(messages: [Message::user('oi')]);
    }

    public function test_throws_provider_exception_on_http_error_status()
    {
        [$provider] = $this->provider([
            new Response(500, [], json_encode(['error' => 'server error'])),
        ]);

        $this->expectException(ProviderException::class);
        $provider->chat(messages: [Message::user('oi')]);
    }

    private function provider(array $responses): array
    {
        $history = new \ArrayObject();
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        $provider = new AnthropicProvider([
            'base_url' => 'http://api.test',
            'api_key' => 'secret-key',
            'model' => 'claude-x',
            'anthropic_version' => '2023-06-01',
            'max_tokens' => 4096,
            'handler' => $stack,
        ]);

        return [$provider, $history];
    }

    private function fakeTool(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'search'; }
            public function description(): string { return 'Busca coisas'; }
            public function schema(): array { return ['type' => 'object']; }
            public function authorize(Context $context): bool { return true; }
            public function handle(array $input, Context $context): mixed { return []; }
        };
    }
}
```

Note: `Response(500, ...)` with Guzzle's default `http_errors` behavior throws a `GuzzleException`, which `AbstractProvider::request()` catches and rethrows as `ProviderException` — this is why the http-error test doesn't need `'http_errors' => false` like `QdrantStoreTest` uses (that package tests raw responses differently). If Step 2 shows this test unexpectedly failing (e.g. no exception thrown), read `AbstractProvider::request()`'s catch block again and adjust — do not weaken the assertion to work around unexpected behavior; investigate first and report if it looks like a real bug.

- [ ] **Step 2: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Providers/AnthropicProviderTest.php`
Expected: PASS (7 tests)

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Providers/AnthropicProviderTest.php
git commit -m "test: add AnthropicProvider unit tests"
```

---

## Task 8: `OpenAIProviderTest`

**Files:**
- Create: `tests/Unit/Providers/OpenAIProviderTest.php`

Same pattern as Task 7. OpenAI-specific behavior to verify: `Authorization: Bearer` header, `tool_calls[].function.arguments` is a JSON *string* on the wire that gets `json_decode`d back into an array, `finish_reason` mapping.

- [ ] **Step 1: Write the test**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Providers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Peralta\AgentKit\Contracts\Tool;
use Peralta\AgentKit\DTOs\Context;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\Exceptions\ProviderException;
use Peralta\AgentKit\Providers\OpenAIProvider;
use PHPUnit\Framework\TestCase;

class OpenAIProviderTest extends TestCase
{
    public function test_chat_sends_correct_url_headers_and_payload()
    {
        [$provider, $history] = $this->provider([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'oi'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ])),
        ]);

        $provider->chat(messages: [Message::user('olá')], system: 'seja breve');

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('http://api.test/chat/completions', (string) $request->getUri());
        $this->assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('gpt-4o', $body['model']);
        $this->assertSame([
            ['role' => 'system', 'content' => 'seja breve'],
            ['role' => 'user', 'content' => 'olá'],
        ], $body['messages']);
    }

    public function test_chat_parses_text_response()
    {
        [$provider] = $this->provider([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'resposta'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2],
            ])),
        ]);

        $response = $provider->chat(messages: [Message::user('oi')]);

        $this->assertSame('resposta', $response->text());
        $this->assertSame('stop', $response->stopReason);
        $this->assertSame(3, $response->inputTokens);
        $this->assertSame(2, $response->outputTokens);
    }

    public function test_chat_decodes_tool_call_arguments_json_string_into_array()
    {
        [$provider] = $this->provider([
            new Response(200, [], json_encode([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call-1',
                            'type' => 'function',
                            'function' => ['name' => 'search', 'arguments' => '{"q":"php"}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ])),
        ]);

        $response = $provider->chat(messages: [Message::user('busque')], tools: [$this->fakeTool()]);

        $this->assertTrue($response->hasToolCalls());
        $this->assertSame('tool_use', $response->stopReason);
        $this->assertSame('call-1', $response->toolCalls()[0]->id);
        $this->assertSame(['q' => 'php'], $response->toolCalls()[0]->arguments);
    }

    public function test_chat_includes_formatted_tools_and_tool_choice_in_payload()
    {
        [$provider, $history] = $this->provider([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
            ])),
        ]);

        $provider->chat(messages: [Message::user('oi')], tools: [$this->fakeTool()]);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('auto', $body['tool_choice']);
        $this->assertSame([[
            'type' => 'function',
            'function' => [
                'name' => 'search',
                'description' => 'Busca coisas',
                'parameters' => ['type' => 'object'],
            ],
        ]], $body['tools']);
    }

    public function test_throws_provider_exception_on_missing_choices()
    {
        [$provider] = $this->provider([
            new Response(200, [], json_encode(['choices' => []])),
        ]);

        $this->expectException(ProviderException::class);
        $provider->chat(messages: [Message::user('oi')]);
    }

    public function test_throws_provider_exception_on_malformed_json()
    {
        [$provider] = $this->provider([
            new Response(200, [], 'not json'),
        ]);

        $this->expectException(ProviderException::class);
        $provider->chat(messages: [Message::user('oi')]);
    }

    public function test_throws_provider_exception_on_http_error_status()
    {
        [$provider] = $this->provider([
            new Response(500, [], json_encode(['error' => 'boom'])),
        ]);

        $this->expectException(ProviderException::class);
        $provider->chat(messages: [Message::user('oi')]);
    }

    private function provider(array $responses): array
    {
        $history = new \ArrayObject();
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        $provider = new OpenAIProvider([
            'base_url' => 'http://api.test',
            'api_key' => 'secret-key',
            'model' => 'gpt-4o',
            'max_tokens' => 4096,
            'handler' => $stack,
        ]);

        return [$provider, $history];
    }

    private function fakeTool(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'search'; }
            public function description(): string { return 'Busca coisas'; }
            public function schema(): array { return ['type' => 'object']; }
            public function authorize(Context $context): bool { return true; }
            public function handle(array $input, Context $context): mixed { return []; }
        };
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Providers/OpenAIProviderTest.php`
Expected: PASS (7 tests)

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Providers/OpenAIProviderTest.php
git commit -m "test: add OpenAIProvider unit tests"
```

---

## Task 9: `GeminiProviderTest`

**Files:**
- Create: `tests/Unit/Providers/GeminiProviderTest.php`

Gemini-specific behavior: API key in query string (not header), synthetic tool-call IDs, `cleanSchemaForGemini()` schema stripping.

- [ ] **Step 1: Write the test**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Providers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Peralta\AgentKit\Contracts\Tool;
use Peralta\AgentKit\DTOs\Context;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\Exceptions\ProviderException;
use Peralta\AgentKit\Providers\GeminiProvider;
use PHPUnit\Framework\TestCase;

class GeminiProviderTest extends TestCase
{
    public function test_chat_sends_api_key_in_query_string_and_correct_payload()
    {
        [$provider, $history] = $this->provider([
            new Response(200, [], json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'oi']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ])),
        ]);

        $provider->chat(messages: [Message::user('olá')], system: 'seja breve');

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString('models/gemini-x:generateContent?key=secret-key', (string) $request->getUri());

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('seja breve', $body['systemInstruction']['parts'][0]['text']);
        $this->assertSame([['role' => 'user', 'parts' => [['text' => 'olá']]]], $body['contents']);
    }

    public function test_chat_parses_text_response()
    {
        [$provider] = $this->provider([
            new Response(200, [], json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'resposta']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 2],
            ])),
        ]);

        $response = $provider->chat(messages: [Message::user('oi')]);

        $this->assertSame('resposta', $response->text());
        $this->assertSame('stop', $response->stopReason);
        $this->assertSame(3, $response->inputTokens);
        $this->assertSame(2, $response->outputTokens);
    }

    public function test_chat_generates_synthetic_tool_call_id()
    {
        [$provider] = $this->provider([
            new Response(200, [], json_encode([
                'candidates' => [[
                    'content' => ['parts' => [['functionCall' => ['name' => 'search', 'args' => ['q' => 'php']]]]],
                    'finishReason' => 'STOP',
                ]],
            ])),
        ]);

        $response = $provider->chat(messages: [Message::user('busque')], tools: [$this->fakeTool()]);

        $this->assertTrue($response->hasToolCalls());
        $this->assertSame('tool_use', $response->stopReason);
        $this->assertStringStartsWith('gemini_', $response->toolCalls()[0]->id);
        $this->assertSame('search', $response->toolCalls()[0]->name);
        $this->assertSame(['q' => 'php'], $response->toolCalls()[0]->arguments);
    }

    public function test_clean_schema_for_gemini_strips_disallowed_keys_from_tool_payload()
    {
        [$provider, $history] = $this->provider([
            new Response(200, [], json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
            ])),
        ]);

        $tool = new class implements Tool {
            public function name(): string { return 'search'; }
            public function description(): string { return 'Busca coisas'; }
            public function schema(): array {
                return [
                    'type' => 'object',
                    'default' => 'x',
                    '$schema' => 'http://json-schema.org/draft-07/schema#',
                    'properties' => [
                        'q' => ['type' => 'string', 'additionalProperties' => false, 'examples' => ['php']],
                    ],
                ];
            }
            public function authorize(Context $context): bool { return true; }
            public function handle(array $input, Context $context): mixed { return []; }
        };

        $provider->chat(messages: [Message::user('oi')], tools: [$tool]);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $params = $body['tools'][0]['functionDeclarations'][0]['parameters'];

        $this->assertArrayNotHasKey('default', $params);
        $this->assertArrayNotHasKey('$schema', $params);
        $this->assertArrayNotHasKey('additionalProperties', $params['properties']['q']);
        $this->assertArrayNotHasKey('examples', $params['properties']['q']);
        $this->assertSame('string', $params['properties']['q']['type']);
    }

    public function test_throws_provider_exception_on_missing_candidates()
    {
        [$provider] = $this->provider([
            new Response(200, [], json_encode(['candidates' => []])),
        ]);

        $this->expectException(ProviderException::class);
        $provider->chat(messages: [Message::user('oi')]);
    }

    public function test_throws_provider_exception_on_malformed_json()
    {
        [$provider] = $this->provider([
            new Response(200, [], 'not json'),
        ]);

        $this->expectException(ProviderException::class);
        $provider->chat(messages: [Message::user('oi')]);
    }

    public function test_throws_provider_exception_on_http_error_status()
    {
        [$provider] = $this->provider([
            new Response(500, [], json_encode(['error' => 'boom'])),
        ]);

        $this->expectException(ProviderException::class);
        $provider->chat(messages: [Message::user('oi')]);
    }

    private function provider(array $responses): array
    {
        $history = new \ArrayObject();
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        $provider = new GeminiProvider([
            'base_url' => 'http://api.test',
            'api_key' => 'secret-key',
            'model' => 'gemini-x',
            'max_tokens' => 4096,
            'handler' => $stack,
        ]);

        return [$provider, $history];
    }

    private function fakeTool(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'search'; }
            public function description(): string { return 'Busca coisas'; }
            public function schema(): array { return ['type' => 'object']; }
            public function authorize(Context $context): bool { return true; }
            public function handle(array $input, Context $context): mixed { return []; }
        };
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Providers/GeminiProviderTest.php`
Expected: PASS (7 tests)

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Providers/GeminiProviderTest.php
git commit -m "test: add GeminiProvider unit tests"
```

---

## Task 10: `DeepSeekProviderTest`

**Files:**
- Create: `tests/Unit/Providers/DeepSeekProviderTest.php`

`DeepSeekProvider` is a thin subclass of `OpenAIProvider` overriding only `name()`. This test proves the subclass doesn't silently diverge from the parent's request/response handling — it does NOT need to repeat every scenario from Task 8.

- [ ] **Step 1: Write the test**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Providers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\Providers\DeepSeekProvider;
use PHPUnit\Framework\TestCase;

class DeepSeekProviderTest extends TestCase
{
    public function test_name_returns_deepseek()
    {
        $provider = new DeepSeekProvider([
            'base_url' => 'http://api.test',
            'api_key' => 'secret-key',
            'model' => 'deepseek-chat',
        ]);

        $this->assertSame('deepseek', $provider->name());
    }

    public function test_chat_uses_openai_compatible_request_and_response_shape()
    {
        $history = new \ArrayObject();
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'oi'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2],
            ])),
        ]));
        $stack->push(Middleware::history($history));

        $provider = new DeepSeekProvider([
            'base_url' => 'http://api.test',
            'api_key' => 'secret-key',
            'model' => 'deepseek-chat',
            'handler' => $stack,
        ]);

        $response = $provider->chat(messages: [Message::user('olá')]);

        $request = $history[0]['request'];
        $this->assertSame('http://api.test/chat/completions', (string) $request->getUri());
        $this->assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('oi', $response->text());
        $this->assertSame('stop', $response->stopReason);
        $this->assertSame(4, $response->inputTokens);
        $this->assertSame(2, $response->outputTokens);
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Providers/DeepSeekProviderTest.php`
Expected: PASS (2 tests)

- [ ] **Step 3: Run the full Providers block together**

Run: `vendor/bin/phpunit tests/Unit/Providers`
Expected: PASS (23 tests total across Tasks 7-10)

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/Providers/DeepSeekProviderTest.php
git commit -m "test: add DeepSeekProvider unit tests"
```

---

## Task 11: `AgentTest` — core send() loop and tool execution

**Files:**
- Create: `tests/Unit/AgentTest.php`

Uses the same pattern as `tests/Feature/AgentAnalyticsEventsTest.php`: a Mockery `Provider` mock bound into the container under `agent-kit.provider.{name}`, then `$this->app->make(Agent::class)`.

- [ ] **Step 1: Write the test**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit;

use Mockery;
use Peralta\AgentKit\Agent;
use Peralta\AgentKit\Contracts\Provider;
use Peralta\AgentKit\Contracts\Tool;
use Peralta\AgentKit\DTOs\AgentResponse;
use Peralta\AgentKit\DTOs\Context;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\DTOs\ToolCall;
use Peralta\AgentKit\Exceptions\MaxIterationsExceededException;
use Peralta\AgentKit\Tests\TestCase;

class AgentTest extends TestCase
{
    public function test_send_runs_multiple_iterations_for_tool_calls_then_returns_final_response()
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')
            ->once()
            ->andReturn(new AgentResponse(
                message: Message::assistant(null, [new ToolCall('call-1', 'echo', ['x' => 1])]),
                stopReason: 'tool_use',
            ));
        $provider->shouldReceive('chat')
            ->once()
            ->andReturn(new AgentResponse(
                message: Message::assistant('resposta final'),
                stopReason: 'stop',
                inputTokens: 5,
                outputTokens: 2,
            ));

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $agent = $this->app->make(Agent::class);
        $response = $agent->provider('openai')->tools([$this->echoTool()])->send('oi');

        $this->assertSame('resposta final', $response->text());
        $this->assertSame('stop', $response->stopReason);
    }

    public function test_send_throws_max_iterations_exceeded_when_provider_keeps_requesting_tools()
    {
        config(['agent-kit.safety.max_iterations' => 2]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')->andReturn(new AgentResponse(
            message: Message::assistant(null, [new ToolCall('call-1', 'echo', [])]),
            stopReason: 'tool_use',
        ));

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $agent = $this->app->make(Agent::class);

        $this->expectException(MaxIterationsExceededException::class);
        $agent->provider('openai')->tools([$this->echoTool()])->send('oi');
    }

    public function test_tool_not_found_returns_error_payload_without_throwing()
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')
            ->once()
            ->andReturn(new AgentResponse(
                message: Message::assistant(null, [new ToolCall('call-1', 'inexistente', [])]),
                stopReason: 'tool_use',
            ));
        $provider->shouldReceive('chat')
            ->once()
            ->andReturnUsing(function ($messages) {
                $toolResult = end($messages);
                $this->assertSame('tool', $toolResult->role);
                $this->assertStringContainsString('não está disponível', $toolResult->content);
                return new AgentResponse(message: Message::assistant('ok'), stopReason: 'stop');
            });

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $agent = $this->app->make(Agent::class);
        $agent->provider('openai')->send('oi'); // no tools registered — 'inexistente' won't be found
    }

    public function test_tool_not_authorized_returns_error_payload_without_calling_handle()
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')
            ->once()
            ->andReturn(new AgentResponse(
                message: Message::assistant(null, [new ToolCall('call-1', 'restrita', [])]),
                stopReason: 'tool_use',
            ));
        $provider->shouldReceive('chat')
            ->once()
            ->andReturnUsing(function ($messages) {
                $toolResult = end($messages);
                $this->assertStringContainsString('não tem permissão', $toolResult->content);
                return new AgentResponse(message: Message::assistant('ok'), stopReason: 'stop');
            });

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $tool = new class implements Tool {
            public function name(): string { return 'restrita'; }
            public function description(): string { return 'x'; }
            public function schema(): array { return []; }
            public function authorize(Context $context): bool { return false; }
            public function handle(array $input, Context $context): mixed {
                throw new \RuntimeException('handle() não deveria ser chamado');
            }
        };

        $agent = $this->app->make(Agent::class);
        $agent->provider('openai')->tools([$tool])->send('oi');
    }

    public function test_tool_handle_exception_is_caught_and_returns_error_payload()
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')
            ->once()
            ->andReturn(new AgentResponse(
                message: Message::assistant(null, [new ToolCall('call-1', 'quebra', [])]),
                stopReason: 'tool_use',
            ));
        $provider->shouldReceive('chat')
            ->once()
            ->andReturnUsing(function ($messages) {
                $toolResult = end($messages);
                $this->assertStringContainsString('Erro executando', $toolResult->content);
                $this->assertStringContainsString('boom', $toolResult->content);
                return new AgentResponse(message: Message::assistant('ok'), stopReason: 'stop');
            });

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $tool = new class implements Tool {
            public function name(): string { return 'quebra'; }
            public function description(): string { return 'x'; }
            public function schema(): array { return []; }
            public function authorize(Context $context): bool { return true; }
            public function handle(array $input, Context $context): mixed {
                throw new \RuntimeException('boom');
            }
        };

        $agent = $this->app->make(Agent::class);
        $agent->provider('openai')->tools([$tool])->send('oi');
    }

    private function echoTool(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'echo'; }
            public function description(): string { return 'ecoa entrada'; }
            public function schema(): array { return ['type' => 'object']; }
            public function authorize(Context $context): bool { return true; }
            public function handle(array $input, Context $context): mixed { return $input; }
        };
    }
}
```

Note: `Message::tool()` JSON-encodes non-string `$result` (see `src/DTOs/Message.php`'s `tool()` static constructor) — `executeTool()` in `src/Agent.php` returns arrays like `['erro' => '...']`, so the resulting `Message::tool()->content` is a JSON string like `{"erro":"..."}`. The `assertStringContainsString` calls above check substrings of that JSON, which works fine since the Portuguese error text appears verbatim inside the JSON string value.

- [ ] **Step 2: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/AgentTest.php`
Expected: PASS (5 tests). If `test_send_throws_max_iterations_exceeded_when_provider_keeps_requesting_tools` fails because `config(['agent-kit.safety.max_iterations' => 2])` doesn't affect the already-constructed `Agent`'s `$maxIterations` (set once in the constructor from `$config['safety']['max_iterations']` at container-resolution time) — this is expected: set the config BEFORE calling `$this->app->make(Agent::class)`, not after. The test above already does this in the right order; if you change anything, preserve that order.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/AgentTest.php
git commit -m "test: add Agent core send() loop and tool execution tests"
```

---

## Task 12: `AgentTest` — fluent builder and knowledgeBase()

**Files:**
- Modify: `tests/Unit/AgentTest.php` (extend from Task 11)

- [ ] **Step 1: Add fluent-builder tests**

Append these test methods to the `AgentTest` class from Task 11:

```php
    public function test_fluent_builder_methods_affect_the_provider_call()
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::any(),
                'seja conciso',
                Mockery::on(fn($opts) => $opts['model'] === 'gpt-4o-mini' && $opts['temperature'] === 0.2),
            )
            ->andReturn(new AgentResponse(message: Message::assistant('ok'), stopReason: 'stop'));

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $agent = $this->app->make(Agent::class);
        $agent->provider('openai')
            ->model('gpt-4o-mini')
            ->system('seja conciso')
            ->options(['temperature' => 0.2])
            ->send('oi');
    }

    public function test_context_method_accepts_array_and_is_available_to_tools()
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')
            ->once()
            ->andReturn(new AgentResponse(
                message: Message::assistant(null, [new ToolCall('call-1', 'ver_contexto', [])]),
                stopReason: 'tool_use',
            ));
        $provider->shouldReceive('chat')
            ->once()
            ->andReturn(new AgentResponse(message: Message::assistant('ok'), stopReason: 'stop'));

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $receivedTenantId = null;
        $tool = new class implements Tool {
            public $onHandle;
            public function name(): string { return 'ver_contexto'; }
            public function description(): string { return 'x'; }
            public function schema(): array { return []; }
            public function authorize(Context $context): bool { return true; }
            public function handle(array $input, Context $context): mixed {
                ($this->onHandle)($context);
                return [];
            }
        };
        $tool->onHandle = function (Context $context) use (&$receivedTenantId) {
            $receivedTenantId = $context->tenantId;
        };

        $agent = $this->app->make(Agent::class);
        $agent->provider('openai')->tools([$tool])->context(['tenant_id' => 'tenant-123'])->send('oi');

        $this->assertSame('tenant-123', $receivedTenantId);
    }

    public function test_knowledge_base_adds_knowledge_search_tool_to_resolved_tools()
    {
        config([
            'agent-kit.knowledge.embedder' => 'openai',
            'agent-kit.knowledge.embedders.openai' => ['driver' => 'openai', 'api_key' => 'x', 'model' => 'text-embedding-3-small'],
            'agent-kit.knowledge.store' => 'pgvector',
            'agent-kit.knowledge.stores.pgvector' => ['driver' => 'pgvector', 'connection' => 'pgsql', 'table' => 'knowledge_chunks'],
        ]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::on(function ($tools) {
                    return count($tools) === 1
                        && $tools[0] instanceof \Peralta\AgentKit\Knowledge\Tools\KnowledgeSearchTool;
                }),
                Mockery::any(),
                Mockery::any(),
            )
            ->andReturn(new AgentResponse(message: Message::assistant('ok'), stopReason: 'stop'));

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $agent = $this->app->make(Agent::class);
        $agent->provider('openai')->knowledgeBase('faq', 'Perguntas frequentes')->send('oi');
    }
```

- [ ] **Step 2: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/AgentTest.php`
Expected: PASS (8 tests total: 5 from Task 11 + 3 new). If `test_knowledge_base_adds_knowledge_search_tool_to_resolved_tools` fails because binding `Embedder::class`/`KnowledgeStore::class` in the container requires network calls or DB access — it shouldn't, since `resolveTools()` only *constructs* a `KnowledgeSearchTool` (which itself lazily uses the embedder/store only when `handle()` is called, not at construction time); if construction alone fails, read `src/Knowledge/Tools/KnowledgeSearchTool.php`'s constructor and the `AgentKitServiceProvider`'s `registerKnowledge()` binding to see what's failing, and report before working around it.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/AgentTest.php
git commit -m "test: add Agent fluent builder and knowledgeBase() tests"
```

---

## Task 13: DTO tests — `AgentResponse`, `Message`, `Context`

**Files:**
- Create: `tests/Unit/DTOs/AgentResponseTest.php`
- Create: `tests/Unit/DTOs/MessageTest.php`
- Create: `tests/Unit/DTOs/ContextTest.php`

- [ ] **Step 1: Write `AgentResponseTest`**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\DTOs;

use Peralta\AgentKit\DTOs\AgentResponse;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\DTOs\ToolCall;
use PHPUnit\Framework\TestCase;

class AgentResponseTest extends TestCase
{
    public function test_has_tool_calls_is_true_when_stop_reason_is_tool_use_and_tool_calls_present()
    {
        $response = new AgentResponse(
            message: Message::assistant(null, [new ToolCall('id-1', 'search', [])]),
            stopReason: 'tool_use',
        );

        $this->assertTrue($response->hasToolCalls());
    }

    public function test_has_tool_calls_is_false_when_stop_reason_is_stop()
    {
        $response = new AgentResponse(message: Message::assistant('oi'), stopReason: 'stop');

        $this->assertFalse($response->hasToolCalls());
    }

    public function test_has_tool_calls_is_false_when_tool_use_but_no_tool_calls_present()
    {
        // Defensive case: stopReason says tool_use but message has no toolCalls.
        $response = new AgentResponse(message: Message::assistant('oi'), stopReason: 'tool_use');

        $this->assertFalse($response->hasToolCalls());
    }

    public function test_text_returns_message_content()
    {
        $response = new AgentResponse(message: Message::assistant('conteúdo'), stopReason: 'stop');

        $this->assertSame('conteúdo', $response->text());
    }

    public function test_tool_calls_returns_message_tool_calls()
    {
        $calls = [new ToolCall('id-1', 'search', [])];
        $response = new AgentResponse(message: Message::assistant(null, $calls), stopReason: 'tool_use');

        $this->assertSame($calls, $response->toolCalls());
    }
}
```

- [ ] **Step 2: Write `MessageTest`**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\DTOs;

use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\DTOs\ToolCall;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    public function test_user_constructor()
    {
        $msg = Message::user('olá');

        $this->assertSame('user', $msg->role);
        $this->assertSame('olá', $msg->content);
    }

    public function test_assistant_constructor_with_tool_calls()
    {
        $calls = [new ToolCall('id-1', 'search', [])];
        $msg = Message::assistant('texto', $calls);

        $this->assertSame('assistant', $msg->role);
        $this->assertSame('texto', $msg->content);
        $this->assertSame($calls, $msg->toolCalls);
    }

    public function test_system_constructor()
    {
        $msg = Message::system('regra do sistema');

        $this->assertSame('system', $msg->role);
        $this->assertSame('regra do sistema', $msg->content);
    }

    public function test_tool_constructor_with_string_result()
    {
        $msg = Message::tool('call-1', 'resultado simples');

        $this->assertSame('tool', $msg->role);
        $this->assertSame('call-1', $msg->toolCallId);
        $this->assertSame('resultado simples', $msg->content);
    }

    public function test_tool_constructor_json_encodes_non_string_result()
    {
        $msg = Message::tool('call-1', ['erro' => 'algo falhou']);

        $this->assertSame('{"erro":"algo falhou"}', $msg->content);
    }

    public function test_to_array_omits_null_fields()
    {
        $msg = Message::user('oi');

        $array = $msg->toArray();

        $this->assertArrayNotHasKey('tool_calls', $array);
        $this->assertArrayNotHasKey('tool_call_id', $array);
        $this->assertArrayNotHasKey('metadata', $array);
        $this->assertSame('user', $array['role']);
        $this->assertSame('oi', $array['content']);
    }

    public function test_to_array_includes_serialized_tool_calls()
    {
        $msg = Message::assistant(null, [new ToolCall('id-1', 'search', ['q' => 'php'])]);

        $array = $msg->toArray();

        $this->assertSame([['id' => 'id-1', 'name' => 'search', 'arguments' => ['q' => 'php']]], $array['tool_calls']);
    }
}
```

- [ ] **Step 3: Write `ContextTest`**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\DTOs;

use Peralta\AgentKit\DTOs\Context;
use PHPUnit\Framework\TestCase;

class ContextTest extends TestCase
{
    public function test_from_array_extracts_tenant_id_and_user()
    {
        $context = Context::fromArray(['tenant_id' => 't-1', 'user' => 'alan']);

        $this->assertSame('t-1', $context->tenantId);
        $this->assertSame('alan', $context->user);
    }

    public function test_from_array_puts_remaining_keys_in_extra()
    {
        $context = Context::fromArray(['tenant_id' => 't-1', 'user' => 'alan', 'role' => 'admin']);

        $this->assertSame(['role' => 'admin'], $context->extra);
    }

    public function test_get_returns_extra_value_or_default()
    {
        $context = Context::fromArray(['role' => 'admin']);

        $this->assertSame('admin', $context->get('role'));
        $this->assertSame('fallback', $context->get('missing', 'fallback'));
        $this->assertNull($context->get('missing'));
    }

    public function test_from_array_with_no_tenant_or_user_defaults_to_null()
    {
        $context = Context::fromArray(['role' => 'admin']);

        $this->assertNull($context->tenantId);
        $this->assertNull($context->user);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/DTOs`
Expected: PASS (16 tests)

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/DTOs
git commit -m "test: add AgentResponse, Message, and Context DTO tests"
```

---

## Task 14: `AbstractToolTest` and `AgentFacadeTest`

**Files:**
- Create: `tests/Unit/Tools/AbstractToolTest.php`
- Create: `tests/Unit/Facades/AgentFacadeTest.php`

- [ ] **Step 1: Write `AbstractToolTest`**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Tools;

use Peralta\AgentKit\DTOs\Context;
use Peralta\AgentKit\Tools\AbstractTool;
use PHPUnit\Framework\TestCase;

class AbstractToolTest extends TestCase
{
    public function test_default_authorize_returns_true()
    {
        $tool = new class extends AbstractTool {
            public function name(): string { return 'minimal'; }
            public function description(): string { return 'x'; }
            public function schema(): array { return []; }
            public function handle(array $input, Context $context): mixed { return null; }
        };

        $this->assertTrue($tool->authorize(new Context()));
    }

    public function test_authorize_can_be_overridden()
    {
        $tool = new class extends AbstractTool {
            public function name(): string { return 'restrita'; }
            public function description(): string { return 'x'; }
            public function schema(): array { return []; }
            public function handle(array $input, Context $context): mixed { return null; }
            public function authorize(Context $context): bool { return false; }
        };

        $this->assertFalse($tool->authorize(new Context()));
    }
}
```

- [ ] **Step 2: Write `AgentFacadeTest`**

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Facades;

use Peralta\AgentKit\Agent;
use Peralta\AgentKit\Facades\Agent as AgentFacade;
use Peralta\AgentKit\Tests\TestCase;

class AgentFacadeTest extends TestCase
{
    public function test_facade_resolves_to_agent_container_binding()
    {
        $this->assertInstanceOf(Agent::class, AgentFacade::getFacadeRoot());
    }

    public function test_make_returns_a_new_agent_instance_each_time()
    {
        $first = AgentFacade::make();
        $second = AgentFacade::make();

        $this->assertInstanceOf(Agent::class, $first);
        $this->assertInstanceOf(Agent::class, $second);
        $this->assertNotSame($first, $second);
    }

    public function test_facade_fluent_call_returns_agent_instance()
    {
        $result = AgentFacade::provider('openai');

        $this->assertInstanceOf(Agent::class, $result);
    }
}
```

- [ ] **Step 3: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Tools tests/Unit/Facades`
Expected: PASS (5 tests)

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/Tools/AbstractToolTest.php tests/Unit/Facades/AgentFacadeTest.php
git commit -m "test: add AbstractTool and Agent facade tests"
```

---

## Task 15: Fix weak assertion in `PersistMetricsListenerTest`

**Files:**
- Modify: `tests/Unit/Listeners/PersistMetricsListenerTest.php`

- [ ] **Step 1: Replace the no-op assertion**

In `tests/Unit/Listeners/PersistMetricsListenerTest.php`, replace `test_swallows_exceptions_instead_of_throwing`'s final line:

```php
        $this->assertTrue(true); // should not throw
```

with:

```php
        $this->assertDatabaseCount('agent_kit_metrics', 0);
```

The rest of the test (config setup pointing `analytics.table` at a nonexistent table, then calling `handle()`) stays unchanged — the fix only replaces the trailing no-op assertion with a real one proving nothing was silently written anywhere (specifically: the real `agent_kit_metrics` table, which DOES exist from the migration set up in `defineDatabaseMigrations()`, still has zero rows, confirming `PersistMetricsListener` didn't fall back to writing to the wrong table or otherwise succeed unexpectedly).

- [ ] **Step 2: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Listeners/PersistMetricsListenerTest.php`
Expected: PASS (3 tests, same count as before — only the assertion changed)

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Listeners/PersistMetricsListenerTest.php
git commit -m "test: assert no row is persisted in PersistMetricsListener exception-swallowing test"
```

---

## Task 16: Fix weak assertions in `DiscordNotifierTest`

**Files:**
- Modify: `tests/Unit/ErrorRecovery/DiscordNotifierTest.php`

Three tests reduce to `assertTrue(true)`. `DiscordNotifier` uses the Laravel `Http` facade (already `Http::fake()`-able, as the other tests in this file already do) — no need for the Guzzle `MockHandler` pattern used elsewhere in this plan.

- [ ] **Step 1: Strengthen `test_null_notifier_is_a_no_op`**

Replace the method body:

```php
    public function test_null_notifier_is_a_no_op()
    {
        Http::fake();

        $notifier = new NullNotifier();

        $notifier->notifyFailure('anything', []);
        $notifier->notifyFallback('anthropic', 'reason');

        Http::assertNothingSent();
    }
```

(Adds `Http::fake()` + `Http::assertNothingSent()` to prove `NullNotifier` genuinely makes no HTTP calls, not just that it doesn't throw.)

- [ ] **Step 2: Strengthen `test_does_not_throw_when_webhook_request_fails`**

Replace the method body:

```php
    public function test_does_not_throw_when_webhook_request_fails()
    {
        Http::fake(['discord.com/*' => Http::response(['error' => 'boom'], 500)]);

        $notifier = new DiscordNotifier(
            webhookUrl: 'https://discord.com/api/webhooks/123/abc',
            mention: null,
        );

        // não deve lançar exception mesmo com resposta de erro
        $notifier->notifyFailure('teste', []);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://discord.com/api/webhooks/123/abc'
                && str_contains($request['content'], 'teste');
        });
    }
```

(Proves the request WAS attempted with the expected payload despite the 500 response — the "doesn't throw" behavior is meaningful specifically because a real send was attempted and its failure was swallowed, not because nothing happened.)

- [ ] **Step 3: Strengthen `test_does_not_throw_when_connection_fails`**

Replace the method body:

```php
    public function test_does_not_throw_when_connection_fails()
    {
        Log::spy();
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });

        $notifier = new DiscordNotifier(
            webhookUrl: 'https://discord.com/api/webhooks/123/abc',
            mention: null,
        );

        // não deve lançar exception mesmo com falha de conexão
        $notifier->notifyFailure('teste', []);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('[agent-kit] Falha ao enviar alerta pro Discord', Mockery::on(
                fn($context) => str_contains($context['error'], 'Connection refused')
            ));
    }
```

Add the two needed imports at the top of the file:

```php
use Illuminate\Support\Facades\Log;
use Mockery;
```

(Proves the connection failure is actually caught AND logged via `Log::warning`, matching `DiscordNotifier::send()`'s catch block — not just "didn't throw".)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ErrorRecovery/DiscordNotifierTest.php`
Expected: PASS (6 tests, same count as before — only assertions changed)

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/ErrorRecovery/DiscordNotifierTest.php
git commit -m "test: assert real HTTP/logging behavior in DiscordNotifier no-throw tests"
```

---

## Final Verification

- [ ] Run the complete suite: `vendor/bin/phpunit`
- [ ] Expected: 0 failures, 0 errors. Test count should be roughly 159 (pre-existing) + ~21 (Conversation) + ~23 (Providers) + ~8 (Agent) + ~21 (DTOs/Tool/Facade) ≈ 232+ tests, with no `assertTrue(true)` remaining anywhere in the 4 previously-weak test methods.
- [ ] Confirm no weak assertions remain: `grep -rn "assertTrue(true)" tests/` should return nothing (or only genuinely new, justified uses — review any hits).
- [ ] If a coverage driver was available in Task 1, run `vendor/bin/phpunit --coverage-text` one final time and note the resulting percentage for the human's awareness (no enforced threshold, informational only).
