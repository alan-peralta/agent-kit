<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = config('agent-kit.analytics.table', 'agent_kit_metrics');

        Schema::create($table, function (Blueprint $t) {
            $t->id();
            $t->string('type', 40);
            $t->string('conversation_id', 100)->nullable()->index();
            $t->string('provider', 40)->nullable();
            $t->string('model', 80)->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->json('payload')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        $table = config('agent-kit.analytics.table', 'agent_kit_metrics');
        Schema::dropIfExists($table);
    }
};
