<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Ast;

use Peralta\AgentKit\Refactoring\Analysis\Ast\PhpAstParser;
use Peralta\AgentKit\Refactoring\Analysis\DTOs\ParsedFile;
use Peralta\AgentKit\Refactoring\Analysis\Graph\Confidence;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyType;
use PHPUnit\Framework\TestCase;

final class LaravelReferenceTest extends TestCase
{
    public function test_it_extracts_inferred_calls_and_laravel_relationships(): void
    {
        $parsed = $this->parseCheckout();
        $calls = array_values(array_filter(
            $parsed->references,
            fn ($reference) => $reference->type === DependencyType::METHOD_CALL,
        ));

        $paymentCalls = array_filter(
            $calls,
            fn ($call) => $call->target === 'Fixtures\\Payments\\PaymentService'
                && $call->targetMethod === 'charge'
                && $call->confidence === Confidence::INFERRED,
        );

        $this->assertCount(6, $paymentCalls);
        $this->assertTrue($this->hasReference($parsed, DependencyType::STATIC_CALL, 'Fixtures\\Payments\\PaymentService', 'status'));
        $this->assertTrue($this->hasReference($parsed, DependencyType::CLASS_CONSTANT, 'Fixtures\\Payments\\PaymentService'));
        $this->assertTrue($this->hasReference($parsed, DependencyType::EVENT, 'Fixtures\\Payments\\PaymentApproved'));
        $this->assertTrue($this->hasReference($parsed, DependencyType::EVENT, 'Fixtures\\Payments\\ProcessPayment'));
        $this->assertTrue($this->hasReference($parsed, DependencyType::STATIC_CALL, 'Fixtures\\Payments\\ProcessPayment', 'dispatch'));
        $this->assertTrue($this->hasReference($parsed, DependencyType::FACADE, 'Illuminate\\Support\\Facades\\Event', 'dispatch'));
        $this->assertTrue($this->hasReference($parsed, DependencyType::FACADE, 'Illuminate\\Support\\Facades\\Bus', 'dispatch'));
        $this->assertTrue($this->hasReference($parsed, DependencyType::FACADE, 'Illuminate\\Support\\Facades\\Log', 'info'));

        $unknown = array_values(array_filter(
            $calls,
            fn ($call) => $call->confidence === Confidence::UNKNOWN,
        ));
        $this->assertCount(1, $unknown);
        $this->assertNull($unknown[0]->target);
    }

    public function test_it_does_not_invent_targets_for_dynamic_laravel_arguments(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'dynamic-php-');
        file_put_contents($file, <<<'PHP'
<?php
namespace Dynamic;
final class Example {
    public function run(string $name, object $service): void {
        event($name);
        app($name);
        resolve($name);
        Helpers\event(new \stdClass());
        $service->execute();
    }
}
PHP);

        $parsed = (new PhpAstParser())->parse($file, 'Dynamic.php');
        unlink($file);

        $targets = array_filter(array_map(fn ($reference) => $reference->target, $parsed->references));
        $this->assertNotContains('name', $targets);
        $this->assertNotContains('service', $targets);
        $this->assertFalse($this->hasReference($parsed, DependencyType::EVENT, 'stdClass'));
        $unknown = array_filter($parsed->references, fn ($reference) => $reference->confidence === Confidence::UNKNOWN);
        $this->assertNotEmpty($unknown);
    }

    private function parseCheckout(): ParsedFile
    {
        $file = dirname(__DIR__, 3) . '/Fixtures/Refactoring/Ast/CheckoutService.php';

        return (new PhpAstParser())->parse($file, 'CheckoutService.php');
    }

    private function hasReference(
        ParsedFile $parsed,
        DependencyType $type,
        string $target,
        ?string $method = null,
    ): bool {
        foreach ($parsed->references as $reference) {
            if ($reference->type === $type
                && $reference->target === $target
                && ($method === null || $reference->targetMethod === $method)) {
                return true;
            }
        }

        return false;
    }
}
