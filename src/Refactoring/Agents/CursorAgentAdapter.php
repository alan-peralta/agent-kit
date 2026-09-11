<?php

namespace Peralta\AgentKit\Refactoring\Agents;

final class CursorAgentAdapter implements AgentAdapter
{
    public function id(): string
    {
        return 'cursor';
    }

    public function generate(AgentCommandRepository $repository, AgentTemplateRenderer $renderer): array
    {
        $files = [];

        foreach ($repository->names() as $name) {
            $body = $renderer->render($repository->command($name), self::CLI_VALUES);
            $frontmatter = "---\n"
                . "name: refactor-{$name}\n"
                . 'description: ' . self::DESCRIPTIONS[$name] . "\n"
                . "---\n\n";

            $files[] = new GeneratedAgentFile(
                ".cursor/skills/refactor-{$name}/SKILL.md",
                $frontmatter . $body,
            );
        }

        $files[] = new GeneratedAgentFile(
            '.cursor/rules/agent-kit-refactoring.mdc',
            "---\n"
                . "description: Apply Agent Kit refactoring safety rules to every refactoring analysis.\n"
                . "alwaysApply: true\n"
                . "---\n\n"
                . $repository->rules(),
        );

        return $files;
    }
}
