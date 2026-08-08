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
