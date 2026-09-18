<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Ast;

use Peralta\AgentKit\Exceptions\MissingDependencyException;
use Peralta\AgentKit\Refactoring\Analysis\Ast\PhpParserRequirement;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PHPUnit\Framework\TestCase;

final class PhpParserRequirementTest extends TestCase
{
    public function test_the_installed_php_parser_5_satisfies_it(): void
    {
        PhpParserRequirement::assertSatisfied();
        PhpParserRequirement::assertSatisfied(ParserFactory::class, PhpVersion::class);

        $this->addToAssertionCount(1);
    }

    public function test_a_missing_parser_names_the_package_to_install(): void
    {
        try {
            PhpParserRequirement::assertSatisfied('Peralta\\AgentKit\\Tests\\Missing\\ParserFactory');
            $this->fail('Expected a missing dependency.');
        } catch (MissingDependencyException $exception) {
            $this->assertSame('nikic/php-parser', $exception->package);
            $this->assertSame(
                'The AST analysis (analyze, callers, dependencies, impact) requires nikic/php-parser. Install it with: composer require --dev nikic/php-parser',
                $exception->getMessage(),
            );
        }
    }

    public function test_an_older_major_version_asks_for_an_upgrade(): void
    {
        // php-parser 4.18+ already has the 5.x factory methods, but no PhpVersion class.
        try {
            PhpParserRequirement::assertSatisfied(ParserFactory::class, 'Peralta\\AgentKit\\Tests\\Missing\\PhpVersion');
            $this->fail('Expected a missing dependency.');
        } catch (MissingDependencyException $exception) {
            $this->assertSame('nikic/php-parser', $exception->package);
            $this->assertSame(
                'The AST analysis (analyze, callers, dependencies, impact) requires nikic/php-parser 5.x, but an older major version is installed. Upgrade it with: composer require --dev "nikic/php-parser:^5.0"',
                $exception->getMessage(),
            );
        }
    }
}
