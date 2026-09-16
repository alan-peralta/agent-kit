<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Ast;

use Peralta\AgentKit\Refactoring\Analysis\DTOs\ParsedFile;

interface AstParser
{
    public function parse(string $file, ?string $displayPath = null): ParsedFile;
}
