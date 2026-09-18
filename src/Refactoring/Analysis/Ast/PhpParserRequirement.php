<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Ast;

use Peralta\AgentKit\Exceptions\MissingDependencyException;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

/**
 * nikic/php-parser is only suggested, so it may be missing, or be an older major version pulled in
 * by another tool. The version marker is `PhpVersion`, a 5.x-only class, because 4.18+ already ships
 * the 5.x factory methods. Both class names are parameters only so tests can stand in for the missing
 * and older-major cases.
 */
final class PhpParserRequirement
{
    public const PACKAGE = 'nikic/php-parser';

    public const FEATURE = 'The AST analysis (analyze, callers, dependencies, impact)';

    public static function assertSatisfied(
        string $factory = ParserFactory::class,
        string $version = PhpVersion::class,
    ): void {
        if (!class_exists($factory)) {
            throw MissingDependencyException::forFeature(self::FEATURE, self::PACKAGE);
        }

        if (!class_exists($version)) {
            throw new MissingDependencyException(
                self::PACKAGE,
                self::FEATURE . ' requires nikic/php-parser 5.x, but an older major version is installed. '
                . 'Upgrade it with: composer require --dev "nikic/php-parser:^5.0"',
            );
        }
    }
}
