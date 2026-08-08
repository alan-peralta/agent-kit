<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $connection = config('agent-kit.conversation.drivers.database.connection');
        $table = config('agent-kit.conversation.drivers.database.messages_table', 'agent_messages');

        Schema::connection($connection)->create($table, function (Blueprint $t) {
            $t->id();
            $t->string('conversation_id', 100)->index();
            $t->string('role', 20);
            $t->longText('content')->nullable();
            $t->json('tool_calls')->nullable();
            $t->string('tool_call_id')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();

            $t->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        $connection = config('agent-kit.conversation.drivers.database.connection');
        $table = config('agent-kit.conversation.drivers.database.messages_table', 'agent_messages');
        Schema::connection($connection)->dropIfExists($table);
    }
};
