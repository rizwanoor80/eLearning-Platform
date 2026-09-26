<?php

use App\Enums\VideoProviderCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CP6 (7b): the video-provider registry (DATA_MODEL `video_providers`, PRD §12) and the table of
 * verified webhook events.
 *
 * The registry rows are inserted here, not seeded: rehearsal is never re-seeded (CLAUDE.local.md),
 * and R125 needs a `fake` row already active there and a `daily` row for the owner to fill in.
 * `fake` starts active everywhere except production; `daily` starts inactive with no credentials.
 * The table is created here, so both rows are always new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_providers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('credentials')->nullable();
            $table->boolean('supports_embed')->default(false);
            $table->boolean('supports_attendance_webhooks')->default(false);
            $table->boolean('is_active')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('create unique index video_providers_one_active on video_providers (is_active) where is_active');

        Schema::create('video_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider_code');
            $table->string('event_id');
            $table->string('type');
            $table->string('room_name');
            $table->string('participant')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');

            $table->unique(['provider_code', 'event_id']);
            $table->index('room_name');
        });

        $now = now();

        DB::table('video_providers')->insert([
            [
                'code' => VideoProviderCode::Daily->value,
                'name' => VideoProviderCode::Daily->label(),
                'supports_embed' => true,
                'supports_attendance_webhooks' => true,
                'is_active' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => VideoProviderCode::Fake->value,
                'name' => VideoProviderCode::Fake->label(),
                'supports_embed' => false,
                'supports_attendance_webhooks' => false,
                'is_active' => ! app()->environment('production'),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('video_webhook_events');
        Schema::dropIfExists('video_providers');
    }
};
