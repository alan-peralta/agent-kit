<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring;

use Peralta\AgentKit\Refactoring\Support\PhpFileAnalyzer;
use PHPUnit\Framework\TestCase;

class PhpFileAnalyzerTest extends TestCase
{
    public function test_it_collects_deterministic_php_metrics_and_smells(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'agent-kit-refactor-');
        file_put_contents($file, <<<'PHP'
<?php
use Foo\A;
use Foo\B;
class Example {
    public function one() { if (true) { return 1; } }
    public function two() { return 2; }
}
PHP);

        $analyzer = new PhpFileAnalyzer([
            'large_class_lines' => 5,
            'many_methods' => 2,
            'many_dependencies' => 2,
            'high_branching' => 1,
        ]);

        $result = $analyzer->analyze($file, 'Example.php');
        @unlink($file);

        $this->assertSame('Example.php', $result->path);
        $this->assertSame(2, $result->methods);
        $this->assertSame(2, $result->dependencies);
        $this->assertSame(1, $result->branches);
        $this->assertCount(4, $result->smells);
    }
}
