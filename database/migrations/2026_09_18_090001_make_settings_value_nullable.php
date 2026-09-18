<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Site-group keys like `tagline`/`logo_path` are legitimately unset
     * until an admin fills them in (CP1) — `value` was NOT NULL from CP0,
     * when only the always-populated platform group existed.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE settings ALTER COLUMN value DROP NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE settings ALTER COLUMN value SET NOT NULL');
    }
};
