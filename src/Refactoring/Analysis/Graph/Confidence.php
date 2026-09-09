<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Graph;

enum Confidence: string
{
    case EXACT = 'exact';
    case INFERRED = 'inferred';
    case UNKNOWN = 'unknown';
}
