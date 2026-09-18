<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Index;

interface CodebaseIndexBuilder
{
    public function build(string $root): CodebaseIndex;
}
