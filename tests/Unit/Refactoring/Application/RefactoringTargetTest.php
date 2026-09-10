<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Application;

use InvalidArgumentException;
use Peralta\AgentKit\Refactoring\Application\RefactoringTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefactoringTargetTest extends TestCase
{
    public function test_it_parses_a_class_and_removes_its_leading_backslash(): void
    {
        $target = RefactoringTarget::parse('  \\App\\Service  ');

        self::assertSame('App\\Service', $target->value);
        self::assertNull($target->method);
    }

    public function test_it_parses_a_class_method_target(): void
    {
        $target = RefactoringTarget::parse(' App\\Service:: run ');

        self::assertSame('App\\Service', $target->value);
        self::assertSame('run', $target->method);
    }

    #[DataProvider('malformedTargets')]
    public function test_it_rejects_malformed_targets(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        RefactoringTarget::parse($value);
    }

    public static function malformedTargets(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'empty class' => ['::run'],
            'empty method' => ['App\\Service::'],
            'multiple separators' => ['App\\Service::run::again'],
        ];
    }
}
