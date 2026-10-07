<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * R180: a record of every request that reaches the video webhook endpoint, kept so the owner can
     * see whether a provider is calling at all and in what shape. Metadata only — never a body,
     * a header or a signature.
     */
    public function up(): void
    {
        Schema::create('video_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('provider_code', 16);
            $table->timestamp('received_at');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('outcome', 32)->nullable();
            $table->string('event_type', 64)->nullable();
            $table->string('event_id', 128)->nullable();
            $table->unsignedInteger('body_length');
            $table->json('top_level_keys')->nullable();
            $table->json('payload_keys')->nullable();

            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_webhook_deliveries');
    }
};
