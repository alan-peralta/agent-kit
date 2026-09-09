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
    private readonly Parser $parser;

    public function __construct(private readonly array $facadePrefixes = ['Illuminate\\Support\\Facades\\'])
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function parse(string $file, ?string $displayPath = null): ParsedFile
    {
        $code = file_get_contents($file);
        if ($code === false) {
            throw new \RuntimeException("Não foi possível ler {$file}.");
        }

        $path = $displayPath ?? $file;

        try {
            $nodes = $this->parser->parse($code) ?? [];
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
}
