<?php

namespace Peralta\AgentKit\Tests;

use Illuminate\Support\Facades\Schema;
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
        $app['config']->set('database.connections.testing', TestDatabase::connection());
        // Never share index snapshots between tests: a fake parser in one test must not feed
        // another. Tests that exercise IndexSnapshotStore pass a temp directory explicitly.
        $app['config']->set('agent-kit.mcp.index_cache.path', false);
    }

    protected function tearDown(): void
    {
        // A server database outlives the test, unlike SQLite in memory: drop what the test created.
        if (TestDatabase::usesServer() && $this->app !== null) {
            Schema::dropAllTables();
        }

        parent::tearDown();
    }
}
