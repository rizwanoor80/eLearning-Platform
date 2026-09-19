<?php

use App\Enums\LevelTier;
use App\Support\YearGroups\LegacyYearGroupMapper;
use App\Support\YearGroups\YearGroupDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R33: year groups become a controlled list per curriculum. The old free-text
 * columns are renamed `*_legacy` and stay only for rows that could not be
 * mapped (see LegacyYearGroupMapper); everything else points at `year_groups`.
 * `down()` exists so the mapping can be tested against legacy-shaped data —
 * migrations are forward-only once merged.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('year_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_id')->constrained('curricula')->restrictOnDelete();
            $table->string('code');
            $table->string('label');
            $table->unsignedSmallInteger('sort');
            $table->enum('level_tier', array_column(LevelTier::cases(), 'value'));
            $table->timestamps();

            $table->unique(['curriculum_id', 'code']);
            $table->unique(['curriculum_id', 'label']);
            $table->index(['curriculum_id', 'sort']);
        });

        // Curricula that already exist get their default year groups now, so
        // the mapping below has something to map onto; on a fresh install the
        // seeder does this after the curricula are seeded.
        YearGroupDefaults::insertMissing();

        Schema::table('learners', function (Blueprint $table) {
            $table->renameColumn('year_group', 'year_group_legacy');
        });

        Schema::table('learners', function (Blueprint $table) {
            $table->foreignId('year_group_id')->nullable()->after('is_minor')->constrained('year_groups')->restrictOnDelete();
        });

        Schema::table('tutor_subjects', function (Blueprint $table) {
            $table->renameColumn('level_min', 'level_min_legacy');
            $table->renameColumn('level_max', 'level_max_legacy');
        });

        Schema::table('tutor_subjects', function (Blueprint $table) {
            $table->string('level_min_legacy')->nullable()->change();
            $table->string('level_max_legacy')->nullable()->change();
            $table->foreignId('level_min_id')->nullable()->constrained('year_groups')->restrictOnDelete();
            $table->foreignId('level_max_id')->nullable()->constrained('year_groups')->restrictOnDelete();
        });

        (new LegacyYearGroupMapper)->run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('UPDATE learners SET year_group_legacy = yg.label FROM year_groups yg WHERE learners.year_group_id = yg.id');

        Schema::table('learners', function (Blueprint $table) {
            $table->dropConstrainedForeignId('year_group_id');
        });

        Schema::table('learners', function (Blueprint $table) {
            $table->renameColumn('year_group_legacy', 'year_group');
        });

        DB::statement('UPDATE tutor_subjects SET level_min_legacy = yg.label FROM year_groups yg WHERE tutor_subjects.level_min_id = yg.id');
        DB::statement('UPDATE tutor_subjects SET level_max_legacy = yg.label FROM year_groups yg WHERE tutor_subjects.level_max_id = yg.id');
        DB::statement("UPDATE tutor_subjects SET level_min_legacy = '' WHERE level_min_legacy IS NULL");
        DB::statement("UPDATE tutor_subjects SET level_max_legacy = '' WHERE level_max_legacy IS NULL");

        Schema::table('tutor_subjects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('level_min_id');
            $table->dropConstrainedForeignId('level_max_id');
        });

        Schema::table('tutor_subjects', function (Blueprint $table) {
            $table->string('level_min_legacy')->nullable(false)->change();
            $table->string('level_max_legacy')->nullable(false)->change();
        });

        Schema::table('tutor_subjects', function (Blueprint $table) {
            $table->renameColumn('level_min_legacy', 'level_min');
            $table->renameColumn('level_max_legacy', 'level_max');
        });

        Schema::dropIfExists('year_groups');
    }
};
