<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Application;

use Peralta\AgentKit\Refactoring\Application\CapabilityException;
use Peralta\AgentKit\Refactoring\Application\CapabilityResult;
use PHPUnit\Framework\TestCase;

final class CapabilityResultTest extends TestCase
{
    public function test_it_serializes_an_incomplete_capability_result(): void
    {
        $diagnostic = ['file' => 'app/Service.php', 'message' => 'Could not parse file.'];
        $unresolved = ['symbol' => 'Vendor\\Missing'];

        $result = new CapabilityResult(
            capability: 'analyze',
            data: ['target' => 'App\\Service'],
            diagnostics: [$diagnostic],
            unresolved: [$unresolved],
        );

        self::assertTrue($result->incomplete());
        self::assertSame([
            'schema_version' => '1.0',
            'capability' => 'analyze',
            'incomplete' => true,
            'data' => ['target' => 'App\\Service'],
            'diagnostics' => [$diagnostic],
            'unresolved' => [$unresolved],
        ], $result->toArray());
    }

    public function test_it_serializes_a_complete_capability_result(): void
    {
        $result = new CapabilityResult('audit', ['files' => 3]);

        self::assertFalse($result->incomplete());
        self::assertSame([
            'schema_version' => '1.0',
            'capability' => 'audit',
            'incomplete' => false,
            'data' => ['files' => 3],
            'diagnostics' => [],
            'unresolved' => [],
        ], $result->toArray());
    }

    public function test_it_normalizes_entries_that_expose_to_array_without_changing_array_entries(): void
    {
        $objectEntry = new class {
            public function toArray(): array
            {
                return ['symbol' => 'App\\Service'];
            }
        };
        $arrayEntry = ['message' => 'Already normalized'];

        $result = new CapabilityResult(
            capability: 'analyze',
            data: [$objectEntry, $arrayEntry],
            diagnostics: [$objectEntry, $arrayEntry],
            unresolved: [$objectEntry, $arrayEntry],
        );

        self::assertSame([
            ['symbol' => 'App\\Service'],
            $arrayEntry,
        ], $result->toArray()['data']);
        self::assertSame([
            ['symbol' => 'App\\Service'],
            $arrayEntry,
        ], $result->toArray()['diagnostics']);
        self::assertSame([
            ['symbol' => 'App\\Service'],
            $arrayEntry,
        ], $result->toArray()['unresolved']);
    }

    public function test_capability_exception_has_a_stable_error_envelope(): void
    {
        $exception = new CapabilityException('invalid_target', 'The target is invalid.');

        self::assertSame('invalid_target', $exception->errorCode);
        self::assertSame('The target is invalid.', $exception->getMessage());
        self::assertSame([
            'schema_version' => '1.0',
            'error' => [
                'code' => 'invalid_target',
                'message' => 'The target is invalid.',
            ],
        ], $exception->toArray());
    }
}
