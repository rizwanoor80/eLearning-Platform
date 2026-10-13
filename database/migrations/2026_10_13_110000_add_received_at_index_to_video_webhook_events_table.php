<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * R193: `/ops/status` counts the attendance events stored in the last 24 hours. That table is never
     * pruned, so without an index the count would scan every row ever stored on each authenticated read.
     */
    public function up(): void
    {
        Schema::table('video_webhook_events', function (Blueprint $table) {
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('video_webhook_events', function (Blueprint $table) {
            $table->dropIndex(['received_at']);
        });
    }
};
