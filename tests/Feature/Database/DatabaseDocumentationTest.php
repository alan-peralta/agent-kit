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
        $this->assertMatchesRegularExpression('/^# Knowledge Store: .*database/m', $read('.env.example'));
        $this->assertStringContainsString('AGENT_KIT_TEST_DB_CONNECTION', $read('CONTRIBUTING.md'));

        foreach (['sem tag própria', 'a migration do pgvector continua sendo executada', 'compartilham a mesma tag'] as $stale) {
            $this->assertStringNotContainsString($stale, $readme, $stale);
            $this->assertStringNotContainsString($stale, $setup, $stale);
        }
    }
}
