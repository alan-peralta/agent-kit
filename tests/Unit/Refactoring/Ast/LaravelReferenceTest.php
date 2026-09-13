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

    public function test_assignment_by_reference_invalidates_the_previous_receiver_type(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'assign-ref-php-');
        file_put_contents($file, <<<'PHP'
<?php
namespace Demo;
final class Service {
    public function run(mixed $other): void {
        $service = new PaymentService();
        $service =& $other;
        $service->charge();
        $typed = new PaymentService();
        $alias =& $typed;
        $typed->refund();
    }
}
PHP);

        $parsed = (new PhpAstParser())->parse($file, 'Service.php');
        unlink($file);

        $calls = array_values(array_filter(
            $parsed->references,
            fn ($reference) => $reference->type === DependencyType::METHOD_CALL,
        ));
        $this->assertSame(['charge', 'refund'], array_column($calls, 'targetMethod'));
        foreach ($calls as $call) {
            $this->assertNull($call->target);
            $this->assertSame(Confidence::UNKNOWN, $call->confidence);
        }
    }

    public function test_alias_and_by_reference_capture_taint_persist_after_reassignment(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'persistent-alias-php-');
        file_put_contents($file, <<<'PHP'
<?php
namespace Demo;
final class Service {
    public function run(mixed $b): void {
        $a = new PaymentService();
        $a =& $b;
        $a = new PaymentService();
        $b = new OtherService();
        $a->afterAlias();
        $b->afterAliasPeer();
        $captured = new PaymentService();
        $mutate = function () use (&$captured): void { $captured = null; };
        $captured = new PaymentService();
        $captured->afterCapture();
    }
    public function freshScope(): void {
        $a = new PaymentService();
        $a->worksInNextMethod();
    }
}
PHP);

        $parsed = (new PhpAstParser())->parse($file, 'Service.php');
        unlink($file);

        $calls = array_values(array_filter(
            $parsed->references,
            fn ($reference) => $reference->type === DependencyType::METHOD_CALL,
        ));
        foreach (['afterAlias', 'afterAliasPeer', 'afterCapture'] as $method) {
            $call = array_values(array_filter($calls, fn ($reference) => $reference->targetMethod === $method))[0];
            $this->assertNull($call->target, $method);
            $this->assertSame(Confidence::UNKNOWN, $call->confidence, $method);
        }
        $fresh = array_values(array_filter($calls, fn ($reference) => $reference->targetMethod === 'worksInNextMethod'))[0];
        $this->assertSame('Demo\\PaymentService', $fresh->target);
        $this->assertSame(Confidence::INFERRED, $fresh->confidence);
    }

    public function test_writes_in_all_uncertain_control_flow_remain_unknown_after_the_construct(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'uncertain-control-php-');
        file_put_contents($file, <<<'PHP'
<?php
namespace Demo;
final class Service {
    public function run(int $choice, bool $condition): void {
        $ternary = new PaymentService();
        $condition ? $ternary = new OtherService() : null;
        $ternary->afterTernary();
        $switch = new PaymentService();
        switch ($choice) { case 1: $switch = new OtherService(); break; default: break; }
        $switch->afterSwitch();
        $short = new PaymentService();
        $condition && ($short = new OtherService());
        $short->afterShortCircuit();
        $coalesce = new PaymentService();
        null ?? ($coalesce = new OtherService());
        $coalesce->afterCoalesce();
        $matched = new PaymentService();
        match ($choice) { 1 => $matched = new OtherService(), default => null };
        $matched->afterMatch();
        $caught = new PaymentService();
        try { risky(); } catch (\Throwable $caught) { recover(); } finally { cleanup(); }
        $caught->afterCatch();
        $tried = new PaymentService();
        try { $tried = new OtherService(); } catch (\Throwable) {}
        $tried->afterTry();
        $unaffected = new PaymentService();
        if ($condition) { $other = new OtherService(); }
        $unaffected->stillKnown();
    }
}
PHP);

        $parsed = (new PhpAstParser())->parse($file, 'Service.php');
        unlink($file);

        $calls = array_values(array_filter(
            $parsed->references,
            fn ($reference) => $reference->type === DependencyType::METHOD_CALL,
        ));
        foreach (['afterTernary', 'afterSwitch', 'afterShortCircuit', 'afterCoalesce', 'afterMatch', 'afterCatch', 'afterTry'] as $method) {
            $call = array_values(array_filter($calls, fn ($reference) => $reference->targetMethod === $method))[0];
            $this->assertNull($call->target, $method);
            $this->assertSame(Confidence::UNKNOWN, $call->confidence, $method);
        }
        $unaffected = array_values(array_filter($calls, fn ($reference) => $reference->targetMethod === 'stillKnown'))[0];
        $this->assertSame('Demo\\PaymentService', $unaffected->target);
    }

    public function test_dynamic_class_constant_references_preserve_every_known_fact(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'dynamic-constant-php-');
        file_put_contents($file, <<<'PHP'
<?php
namespace Demo;
final class Service {
    public function run(string $class, string $constant): void {
        $one = $class::VALUE;
        $two = KnownClass::{$constant};
        $three = $class::{$constant};
        $four = KnownClass::class;
    }
}
PHP);

        $parsed = (new PhpAstParser())->parse($file, 'Service.php');
        unlink($file);

        $constants = array_values(array_filter(
            $parsed->references,
            fn ($reference) => $reference->type === DependencyType::CLASS_CONSTANT,
        ));
        $this->assertCount(3, $constants);
        $this->assertNull($constants[0]->target);
        $this->assertSame(Confidence::UNKNOWN, $constants[0]->confidence);
        $this->assertSame(['constant' => 'VALUE'], $constants[0]->metadata);
        $this->assertSame('Demo\\KnownClass', $constants[1]->target);
        $this->assertSame(Confidence::EXACT, $constants[1]->confidence);
        $this->assertSame(['constant_name_confidence' => 'unknown'], $constants[1]->metadata);
        $this->assertNull($constants[2]->target);
        $this->assertSame(Confidence::UNKNOWN, $constants[2]->confidence);
        $this->assertSame(['constant_name_confidence' => 'unknown'], $constants[2]->metadata);
    }

    public function test_nullsafe_argument_and_dynamic_property_writes_do_not_leak_receiver_types(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'nullsafe-write-php-');
        file_put_contents($file, <<<'PHP'
<?php
namespace Demo;
final class Service {
    public function run(?object $nullable): void {
        $service = new PaymentService();
        $nullable?->consume($service = new OtherService());
        $service->afterNullsafe();
        $propertyService = new PaymentService();
        $nullable?->{get_debug_type($propertyService = new OtherService())};
        $propertyService->afterNullsafeProperty();
    }
}
PHP);

        $parsed = (new PhpAstParser())->parse($file, 'Service.php');
        unlink($file);

        $calls = array_values(array_filter(
            $parsed->references,
            fn ($reference) => $reference->type === DependencyType::METHOD_CALL,
        ));
        $nullsafe = array_values(array_filter($calls, fn ($reference) => $reference->targetMethod === 'consume'));
        $this->assertCount(1, $nullsafe);
        $this->assertNull($nullsafe[0]->target);
        $this->assertSame(Confidence::UNKNOWN, $nullsafe[0]->confidence);
        foreach (['afterNullsafe', 'afterNullsafeProperty'] as $method) {
            $call = array_values(array_filter($calls, fn ($reference) => $reference->targetMethod === $method))[0];
            $this->assertNull($call->target, $method);
            $this->assertSame(Confidence::UNKNOWN, $call->confidence, $method);
        }
    }

    public function test_foreach_by_reference_persistently_taints_only_the_aliased_value(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'foreach-reference-php-');
        file_put_contents($file, <<<'PHP'
<?php
namespace Demo;
final class Service {
    public function run(array $items): void {
        $service = new PaymentService();
        foreach ($items as &$service) { $service->insideAlias(); }
        $service = new PaymentService();
        $service->afterForeachAlias();
        $normal = new PaymentService();
        foreach ($items as $normal) {}
        $normal = new PaymentService();
        $normal->afterNormalForeach();
    }
}
PHP);

        $parsed = (new PhpAstParser())->parse($file, 'Service.php');
        unlink($file);

        $calls = array_values(array_filter(
            $parsed->references,
            fn ($reference) => $reference->type === DependencyType::METHOD_CALL,
        ));
        foreach (['insideAlias', 'afterForeachAlias'] as $method) {
            $call = array_values(array_filter($calls, fn ($reference) => $reference->targetMethod === $method))[0];
            $this->assertNull($call->target, $method);
            $this->assertSame(Confidence::UNKNOWN, $call->confidence, $method);
        }
        $normal = array_values(array_filter($calls, fn ($reference) => $reference->targetMethod === 'afterNormalForeach'))[0];
        $this->assertSame('Demo\\PaymentService', $normal->target);
        $this->assertSame(Confidence::INFERRED, $normal->confidence);
    }

    public function test_global_bindings_persistently_taint_static_and_dynamic_targets(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'global-reference-php-');
        file_put_contents($file, <<<'PHP'
<?php
namespace Demo;
final class Service {
    public function run(string $name): void {
        $service = new PaymentService();
        global $service;
        $service = new PaymentService();
        $service->afterGlobal();
        $other = new PaymentService();
        global $$name;
        $other = new PaymentService();
        $other->afterDynamicGlobal();
    }
    public function freshScope(): void {
        $service = new PaymentService();
        $service->afterGlobalScope();
    }
}
PHP);

        $parsed = (new PhpAstParser())->parse($file, 'Service.php');
        unlink($file);

        foreach (['afterGlobal', 'afterDynamicGlobal'] as $method) {
            $call = array_values(array_filter(
                $parsed->references,
                fn ($reference) => $reference->type === DependencyType::METHOD_CALL
                    && $reference->targetMethod === $method,
            ))[0];
            $this->assertNull($call->target, $method);
            $this->assertSame(Confidence::UNKNOWN, $call->confidence, $method);
        }
        $fresh = array_values(array_filter(
            $parsed->references,
            fn ($reference) => $reference->type === DependencyType::METHOD_CALL
                && $reference->targetMethod === 'afterGlobalScope',
        ))[0];
        $this->assertSame('Demo\\PaymentService', $fresh->target);
        $this->assertSame(Confidence::INFERRED, $fresh->confidence);
    }

    public function test_dynamic_variable_writes_invalidate_all_current_types_but_allow_later_proof(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'dynamic-write-php-');
        file_put_contents($file, <<<'PHP'
<?php
namespace Demo;
final class Service {
    public function run(string $name): void {
        $service = new PaymentService();
        $$name = new OtherService();
        $service->afterVariableVariable();
        $service = new PaymentService();
        $service->afterSequentialProof();
        $destructured = new PaymentService();
        [$$name] = [];
        $destructured->afterDynamicDestructuring();
    }
}
PHP);

        $parsed = (new PhpAstParser())->parse($file, 'Service.php');
        unlink($file);

        $calls = array_values(array_filter(
            $parsed->references,
            fn ($reference) => $reference->type === DependencyType::METHOD_CALL,
        ));
        foreach (['afterVariableVariable', 'afterDynamicDestructuring'] as $method) {
            $call = array_values(array_filter($calls, fn ($reference) => $reference->targetMethod === $method))[0];
            $this->assertNull($call->target, $method);
            $this->assertSame(Confidence::UNKNOWN, $call->confidence, $method);
        }
        $proven = array_values(array_filter($calls, fn ($reference) => $reference->targetMethod === 'afterSequentialProof'))[0];
        $this->assertSame('Demo\\PaymentService', $proven->target);
        $this->assertSame(Confidence::INFERRED, $proven->confidence);
    }

    public function test_call_arguments_are_invalidated_only_after_each_call_boundary(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'call-boundary-php-');
        file_put_contents($file, <<<'PHP'
<?php
namespace Demo;
final class Service {
    public function run(callable $callback, Mutator $mutator, ?Mutator $maybe): void {
        $function = new PaymentService();
        mutate($function);
        $function->afterFunction();
        $method = new PaymentService();
        $mutator->change(value: $method);
        $method->afterMethod();
        $static = new PaymentService();
        Mutator::change($static);
        $static->afterStatic();
        $dynamic = new PaymentService();
        $callback($dynamic);
        $dynamic->afterDynamicCallable();
        $constructor = new PaymentService();
        new Mutator($constructor);
        $constructor->afterConstructor();
        $nullsafe = new PaymentService();
        $maybe?->change($nullsafe);
        $nullsafe->afterNullsafeCall();
        $arrayLvalue = new PaymentService();
        mutate($arrayLvalue[0]);
        $arrayLvalue->afterArrayLvalue();
        $propertyLvalue = new PaymentService();
        mutate($propertyLvalue->value);
        $propertyLvalue->afterPropertyLvalue();
        $preserved = new PaymentService();
        mutateSomethingElse();
        $preserved->afterNoArgument();
        $receiver = new PaymentService();
        $receiver->touch();
        $receiver->afterReceiverOnly();
        $restored = new PaymentService();
        mutate($restored);
        $restored = new PaymentService();
        $restored->afterExplicitProof();
    }
}
PHP);

        $parsed = (new PhpAstParser())->parse($file, 'Service.php');
        unlink($file);

        $calls = array_values(array_filter(
            $parsed->references,
            fn ($reference) => $reference->type === DependencyType::METHOD_CALL,
        ));
        foreach (['afterFunction', 'afterMethod', 'afterStatic', 'afterDynamicCallable', 'afterConstructor', 'afterNullsafeCall', 'afterArrayLvalue', 'afterPropertyLvalue'] as $method) {
            $call = array_values(array_filter($calls, fn ($reference) => $reference->targetMethod === $method))[0];
            $this->assertNull($call->target, $method);
            $this->assertSame(Confidence::UNKNOWN, $call->confidence, $method);
        }
        foreach (['afterNoArgument', 'touch', 'afterReceiverOnly', 'afterExplicitProof'] as $method) {
            $call = array_values(array_filter($calls, fn ($reference) => $reference->targetMethod === $method))[0];
            $this->assertSame('Demo\\PaymentService', $call->target, $method);
            $this->assertSame(Confidence::INFERRED, $call->confidence, $method);
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
