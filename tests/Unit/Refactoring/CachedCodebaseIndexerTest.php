<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring;

use Peralta\AgentKit\Refactoring\Analysis\Ast\PhpAstParser;
use Peralta\AgentKit\Refactoring\Analysis\Index\CachedCodebaseIndexer;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndex;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexBuilder;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexer;
use Peralta\AgentKit\Refactoring\Analysis\Index\ProjectFingerprint;
use Peralta\AgentKit\Refactoring\Support\PhpFileAnalyzer;
use Peralta\AgentKit\Refactoring\Support\ProjectScanner;
use PHPUnit\Framework\TestCase;

final class CachedCodebaseIndexerTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->directories) as $directory) {
            foreach (glob($directory . '/*.php') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
        parent::tearDown();
    }

    public function test_an_unchanged_project_reuses_the_same_index_instance(): void
    {
        $root = $this->project(['Service.php' => '<?php namespace Demo; class Service {}']);
        $inner = $this->countingBuilder();
        $cache = $this->cache($inner);

        $first = $cache->build($root);
        $second = $cache->build($root);

        self::assertSame($first, $second);
        self::assertSame(1, $inner->builds);
        self::assertSame(1, $cache->count());
    }

    public function test_a_same_size_content_change_invalidates_the_index(): void
    {
        $root = $this->project(['Service.php' => '<?php namespace Demo; class Servica {}']);
        $inner = $this->countingBuilder();
        $cache = $this->cache($inner);
        $cache->build($root);

        // Same byte length and same second: only a content fingerprint can notice this.
        file_put_contents($root . '/Service.php', '<?php namespace Demo; class Service {}');
        $index = $cache->build($root);

        self::assertSame(2, $inner->builds);
        self::assertNotNull($index->findClass('Demo\\Service'));
        self::assertNull($index->findClass('Demo\\Servica'));
    }

    public function test_created_and_removed_files_invalidate_the_index(): void
    {
        $root = $this->project(['Service.php' => '<?php namespace Demo; class Service {}']);
        $inner = $this->countingBuilder();
        $cache = $this->cache($inner);
        $cache->build($root);

        file_put_contents($root . '/Extra.php', '<?php namespace Demo; class Extra {}');
        $withExtra = $cache->build($root);
        self::assertSame(2, $inner->builds);
        self::assertNotNull($withExtra->findClass('Demo\\Extra'));

        unlink($root . '/Extra.php');
        $withoutExtra = $cache->build($root);
        self::assertSame(3, $inner->builds);
        self::assertNull($withoutExtra->findClass('Demo\\Extra'));
    }

    public function test_different_roots_never_share_entries(): void
    {
        $first = $this->project(['A.php' => '<?php namespace Demo; class A {}']);
        $second = $this->project(['B.php' => '<?php namespace Demo; class B {}']);
        $cache = $this->cache($this->countingBuilder(), 2);

        $firstIndex = $cache->build($first);
        $secondIndex = $cache->build($second);

        self::assertNotNull($firstIndex->findClass('Demo\\A'));
        self::assertNull($firstIndex->findClass('Demo\\B'));
        self::assertNotNull($secondIndex->findClass('Demo\\B'));
        self::assertSame(2, $cache->count());
    }

    public function test_the_entry_bound_evicts_the_least_recently_used_root(): void
    {
        $first = $this->project(['A.php' => '<?php namespace Demo; class A {}']);
        $second = $this->project(['B.php' => '<?php namespace Demo; class B {}']);
        $inner = $this->countingBuilder();
        $cache = $this->cache($inner, 1);

        $cache->build($first);
        $cache->build($second);
        self::assertSame(1, $cache->count());
        $cache->build($first);

        self::assertSame(3, $inner->builds);
    }

    public function test_a_rebuilt_index_equals_a_fresh_index(): void
    {
        $root = $this->project([
            'Service.php' => '<?php namespace Demo; class Service { public function run(): void {} }',
            'Caller.php' => '<?php namespace Demo; class Caller { public function __construct(private Service $s) {} public function go(): void { $this->s->run(); } }',
        ]);
        $cache = $this->cache($this->countingBuilder());
        $cache->build($root);
        file_put_contents($root . '/Other.php', '<?php namespace Demo; class Other {}');

        $cached = $cache->build($root);
        $fresh = $this->realIndexer()->build($root);

        self::assertEquals($fresh, $cached);
    }

    public function test_clear_forgets_every_entry(): void
    {
        $root = $this->project(['A.php' => '<?php namespace Demo; class A {}']);
        $inner = $this->countingBuilder();
        $cache = $this->cache($inner);
        $cache->build($root);

        $cache->clear();
        $cache->build($root);

        self::assertSame(2, $inner->builds);
    }

    public function test_it_rejects_a_zero_entry_bound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cache($this->countingBuilder(), 0);
    }

    private function cache(CodebaseIndexBuilder $inner, int $maxEntries = 1): CachedCodebaseIndexer
    {
        return new CachedCodebaseIndexer($inner, new ProjectFingerprint($this->scanner()), $maxEntries);
    }

    private function realIndexer(): CodebaseIndexer
    {
        return new CodebaseIndexer($this->scanner(), new PhpAstParser());
    }

    private function scanner(): ProjectScanner
    {
        return new ProjectScanner(new PhpFileAnalyzer());
    }

    /** @return CodebaseIndexBuilder&object{builds: int} */
    private function countingBuilder(): CodebaseIndexBuilder
    {
        return new class($this->realIndexer()) implements CodebaseIndexBuilder {
            public int $builds = 0;

            public function __construct(private readonly CodebaseIndexBuilder $inner) {}

            public function build(string $root): CodebaseIndex
            {
                $this->builds++;

                return $this->inner->build($root);
            }
        };
    }

    /** @param array<string, string> $files */
    private function project(array $files): string
    {
        $directory = sys_get_temp_dir() . '/agent-kit-index-cache-' . bin2hex(random_bytes(6));
        mkdir($directory, 0777, true);
        $this->directories[] = $directory;
        foreach ($files as $name => $contents) {
            file_put_contents($directory . '/' . $name, $contents);
        }

        return $directory;
    }
}
