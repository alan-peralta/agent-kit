<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp\Http;

use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\BoundedInMemorySessionStore;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class BoundedInMemorySessionStoreTest extends TestCase
{
    public function test_it_evicts_the_oldest_session_when_the_bound_is_reached(): void
    {
        $clock = $this->clock();
        $store = new BoundedInMemorySessionStore(3600, 2, $clock);
        [$a, $b, $c] = [Uuid::v4(), Uuid::v4(), Uuid::v4()];

        $store->write($a, 'a');
        $clock->now = $clock->now->modify('+1 second');
        $store->write($b, 'b');
        $clock->now = $clock->now->modify('+1 second');
        $store->write($c, 'c');

        self::assertSame(2, $store->count());
        self::assertFalse($store->exists($a));
        self::assertTrue($store->exists($b));
        self::assertTrue($store->exists($c));
    }

    public function test_updating_an_existing_session_never_evicts(): void
    {
        $store = new BoundedInMemorySessionStore(3600, 1, $this->clock());
        $id = Uuid::v4();

        $store->write($id, 'first');
        $store->write($id, 'second');

        self::assertSame('second', $store->read($id));
        self::assertSame(1, $store->count());
    }

    public function test_expired_sessions_are_garbage_collected_and_clear_empties_the_store(): void
    {
        $clock = $this->clock();
        $store = new BoundedInMemorySessionStore(10, 5, $clock);
        $id = Uuid::v4();
        $store->write($id, 'data');

        $clock->now = $clock->now->modify('+11 seconds');
        self::assertCount(1, $store->gc());
        self::assertFalse($store->exists($id));

        $store->write(Uuid::v4(), 'x');
        $store->clear();
        self::assertSame(0, $store->count());
    }

    public function test_a_zero_bound_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BoundedInMemorySessionStore(10, 0);
    }

    /** @return ClockInterface&object{now: \DateTimeImmutable} */
    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public \DateTimeImmutable $now;

            public function __construct()
            {
                $this->now = new \DateTimeImmutable('2026-09-16 12:00:00');
            }

            public function now(): \DateTimeImmutable
            {
                return $this->now;
            }
        };
    }
}
