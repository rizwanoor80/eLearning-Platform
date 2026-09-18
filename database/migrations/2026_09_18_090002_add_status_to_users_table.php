<?php

use App\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. DATA_MODEL.md's `users.status`/`suspended_reason`,
     * arriving with their first real caller: disabling an admin user (CP1).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('status', array_column(UserStatus::cases(), 'value'))
                ->default(UserStatus::Active->value)
                ->after('role');
            $table->string('suspended_reason')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['status', 'suspended_reason']);
        });
    }
};
