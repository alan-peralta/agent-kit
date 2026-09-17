<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Ast;

use Peralta\AgentKit\Refactoring\Analysis\Ast\PhpAstParser;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhpAstParserTest extends TestCase
{
    public function test_it_extracts_normalized_declarations_members_and_types(): void
    {
        $file = dirname(__DIR__, 3) . '/Fixtures/Refactoring/Ast/PaymentService.php';
        $parsed = (new PhpAstParser())->parse($file, 'PaymentService.php');

        $this->assertSame([], $parsed->diagnostics);
        $this->assertSame('Fixtures\\Payments', $parsed->namespace);
        $this->assertContains([
            'name' => 'Fixtures\\Contracts\\Auditor',
            'alias' => 'PaymentAuditor',
            'type' => 'class',
            'line' => 6,
        ], $parsed->imports);
        $this->assertCount(1, $parsed->symbols);
        $symbol = $parsed->symbols[0];
        $this->assertSame('Fixtures\\Payments\\PaymentService', $symbol->fqcn);
        $this->assertSame('class', $symbol->kind);
        $this->assertSame(['__construct', 'charge'], array_column($symbol->methods, 'name'));
        $this->assertSame(['auditor', 'lastReceipt'], array_column($symbol->properties, 'name'));
        $this->assertContains('CURRENCY', array_column($symbol->constants, 'name'));
        $this->assertContains('Attribute', $symbol->attributes);

        $this->assertTrue($this->has($parsed->references, DependencyType::IMPLEMENTS, 'Fixtures\\Payments\\PaymentGateway'));
        $this->assertTrue($this->has($parsed->references, DependencyType::TRAIT, 'Fixtures\\Payments\\LogsPayments'));
        $this->assertTrue($this->has($parsed->references, DependencyType::CONSTRUCTOR_INJECTION, 'Fixtures\\Contracts\\Auditor'));
        $this->assertTrue($this->has($parsed->references, DependencyType::PROPERTY_TYPE, 'Fixtures\\Payments\\Receipt'));
        $this->assertTrue($this->has($parsed->references, DependencyType::METHOD_PARAMETER, 'Fixtures\\Payments\\PaymentGateway'));
        $this->assertTrue($this->has($parsed->references, DependencyType::RETURN_TYPE, 'Fixtures\\Payments\\Receipt'));
        $this->assertTrue($this->has($parsed->references, DependencyType::RETURN_TYPE, 'Fixtures\\Payments\\Failure'));
        $this->assertTrue($this->has($parsed->references, DependencyType::INSTANTIATION, 'Fixtures\\Payments\\Receipt'));
        $this->assertTrue($this->has($parsed->references, DependencyType::ATTRIBUTE, 'Attribute'));
        $this->assertGreaterThan(0, $parsed->references[0]->line);
        $this->assertSame('PaymentService.php', $parsed->references[0]->file);

        $charge = array_values(array_filter($symbol->methods, fn ($method) => $method['name'] === 'charge'))[0];
        $this->assertSame(['Fixtures\\Payments\\Receipt', 'Fixtures\\Payments\\Failure'], $charge['return_types']);
        $gateway = array_values(array_filter($charge['parameters'], fn ($parameter) => $parameter['name'] === 'gateway'))[0];
        $this->assertSame(['Fixtures\\Payments\\PaymentGateway', 'Fixtures\\Contracts\\Auditor'], $gateway['types']);
        $auditor = array_values(array_filter($symbol->properties, fn ($property) => $property['name'] === 'auditor'))[0];
        $this->assertSame(['Fixtures\\Contracts\\Auditor'], $auditor['types']);
    }

    public function test_it_returns_a_diagnostic_for_invalid_php(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'invalid-php-');
        file_put_contents($file, '<?php class Broken {');

        $parsed = (new PhpAstParser())->parse($file, 'Broken.php');

        unlink($file);
        $this->assertSame([], $parsed->symbols);
        $this->assertCount(1, $parsed->diagnostics);
        $this->assertSame('Broken.php', $parsed->diagnostics[0]->file);
        $this->assertGreaterThan(0, $parsed->diagnostics[0]->line);
    }

    public function test_it_resolves_self_static_parent_and_enum_declarations(): void
    {
        $file = dirname(__DIR__, 3) . '/Fixtures/Refactoring/Ast/StructuralTypes.php';
        $parsed = (new PhpAstParser())->parse($file, 'StructuralTypes.php');

        $symbols = [];
        foreach ($parsed->symbols as $symbol) {
            $symbols[$symbol->fqcn] = $symbol;
        }

        $this->assertSame('enum', $symbols['Fixtures\\Payments\\PaymentStatus']->kind);
        $this->assertTrue($this->hasFrom(
            $parsed->references,
            'Fixtures\\Payments\\AdvancedPaymentGateway',
            DependencyType::EXTENDS,
            'Fixtures\\Payments\\PaymentGateway',
        ));
        $this->assertTrue($this->has($parsed->references, DependencyType::EXTENDS, 'Fixtures\\Payments\\BasePaymentService'));
        $this->assertTrue($this->has($parsed->references, DependencyType::METHOD_PARAMETER, 'Fixtures\\Payments\\ExtendedPaymentService'));
        $this->assertTrue($this->has($parsed->references, DependencyType::METHOD_PARAMETER, 'Fixtures\\Payments\\BasePaymentService'));
        $this->assertTrue($this->has($parsed->references, DependencyType::RETURN_TYPE, 'Fixtures\\Payments\\ExtendedPaymentService'));
        $this->assertTrue($this->has($parsed->references, DependencyType::CLASS_CONSTANT, 'Fixtures\\Payments\\ExtendedPaymentService'));
        $this->assertTrue($this->has($parsed->references, DependencyType::CLASS_CONSTANT, 'Fixtures\\Payments\\BasePaymentService'));
        $this->assertTrue($this->has($parsed->references, DependencyType::STATIC_CALL, 'Fixtures\\Payments\\ExtendedPaymentService'));
    }

    public function test_it_infers_properties_declared_after_the_calling_method(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'out-of-order-php-');
        file_put_contents($file, <<<'PHP'
<?php
namespace Demo;
class LaterProperties {
    public function run(): void {
        $this->service->charge();
        $this->promoted->charge();
    }
    private PaymentService $service;
    public function __construct(private PaymentService $promoted) {}
}
PHP);

        $parsed = (new PhpAstParser())->parse($file, 'LaterProperties.php');
        unlink($file);

        $calls = array_filter(
            $parsed->references,
            fn ($reference) => $reference->type === DependencyType::METHOD_CALL
                && $reference->target === 'Demo\\PaymentService',
        );
        $this->assertCount(2, $calls);
    }

    public function test_it_does_not_guess_a_receiver_from_multiple_declared_types(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'union-receiver-php-');
        file_put_contents($file, <<<'PHP'
<?php
namespace Demo;
class UnionReceiver {
    public function run(FirstService|SecondService $service): void {
        $service->execute();
    }
}
PHP);

        $parsed = (new PhpAstParser())->parse($file, 'UnionReceiver.php');
        unlink($file);

        $calls = array_values(array_filter(
            $parsed->references,
            fn ($reference) => $reference->type === DependencyType::METHOD_CALL,
        ));
        $this->assertCount(1, $calls);
        $this->assertNull($calls[0]->target);
        $this->assertSame('unknown', $calls[0]->confidence->value);
    }

    #[DataProvider('proceduralCode')]
    public function test_it_parses_code_outside_classes_without_scope_errors(string $code, ?string $expectedClass): void
    {
        $file = tempnam(sys_get_temp_dir(), 'procedural-php-');
        file_put_contents($file, $code);
        // Laravel converts warnings into ErrorException at runtime; mirror that so a
        // scope-stack underflow ("array offset on null") fails here instead of passing silently.
        set_error_handler(static function (int $severity, string $message, string $filename, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $filename, $line);
        });

        try {
            $parsed = (new PhpAstParser())->parse($file, 'procedural.php');
        } finally {
            restore_error_handler();
            unlink($file);
        }

        $this->assertSame([], $parsed->diagnostics);
        if ($expectedClass === null) {
            $this->assertSame([], $parsed->symbols);

            return;
        }

        $symbols = [];
        foreach ($parsed->symbols as $symbol) {
            $symbols[$symbol->fqcn] = $symbol;
        }
        $this->assertArrayHasKey($expectedClass, $symbols);
        $this->assertTrue($this->hasFrom($parsed->references, $expectedClass, DependencyType::METHOD_CALL, 'Demo\\Service'));
    }

    public static function proceduralCode(): array
    {
        $class = <<<'PHP'

class Service { public function run(): void {} }
class Caller {
    public function __construct(private Service $service) {}
    public function go(): void { if ($this->service) { $this->service->run(); } }
}
PHP;

        return [
            'top-level closure' => ["<?php\n\$app = function (\$request) { return \$request; };", null],
            'top-level arrow function' => ["<?php\n\$double = fn (\$value) => \$value * 2;", null],
            'top-level if' => ["<?php\nif (\$argc > 1) { \$mode = 'verbose'; } else { \$mode = 'quiet'; }", null],
            'top-level ternary and coalesce' => ["<?php\nreturn ['debug' => \$env ?? false, 'url' => \$secure ? 'https' : 'http'];", null],
            'top-level match, try and loops' => ["<?php\ntry { foreach ([1] as \$i) { \$x = match (\$i) { 1 => 'a', default => 'b' }; } } catch (\\Throwable \$e) { while (false) {} }", null],
            'top-level function with closure and if' => ["<?php\nfunction bootstrap(array \$config) { \$factory = static function () use (\$config) { return \$config; }; if (\$config) { return \$factory(); } return null; }", null],
            'closure returning an anonymous class' => ["<?php\nreturn new class { public function up(): void { \$now = time(); if (\$now > 0) { \$fn = fn () => \$now; } } };", null],
            'routes file with static calls' => ["<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('/', function () { return view('welcome'); });\nRoute::middleware(['auth'])->group(function () { Route::get('/home', fn () => 'home'); });", null],
            'class declared after procedural code' => ["<?php\nnamespace Demo;\n\$boot = function () { return \$_ENV['x'] ?? null; };\nif (\$boot) { \$boot(); }\n" . $class, 'Demo\\Caller'],
            'class declared before procedural code' => ["<?php\nnamespace Demo;" . $class . "\n\$caller = \$argc ? new Caller(new Service()) : null;\n\$caller?->go();", 'Demo\\Caller'],
        ];
    }

    private function has(array $references, DependencyType $type, string $target): bool
    {
        foreach ($references as $reference) {
            if ($reference->type === $type && $reference->target === $target) {
                return true;
            }
        }

        return false;
    }

    private function hasFrom(array $references, string $source, DependencyType $type, string $target): bool
    {
        foreach ($references as $reference) {
            if ($reference->source === $source && $reference->type === $type && $reference->target === $target) {
                return true;
            }
        }

        return false;
    }
}
