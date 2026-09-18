<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport\Http;

use InvalidArgumentException;
use Mcp\Server\NativeClock;
use Mcp\Server\Session\InMemorySessionStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class BoundedInMemorySessionStore extends InMemorySessionStore
{
    public function __construct(
        int $ttl,
        private readonly int $maxSessions,
        ClockInterface $clock = new NativeClock(),
    ) {
        if ($maxSessions < 1) {
            throw new InvalidArgumentException('The session store must allow at least one session.');
        }
        parent::__construct($ttl, $clock);
    }

    public function write(Uuid $id, string $data): bool
    {
        if (!isset($this->store[$id->toRfc4122()]) && count($this->store) >= $this->maxSessions) {
            $this->evictOldest();
        }

        return parent::write($id, $data);
    }

    public function count(): int
    {
        return count($this->store);
    }

    public function clear(): void
    {
        $this->store = [];
    }

    private function evictOldest(): void
    {
        $oldestKey = null;
        $oldestTimestamp = PHP_INT_MAX;
        foreach ($this->store as $key => $session) {
            if ($session['timestamp'] < $oldestTimestamp) {
                $oldestTimestamp = $session['timestamp'];
                $oldestKey = $key;
            }
        }
        if ($oldestKey !== null) {
            unset($this->store[$oldestKey]);
        }
    }
}
