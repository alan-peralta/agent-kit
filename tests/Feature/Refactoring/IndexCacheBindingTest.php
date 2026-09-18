<?php

namespace Peralta\AgentKit\Tests\Feature\Refactoring;

use Peralta\AgentKit\Refactoring\Analysis\Index\CachedCodebaseIndexer;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexBuilder;
use Peralta\AgentKit\Refactoring\Application\DefaultRefactoringCapabilities;
use Peralta\AgentKit\Tests\TestCase;
use ReflectionClass;

final class IndexCacheBindingTest extends TestCase
{
    public function test_the_index_builder_is_a_process_wide_cached_singleton(): void
    {
        $first = $this->app->make(CodebaseIndexBuilder::class);
        $second = $this->app->make(CodebaseIndexBuilder::class);

        self::assertInstanceOf(CachedCodebaseIndexer::class, $first);
        self::assertSame($first, $second);
    }

    public function test_capabilities_depend_on_the_builder_interface(): void
    {
        $parameters = (new ReflectionClass(DefaultRefactoringCapabilities::class))->getConstructor()->getParameters();
        $types = array_map(fn ($parameter) => $parameter->getType()?->getName(), $parameters);

        self::assertContains(CodebaseIndexBuilder::class, $types);
    }
}
