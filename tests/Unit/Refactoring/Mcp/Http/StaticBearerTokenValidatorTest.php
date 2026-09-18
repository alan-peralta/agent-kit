<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp\Http;

use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\StaticBearerTokenValidator;
use PHPUnit\Framework\TestCase;

final class StaticBearerTokenValidatorTest extends TestCase
{
    private const TOKEN = 'test-token-0123456789abcdef0123456789abcdef';

    public function test_the_exact_token_is_allowed(): void
    {
        self::assertTrue((new StaticBearerTokenValidator(self::TOKEN))->validate(self::TOKEN)->isAllowed());
    }

    public function test_other_tokens_are_unauthorized_without_echoing_them(): void
    {
        $result = (new StaticBearerTokenValidator(self::TOKEN))->validate(self::TOKEN . 'x');

        self::assertFalse($result->isAllowed());
        self::assertSame(401, $result->getStatusCode());
        self::assertSame('invalid_token', $result->getError());
        self::assertStringNotContainsString(self::TOKEN, (string) $result->getErrorDescription());
    }

    public function test_an_empty_expected_token_is_rejected_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StaticBearerTokenValidator('');
    }
}
