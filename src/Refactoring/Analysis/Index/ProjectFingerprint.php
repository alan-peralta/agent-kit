<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Index;

use Peralta\AgentKit\Refactoring\Support\ProjectRoot;
use Peralta\AgentKit\Refactoring\Support\ProjectScanner;

final class ProjectFingerprint
{
    public function __construct(private readonly ProjectScanner $scanner) {}

    /**
     * Content fingerprint of every PHP file the indexer would parse. Content hashes
     * (not mtimes) are used on purpose: two edits inside the same second, or a
     * restored file with the same size, must still invalidate the cache.
     */
    public function compute(string $root): string
    {
        $root = ProjectRoot::normalize($root);
        $context = hash_init('xxh128');

        foreach ($this->scanner->phpFiles($root) as $file) {
            $size = is_file($file) ? filesize($file) : false;
            $digest = is_file($file) ? hash_file('xxh128', $file) : false;
            hash_update($context, implode("\0", [
                ProjectRoot::relative($root, $file),
                $size === false ? 'missing' : (string) $size,
                $digest === false ? 'missing' : $digest,
            ]) . "\n");
        }

        return hash_final($context);
    }
}
