<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Index;

use Peralta\AgentKit\Refactoring\Analysis\Ast\AstParser;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyEdge;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyGraph;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyNode;
use Peralta\AgentKit\Refactoring\Support\ProjectScanner;

final class CodebaseIndexer
{
    public function __construct(
        private readonly ProjectScanner $scanner,
        private readonly AstParser $parser,
    ) {}

    public function build(string $root): CodebaseIndex
    {
        $root = rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR);
        $symbols = [];
        $diagnostics = [];
        $references = [];
        $unresolved = [];
        $graph = new DependencyGraph();

        foreach ($this->scanner->phpFiles($root) as $file) {
            $relative = str_replace('\\', '/', ltrim(str_replace($root, '', $file), DIRECTORY_SEPARATOR));
            $parsed = $this->parser->parse($file, $relative);
            $diagnostics = array_merge($diagnostics, $parsed->diagnostics);
            $references = array_merge($references, $parsed->references);

            foreach ($parsed->symbols as $symbol) {
                $symbols[ltrim($symbol->fqcn, '\\')] = $symbol;
                $graph->addNode(new DependencyNode(
                    ltrim($symbol->fqcn, '\\'),
                    $symbol->kind,
                    $symbol->file,
                    $symbol->line,
                ));
            }
        }

        foreach ($references as $reference) {
            if ($reference->target === null) {
                $unresolved[] = $reference;
                continue;
            }
            $graph->addEdge(new DependencyEdge(
                ltrim($reference->source, '\\'),
                $reference->sourceMethod,
                ltrim($reference->target, '\\'),
                $reference->targetMethod,
                $reference->type,
                $reference->confidence,
                $reference->file,
                $reference->line,
                $reference->metadata,
            ));
        }

        ksort($symbols, SORT_STRING);

        return new CodebaseIndex($symbols, $graph, $diagnostics, $unresolved);
    }
}
