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
        dispatch($service);
        app()->make($name);
        app();
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
        $unknown = array_values(array_filter(
            $parsed->references,
            fn ($reference) => $reference->confidence === Confidence::UNKNOWN,
        ));
        $this->assertSame(
            ['event', 'app', 'resolve', 'job', 'app_make', 'method_call'],
            array_map(
                fn ($reference) => $reference->metadata['dispatch_kind']
                    ?? $reference->metadata['resolution']
                    ?? $reference->type->value,
                $unknown,
            ),
        );
        $this->assertCount(6, $unknown, 'A zero-argument app() receiver must not add its own unresolved reference.');
        foreach ($unknown as $reference) {
            $this->assertNull($reference->target);
        }
    }

    public function test_it_resolves_this_method_calls_to_the_current_class(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'this-call-php-');
        file_put_contents($file, <<<'PHP'
<?php
namespace Demo;
final class Service {
    public function run(): void { $this->charge(); }
    private function charge(): void {}
}
PHP);

        $parsed = (new PhpAstParser())->parse($file, 'Service.php');
        unlink($file);

        $calls = array_values(array_filter(
            $parsed->references,
            fn ($reference) => $reference->type === DependencyType::METHOD_CALL,
        ));
        $this->assertCount(1, $calls);
        $this->assertSame('Demo\\Service', $calls[0]->target);
        $this->assertSame('run', $calls[0]->sourceMethod);
        $this->assertSame('charge', $calls[0]->targetMethod);
        $this->assertSame(Confidence::EXACT, $calls[0]->confidence);
    }

    public function test_it_forgets_a_local_receiver_type_after_uninferrable_assignments(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'stale-receiver-php-');
        file_put_contents($file, <<<'PHP'
<?php
namespace Demo;
final class Service {
    public function run(bool $condition): void {
        $service = new PaymentService();
        $service = null;
        $service->afterNull();
        $service = new PaymentService();
        $service = factory();
        $service->afterCall();
        $service = new PaymentService();
        if ($condition) { $service = null; }
        $service->afterBranch();
        $service = new PaymentService();
        if ($condition) { $service = new AlternateService(); }
        $service->afterTypedBranch();
        $service = new PaymentService();
        (function ($service): void { $service->insideShadow(); })(null);
        $service->afterShadow();
    }
}
PHP);

        $parsed = (new PhpAstParser())->parse($file, 'Service.php');
        unlink($file);

        $calls = array_values(array_filter(
            $parsed->references,
            fn ($reference) => $reference->type === DependencyType::METHOD_CALL,
        ));
        $unknown = array_values(array_filter($calls, fn ($call) => in_array($call->targetMethod, [
            'afterNull',
            'afterCall',
            'afterBranch',
            'afterTypedBranch',
            'insideShadow',
        ], true)));
        $this->assertSame(
            ['afterNull', 'afterCall', 'afterBranch', 'afterTypedBranch', 'insideShadow'],
            array_column($unknown, 'targetMethod'),
        );
        foreach ($unknown as $call) {
            $this->assertNull($call->target);
            $this->assertSame(Confidence::UNKNOWN, $call->confidence);
        }
        $afterShadow = array_values(array_filter(
            $calls,
            fn ($call) => $call->targetMethod === 'afterShadow',
        ));
        $this->assertCount(1, $afterShadow);
        $this->assertSame('Demo\\PaymentService', $afterShadow[0]->target);
    }

    public function test_qualified_app_and_resolve_functions_are_not_treated_as_laravel_helpers(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'qualified-container-php-');
        file_put_contents($file, <<<'PHP'
<?php
namespace Demo;
final class Service {
    public function run(): void {
        Helpers\app(Target::class);
        Helpers\resolve(Target::class)->execute();
    }
}
PHP);

        $parsed = (new PhpAstParser())->parse($file, 'Service.php');
        unlink($file);

        $this->assertFalse($this->hasReference($parsed, DependencyType::INSTANTIATION, 'Demo\\Target'));
        $calls = array_values(array_filter(
            $parsed->references,
            fn ($reference) => $reference->type === DependencyType::METHOD_CALL,
        ));
        $this->assertCount(1, $calls);
        $this->assertNull($calls[0]->target);
        $this->assertSame(Confidence::UNKNOWN, $calls[0]->confidence);
    }

    public function test_uncertain_writes_invalidate_local_receiver_types_without_losing_simple_inference(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'uncertain-writes-php-');
        file_put_contents($file, <<<'PHP'
<?php
namespace Demo;
final class Service {
    public function run(array $items, bool $condition): void {
        $service = new PaymentService();
        $service->before();
        while ($condition) { $service = new AlternateService(); }
        $service->afterLoop();
        $service = new PaymentService();
        $mutate = function () use (&$service): void { $service = null; };
        $service->afterByReferenceCapture();
        $service = new PaymentService();
        $service .= 'invalidates';
        $service->afterAssignOp();
        $service = new PaymentService();
        foreach ($items as $service) { $service->insideForeach(); }
        $service->afterForeach();
        $service = new PaymentService();
        [$service] = $items;
        $service->afterDestructuring();
    }
}
PHP);

        $parsed = (new PhpAstParser())->parse($file, 'Service.php');
        unlink($file);

        $calls = array_values(array_filter(
            $parsed->references,
            fn ($reference) => $reference->type === DependencyType::METHOD_CALL,
        ));
        $this->assertSame('Demo\\PaymentService', $calls[0]->target);
        $this->assertSame('before', $calls[0]->targetMethod);
        foreach (['afterLoop', 'afterByReferenceCapture', 'afterAssignOp', 'insideForeach', 'afterForeach', 'afterDestructuring'] as $method) {
            $matching = array_values(array_filter($calls, fn ($call) => $call->targetMethod === $method));
            $this->assertCount(1, $matching, $method);
            $this->assertNull($matching[0]->target, $method);
            $this->assertSame(Confidence::UNKNOWN, $matching[0]->confidence, $method);
        }
    }

    public function test_dynamic_new_and_dynamic_facade_dispatches_are_explicitly_unresolved(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'dynamic-dispatch-php-');
        file_put_contents($file, <<<'PHP'
<?php
namespace Demo;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Bus;
final class Service {
    public function run(string $class, object $event, object $job): void {
        new $class();
        new class {};
        Event::dispatch($event);
        Bus::dispatch($job);
    }
}
PHP);

        $parsed = (new PhpAstParser(['illuminate\\support\\facades\\']))->parse($file, 'Service.php');
        unlink($file);

        $unresolved = array_values(array_filter(
            $parsed->references,
            fn ($reference) => $reference->target === null,
        ));
        $this->assertSame(
            ['instantiation', 'event', 'event'],
            array_map(fn ($reference) => $reference->type->value, $unresolved),
        );
        $this->assertSame(
            [[], ['dispatch_kind' => 'event'], ['dispatch_kind' => 'job']],
            array_column($unresolved, 'metadata'),
        );
        foreach ($unresolved as $reference) {
            $this->assertSame(Confidence::UNKNOWN, $reference->confidence);
        }
    }

    public function test_laravel_facade_dispatch_and_container_make_are_case_insensitive(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'case-laravel-php-');
        file_put_contents($file, <<<'PHP'
<?php
namespace Demo;
use illuminate\support\facades\event;
use ILLUMINATE\SUPPORT\FACADES\BUS;
final class Service {
    public function run(object $event, object $job): void {
        event::Dispatch($event);
        BUS::DISPATCH($job);
        app()->Make(Target::class);
    }
}
PHP);

        $parsed = (new PhpAstParser(['Illuminate\\Support\\Facades\\']))->parse($file, 'Service.php');
        unlink($file);

        $events = array_values(array_filter(
            $parsed->references,
            fn ($reference) => $reference->type === DependencyType::EVENT,
        ));
        $this->assertSame(
            [['dispatch_kind' => 'event'], ['dispatch_kind' => 'job']],
            array_column($events, 'metadata'),
        );
        foreach ($events as $event) {
            $this->assertNull($event->target);
            $this->assertSame(Confidence::UNKNOWN, $event->confidence);
        }
        $this->assertTrue($this->hasReference($parsed, DependencyType::INSTANTIATION, 'Demo\\Target'));
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
