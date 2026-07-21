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
        Schema::table('agentic_approvals', function (Blueprint $table) {
            // sha256(args_hash . '|' . principal), set ONLY while status='pending',
            // NULL otherwise — a nullable-unique index that limits each
            // (args_hash, principal) pair to at most one pending row, portable
            // across SQLite/MySQL/PostgreSQL (all three allow multiple NULLs
            // in a unique index).
            $table->string('active_key', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('agentic_approvals', function (Blueprint $table) {
            $table->dropUnique(['active_key']);
            $table->dropColumn('active_key');
        });
    }
};
