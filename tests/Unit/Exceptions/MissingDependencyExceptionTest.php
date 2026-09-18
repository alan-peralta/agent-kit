<?php

namespace Peralta\AgentKit\Tests\Unit\Exceptions;

use Peralta\AgentKit\Exceptions\MissingDependencyException;
use PHPUnit\Framework\TestCase;

final class MissingDependencyExceptionTest extends TestCase
{
    public function test_for_feature_names_the_package_and_the_dev_install_command(): void
    {
        $exception = MissingDependencyException::forFeature('The MCP server', 'mcp/sdk');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertSame('mcp/sdk', $exception->package);
        $this->assertSame(
            'The MCP server requires mcp/sdk. Install it with: composer require --dev mcp/sdk',
            $exception->getMessage(),
        );
    }
}
