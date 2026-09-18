<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection($this->connectionName())->create($this->tableName(), function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id', 64);
            $t->string('collection', 64);
            $t->string('source');
            $t->longText('content');
            // Nullable because MySQL rejects literal defaults on JSON columns; the store always writes it.
            $t->json('metadata')->nullable();
            // Base64 of the L2-normalised embedding packed as little-endian float32.
            $t->longText('embedding');
            $t->timestamps();

            $t->index('tenant_id');
            $t->index(['tenant_id', 'collection']);
            $t->index(['tenant_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connectionName())->dropIfExists($this->tableName());
    }

    private function connectionName(): ?string
    {
        return config('agent-kit.knowledge.stores.database.connection') ?: null;
    }

    private function tableName(): string
    {
        return config('agent-kit.knowledge.stores.database.table', 'knowledge_chunks');
    }
};
