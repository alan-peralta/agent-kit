<?php

namespace Peralta\AgentKit\Tests\Feature\Database;

use PHPUnit\Framework\TestCase;

final class DatabaseDocumentationTest extends TestCase
{
    public function test_docs_name_the_migration_tags_and_the_database_store(): void
    {
        $root = dirname(__DIR__, 3);
        $read = fn (string $file): string => (string) file_get_contents($root . '/' . $file);
        $readme = $read('README.md');
        $setup = $read('SETUP.md');
        $changelog = $read('CHANGELOG.md');

        foreach (['agent-kit-migrations', 'agent-kit-pgvector-migrations', 'agent-kit-database-store-migrations'] as $tag) {
            $this->assertStringContainsString($tag, $readme, $tag);
            $this->assertStringContainsString($tag, $setup, $tag);
        }
        foreach (['agent-kit-pgvector-migrations', 'agent-kit-database-store-migrations', 'AGENT_KNOWLEDGE_STORE=database'] as $needle) {
            $this->assertStringContainsString($needle, $changelog, $needle);
        }

        $this->assertStringContainsString('AGENT_KNOWLEDGE_STORE=database', $readme);
        $this->assertStringContainsString('AGENT_KNOWLEDGE_STORE=database', $setup);
        $this->assertStringContainsString('Vindo da v0.3.x ou anterior', $readme);
        $this->assertStringContainsString('2026_05_05_000002_create_knowledge_chunks_table.php', $readme);
        $this->assertMatchesRegularExpression('/^# Knowledge Store: .*database/m', $read('.env.example'));
        $this->assertStringContainsString('AGENT_KIT_TEST_DB_CONNECTION', $read('CONTRIBUTING.md'));

        $stale = [
            'sem tag própria',
            'a migration do pgvector continua sendo executada',
            'compartilham a mesma tag',
            'Vindo da v0.2.x: a migration',
        ];
        foreach ($stale as $text) {
            $this->assertStringNotContainsString($text, $readme, $text);
            $this->assertStringNotContainsString($text, $setup, $text);
        }
    }
}
