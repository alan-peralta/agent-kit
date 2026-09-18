<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Ast;

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Peralta\AgentKit\Refactoring\Analysis\DTOs\ParseDiagnostic;
use Peralta\AgentKit\Refactoring\Analysis\DTOs\ParsedFile;

final class PhpAstParser implements AstParser
{
    // Created on the first parse: resolving the refactoring services (the audit included) must
    // not need nikic/php-parser, which Agent Kit only suggests.
    private ?Parser $parser = null;

    public function __construct(private readonly array $facadePrefixes = ['Illuminate\\Support\\Facades\\']) {}

    public function parse(string $file, ?string $displayPath = null): ParsedFile
    {
        $parser = $this->parser();

        $code = file_get_contents($file);
        if ($code === false) {
            throw new \RuntimeException("Não foi possível ler {$file}.");
        }

        $path = $displayPath ?? $file;

        try {
            $nodes = $parser->parse($code) ?? [];
        } catch (Error $error) {
            return new ParsedFile($path, diagnostics: [
                new ParseDiagnostic($path, max(1, $error->getStartLine()), $error->getRawMessage()),
            ]);
        }

        $names = new NodeTraverser();
        $names->addVisitor(new NameResolver());
        $nodes = $names->traverse($nodes);

        $collector = new StructureCollector($path, $this->facadePrefixes);
        $structure = new NodeTraverser();
        $structure->addVisitor($collector);
        $structure->traverse($nodes);

        return new ParsedFile(
            $path,
            $collector->symbols(),
            $collector->references(),
            namespace: $collector->namespace(),
            imports: $collector->imports(),
        );
    }

    private function parser(): Parser
    {
        if ($this->parser === null) {
            PhpParserRequirement::assertSatisfied();
            $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        }

        return $this->parser;
    }
}
