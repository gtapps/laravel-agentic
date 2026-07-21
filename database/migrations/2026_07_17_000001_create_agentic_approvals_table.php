<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('agentic.approvals.connection');
    }

    public function up(): void
    {
        Schema::create('agentic_approvals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('action');
            $table->string('args_hash', 64);
            $table->json('args_redacted');
            $table->string('status'); // pending|granted|denied|expired|consumed
            // sha256(args_hash . '|' . principal), set ONLY while status='pending',
            // NULL otherwise — a nullable-unique index that limits each
            // (args_hash, principal) pair to at most one pending row, portable
            // across SQLite/MySQL/PostgreSQL (all three allow multiple NULLs
            // in a unique index).
            $table->string('active_key', 64)->nullable()->unique();
            $table->string('token_hash', 64);
            $table->string('requested_user_id')->nullable();
            $table->string('requested_surface');
            $table->string('definition_hash', 64);
            $table->string('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            // Every broker query filters args_hash + status together, and a
            // repeated action+args combination accumulates terminal rows
            // under one hash — keep those lookups a narrow index seek.
            $table->index(['args_hash', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agentic_approvals');
    }
};
