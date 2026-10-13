<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * R193: the bearer token that opens `GET /ops/status`. Only a SHA-256 hash of the token is kept —
     * the plaintext is shown once, in the admin, and is never stored or logged. At most one token is
     * active at a time (the partial unique index); a rotation revokes the old row and inserts the new
     * one in one transaction, and a revoked row stays as the record that it existed.
     */
    public function up(): void
    {
        Schema::create('ops_status_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->json('allowed_ips')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        DB::statement('create unique index ops_status_tokens_one_active on ops_status_tokens ((true)) where revoked_at is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_status_tokens');
    }
};
