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
}
