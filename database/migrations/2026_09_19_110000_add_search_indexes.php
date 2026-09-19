<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tutor_profiles', function (Blueprint $table) {
            $table->index(['status', 'permit_expires_at']);
            $table->index('hourly_rate');
            $table->index('rating_avg');
        });

        Schema::table('tutor_subjects', function (Blueprint $table) {
            $table->index(['curriculum_id', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tutor_profiles', function (Blueprint $table) {
            $table->dropIndex(['status', 'permit_expires_at']);
            $table->dropIndex(['hourly_rate']);
            $table->dropIndex(['rating_avg']);
        });

        Schema::table('tutor_subjects', function (Blueprint $table) {
            $table->dropIndex(['curriculum_id', 'subject_id']);
        });
    }
};
