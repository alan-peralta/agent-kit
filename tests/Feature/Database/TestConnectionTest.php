<?php

namespace Peralta\AgentKit\Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Peralta\AgentKit\Tests\TestCase;
use Peralta\AgentKit\Tests\TestDatabase;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
class TestConnectionTest extends TestCase
{
    public function test_the_default_connection_uses_the_configured_driver(): void
    {
        $this->assertSame('testing', DB::getDefaultConnection());
        $this->assertSame(TestDatabase::driver(), DB::connection()->getDriverName());
    }
}
