<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $connection = config('agent-kit.knowledge.stores.pgvector.connection', 'pgsql');
        $table = config('agent-kit.knowledge.stores.pgvector.table', 'knowledge_chunks');
        $dimensions = config('agent-kit.knowledge.embedders.openai.dimensions', 1536);

        $db = DB::connection($connection);

        $db->statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::connection($connection)->create($table, function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id', 64);
            $t->string('collection', 64);
            $t->string('source');
            $t->text('content');
            $t->jsonb('metadata')->default('{}');
            $t->integer('token_count')->nullable();
            $t->timestamps();

            $t->index('tenant_id');
            $t->index(['tenant_id', 'collection']);
            $t->index('source');
        });

        // Coluna vector e índice HNSW (Schema do Laravel não suporta vector nativamente)
        $db->statement("ALTER TABLE {$table} ADD COLUMN embedding vector({$dimensions}) NOT NULL");
        $db->statement("
            CREATE INDEX {$table}_embedding_idx
            ON {$table}
            USING hnsw (embedding vector_cosine_ops)
            WITH (m = 16, ef_construction = 64)
        ");
    }

    public function down(): void
    {
        $connection = config('agent-kit.knowledge.stores.pgvector.connection', 'pgsql');
        $table = config('agent-kit.knowledge.stores.pgvector.table', 'knowledge_chunks');

        Schema::connection($connection)->dropIfExists($table);
    }
};
