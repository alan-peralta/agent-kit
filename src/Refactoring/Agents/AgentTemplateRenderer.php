<?php

namespace Peralta\AgentKit\Refactoring\Agents;

use InvalidArgumentException;

final class AgentTemplateRenderer
{
    /** @param array<mixed> $values */
    public function render(string $template, array $values): string
    {
        $replacements = [];

        foreach ($values as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $key) !== 1) {
                throw new InvalidArgumentException("Invalid agent template placeholder key: {$key}");
            }

            if (!is_string($value)) {
                throw new InvalidArgumentException("Agent template value must be a string: {$key}");
            }

            $replacements['{{' . $key . '}}'] = $value;
        }

        $rendered = strtr($template, $replacements);

        if (preg_match('/\{\{[^{}]+\}\}/', $rendered, $match) === 1) {
            throw new InvalidArgumentException("Unresolved agent template placeholder: {$match[0]}");
        }

        return $rendered;
    }
}
