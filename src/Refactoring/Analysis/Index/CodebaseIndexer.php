<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Index;

use Peralta\AgentKit\Exceptions\MissingDependencyException;
use Peralta\AgentKit\Refactoring\Analysis\Ast\AstParser;
use Peralta\AgentKit\Refactoring\Analysis\DTOs\ParseDiagnostic;
use Peralta\AgentKit\Refactoring\Analysis\DTOs\Reference;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyEdge;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyGraph;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyNode;
use Peralta\AgentKit\Refactoring\Analysis\Graph\Confidence;
use Peralta\AgentKit\Refactoring\Support\ProjectRoot;
use Peralta\AgentKit\Refactoring\Support\ProjectScanner;

final class CodebaseIndexer implements CodebaseIndexBuilder
{
    public function __construct(
        private readonly ProjectScanner $scanner,
        private readonly AstParser $parser,
    ) {}

    public function build(string $root): CodebaseIndex
    {
        $root = $this->normalizedRoot($root);
        $symbols = [];
        $classDeclarations = [];
        $diagnostics = [];
        $references = [];
        /** @var list<Reference> $unresolvedReferences */
        $unresolvedReferences = [];
        $graph = new DependencyGraph();

        foreach ($this->scanner->phpFiles($root) as $file) {
            $relative = ProjectRoot::relative($root, $file);
            try {
                $parsed = $this->parser->parse($file, $relative);
            } catch (MissingDependencyException $missing) {
                // A missing package breaks every file the same way: report it once, not per file.
                throw $missing;
            } catch (\Throwable $failure) {
                // One unreadable or unanalysable file must not abort the whole index. The
                // diagnostic keeps only basenames from the exception message and never adds
                // the exception's own file/line: absolute paths would otherwise leave the
                // process through the MCP HTTP transport.
                $diagnostics[] = new ParseDiagnostic(
                    $relative,
                    1,
                    sprintf('Analysis failed: %s: %s', $failure::class, $this->withoutAbsolutePaths($failure->getMessage())),
                );
                continue;
            }
            $diagnostics = array_merge($diagnostics, $parsed->diagnostics);
            $references = array_merge($references, $parsed->references);

            foreach ($parsed->symbols as $symbol) {
                $key = strtolower(ltrim($symbol->fqcn, '\\'));
                $classDeclarations[$key][] = $symbol;
                $symbols[$key] ??= $symbol;
            }
        }

        ksort($classDeclarations, SORT_STRING);
        foreach ($classDeclarations as $declarations) {
            if (count($declarations) !== 1) {
                $files = array_map(static fn ($declaration): string => $declaration->file, $declarations);
                $message = sprintf(
                    'Ambiguous class declaration for %s; declarations found in: %s.',
                    ltrim($declarations[0]->fqcn, '\\'),
                    implode(', ', $files),
                );
                foreach ($declarations as $declaration) {
                    $diagnostics[] = new ParseDiagnostic($declaration->file, $declaration->line, $message);
                }
                continue;
            }
            $symbol = $declarations[0];
            $graph->addNode(new DependencyNode(
                ltrim($symbol->fqcn, '\\'),
                $symbol->kind,
                $symbol->file,
                $symbol->line,
            ));
        }

        $canonicalMethod = function (string $class, ?string $method) use ($classDeclarations): ?string {
            if ($method === null) {
                return null;
            }
            $declarations = $classDeclarations[strtolower(ltrim($class, '\\'))] ?? [];
            if (count($declarations) !== 1) {
                return $method;
            }
            foreach ($declarations[0]->methods as $definition) {
                if (strcasecmp($definition['name'], $method) === 0) {
                    return $definition['name'];
                }
            }

            return $method;
        };

        foreach ($references as $reference) {
            if ($reference->target === null) {
                $unresolvedReferences[] = $reference;
                continue;
            }
            $sourceKey = strtolower(ltrim($reference->source, '\\'));
            $targetKey = strtolower(ltrim($reference->target, '\\'));
            $ambiguousSource = count($classDeclarations[$sourceKey] ?? []) > 1;
            $ambiguousTarget = count($classDeclarations[$targetKey] ?? []) > 1;
            if ($ambiguousSource || $ambiguousTarget) {
                $unresolvedReferences[] = new Reference(
                    $reference->source,
                    $reference->sourceMethod,
                    null,
                    null,
                    $reference->type,
                    Confidence::UNKNOWN,
                    $reference->file,
                    $reference->line,
                    [
                        'reason' => $ambiguousSource ? 'ambiguous_source' : 'ambiguous_target',
                        'original_target' => $reference->target,
                        'original_target_method' => $reference->targetMethod,
                        'original_type' => $reference->type->value,
                        'original_metadata' => $reference->metadata,
                    ],
                );
                continue;
            }
            $graph->addEdge(new DependencyEdge(
                ltrim($reference->source, '\\'),
                $reference->sourceMethod,
                ltrim($reference->target, '\\'),
                $canonicalMethod($reference->target, $reference->targetMethod),
                $reference->type,
                $reference->confidence,
                $reference->file,
                $reference->line,
                $reference->metadata,
            ));
        }

        ksort($symbols, SORT_STRING);
        return new CodebaseIndex($symbols, $graph, $diagnostics, $unresolvedReferences, $classDeclarations);
    }

    private function normalizedRoot(string $root): string
    {
        return ProjectRoot::normalize($root);
    }

    /** Reduces every absolute Unix or Windows path in a message to its last segment. */
    private function withoutAbsolutePaths(string $message): string
    {
        return preg_replace('~(?:[A-Za-z]:\\\\|/)(?:[^/\\\\\s]+[/\\\\])*([^/\\\\\s]+)~', '$1', $message) ?? $message;
    }
}
