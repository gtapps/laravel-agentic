<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('agentic.audit.connection');
    }

    public function up(): void
    {
        Schema::create('agentic_action_log', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('action')->index();
            $table->string('surface');
            $table->string('user_id')->nullable();
            $table->json('args'); // redacted
            $table->string('args_hash', 64);
            $table->string('status'); // ok|error|denied|approval_required
            $table->text('error')->nullable();
            $table->foreignUlid('approval_id')->nullable();
            $table->string('definition_hash', 64);
            $table->string('request_id')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agentic_action_log');
    }
};
