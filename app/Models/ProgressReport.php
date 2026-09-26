<?php

namespace App\Models;

use App\Enums\TrialSuitability;
use Database\Factories\ProgressReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A tutor's report on one lesson (PRD §2.7). One per lesson (unique `lesson_id`); written only by
 * `SubmitProgressReport`. The three trial fields are set exactly when the lesson is a trial.
 *
 * @property int $id
 * @property int $lesson_id
 * @property int $tutor_profile_id
 * @property string $topics_covered
 * @property string $went_well
 * @property string $work_on_next
 * @property string $homework
 * @property int $engagement
 * @property TrialSuitability|null $trial_suitability
 * @property int|null $trial_recommended_frequency
 * @property string|null $trial_focus_areas
 * @property Carbon $submitted_at
 * @property Carbon|null $emailed_at
 */
class ProgressReport extends Model
{
    /** @use HasFactory<ProgressReportFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'trial_suitability' => TrialSuitability::class,
            'engagement' => 'integer',
            'trial_recommended_frequency' => 'integer',
            'submitted_at' => 'datetime',
            'emailed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return BelongsTo<TutorProfile, $this>
     */
    public function tutorProfile(): BelongsTo
    {
        return $this->belongsTo(TutorProfile::class);
    }
}
