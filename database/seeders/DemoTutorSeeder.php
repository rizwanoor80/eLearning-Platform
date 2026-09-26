<?php

namespace Database\Seeders;

use App\Enums\CurriculumCode;
use App\Enums\LevelTier;
use App\Enums\Role;
use App\Enums\TutorProfileStatus;
use App\Enums\UserStatus;
use App\Models\AvailabilityRule;
use App\Models\Curriculum;
use App\Models\PriceBand;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
use App\Models\User;
use App\Models\YearGroup;
use App\Services\Tutors\TutorRateBands;
use App\Support\YearGroups\YearGroupTiers;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Demo tutors for rehearsal (R121): seven approved, bookable, clearly fictional tutors across the
 * launch curricula (GCSE, IB, CBSE), so search, profile and booking can be exercised without real
 * identities (HOW-WE-WORK §8). Not called from `DatabaseSeeder`; run by name.
 *
 * Deliberately no model factories and no fake-data library — both are dev-only dependencies, absent from a
 * `--no-dev` deploy. Data is hard-coded below.
 *
 * Idempotent and never destructive: a tutor whose `@example.test` email already exists is skipped
 * whole — its user, profile, subjects and rules are not read for change, updated or deleted. Each
 * tutor is built in its own transaction so a half-built tutor cannot be left behind and then be
 * skipped forever. Curricula, subjects, year groups and price bands come from the base seeders and
 * are only looked up here — a missing one aborts the run, never invented.
 *
 * Availability is weekly (`availability_rules` has no date range), so the rules cover every one of
 * the next eight weeks and beyond. Each rate is checked against the app's own band rule
 * (`TutorRateBands`) before its transaction commits. Passwords are random and never shown: these
 * accounts cannot be signed into, only browsed as tutors.
 */
class DemoTutorSeeder extends Seeder
{
    /** Permit validity given to each demo tutor, counted from the day the seeder runs. */
    public const PERMIT_MONTHS = 15;

    /**
     * Availability weekday: 0 = Sunday … 6 = Saturday (Carbon `dayOfWeek`), as `SlotCalculator` reads it.
     *
     * @var list<array{name: string, email: string, timezone: string, headline: string, bio: string, curriculum: CurriculumCode, subjects: list<string>, from: string, to: string, tier: LevelTier, rate: int, rules: list<array{int, string, string}>}>
     */
    private const TUTORS = [
        [
            'name' => 'Amira Demo', 'email' => 'demo.amira@example.test', 'timezone' => 'Asia/Dubai',
            'headline' => 'GCSE maths and physics, step by step',
            'bio' => 'Demo tutor. Fictional profile used to try out search and booking. Patient explanations, past-paper practice and a weekly focus on the topics that lose the most marks.',
            'curriculum' => CurriculumCode::Gcse, 'subjects' => ['mathematics', 'physics'],
            'from' => 'y7', 'to' => 'y11', 'tier' => LevelTier::Exam1, 'rate' => 14000,
            'rules' => [[1, '16:00', '20:00'], [3, '16:00', '20:00'], [6, '10:00', '14:00']],
        ],
        [
            'name' => 'Oliver Demo', 'email' => 'demo.oliver@example.test', 'timezone' => 'Europe/London',
            'headline' => 'GCSE English language and literature',
            'bio' => 'Demo tutor. Fictional profile used to try out search and booking. Essay structure, set texts and exam technique for GCSE English.',
            'curriculum' => CurriculumCode::Gcse, 'subjects' => ['english-language', 'english-literature'],
            'from' => 'y8', 'to' => 'y11', 'tier' => LevelTier::Exam1, 'rate' => 12000,
            'rules' => [[2, '15:00', '19:00'], [4, '15:00', '19:00'], [0, '10:00', '13:00']],
        ],
        [
            'name' => 'Priya Demo', 'email' => 'demo.priya@example.test', 'timezone' => 'Asia/Kolkata',
            'headline' => 'CBSE maths and science for grades 6 to 10',
            'bio' => 'Demo tutor. Fictional profile used to try out search and booking. NCERT-aligned lessons with regular short quizzes and clear worked examples.',
            'curriculum' => CurriculumCode::Cbse, 'subjects' => ['mathematics', 'combined-science'],
            'from' => 'g6', 'to' => 'g10', 'tier' => LevelTier::Exam1, 'rate' => 11000,
            'rules' => [[1, '18:00', '21:00'], [2, '18:00', '21:00'], [5, '18:00', '21:00']],
        ],
        [
            'name' => 'Rohan Demo', 'email' => 'demo.rohan@example.test', 'timezone' => 'Asia/Dubai',
            'headline' => 'CBSE chemistry and biology for grades 9 to 12',
            'bio' => 'Demo tutor. Fictional profile used to try out search and booking. Board-exam preparation, diagrams that stick and numerical problems taken slowly.',
            'curriculum' => CurriculumCode::Cbse, 'subjects' => ['chemistry', 'biology'],
            'from' => 'g9', 'to' => 'g12', 'tier' => LevelTier::Exam2, 'rate' => 16000,
            'rules' => [[0, '17:00', '21:00'], [4, '17:00', '21:00'], [6, '09:00', '13:00']],
        ],
        [
            'name' => 'Sofia Demo', 'email' => 'demo.sofia@example.test', 'timezone' => 'Europe/London',
            'headline' => 'IB MYP mathematics and Spanish',
            'bio' => 'Demo tutor. Fictional profile used to try out search and booking. Criterion-based feedback for MYP assessments and conversational Spanish practice.',
            'curriculum' => CurriculumCode::IbMyp, 'subjects' => ['mathematics', 'spanish'],
            'from' => 'myp1', 'to' => 'myp5', 'tier' => LevelTier::Exam1, 'rate' => 15000,
            'rules' => [[1, '14:00', '18:00'], [3, '14:00', '18:00'], [5, '14:00', '17:00']],
        ],
        [
            'name' => 'Daniel Demo', 'email' => 'demo.daniel@example.test', 'timezone' => 'Asia/Dubai',
            'headline' => 'IB Diploma economics and business',
            'bio' => 'Demo tutor. Fictional profile used to try out search and booking. Internal assessment guidance, data-response practice and clear evaluation frameworks for DP.',
            'curriculum' => CurriculumCode::IbDp, 'subjects' => ['economics', 'business-studies'],
            'from' => 'dp1', 'to' => 'dp2', 'tier' => LevelTier::Exam2, 'rate' => 20000,
            'rules' => [[2, '16:00', '20:00'], [4, '16:00', '20:00'], [0, '15:00', '19:00']],
        ],
        [
            'name' => 'Layla Demo', 'email' => 'demo.layla@example.test', 'timezone' => 'Asia/Dubai',
            'headline' => 'GCSE chemistry and biology',
            'bio' => 'Demo tutor. Fictional profile used to try out search and booking. Core practicals, required-practical write-ups and retrieval practice for GCSE sciences.',
            'curriculum' => CurriculumCode::Gcse, 'subjects' => ['chemistry', 'biology'],
            'from' => 'y7', 'to' => 'y11', 'tier' => LevelTier::Exam1, 'rate' => 13000,
            'rules' => [[1, '15:30', '19:30'], [3, '15:30', '19:30'], [0, '16:00', '19:00']],
        ],
    ];

    public function run(TutorRateBands $bands): void
    {
        // Every reference row is resolved for all tutors before the first insert, so a missing one
        // aborts the run with nothing written — never a partial seed.
        $resolved = array_map(fn (array $tutor): array => $this->referenceRows($tutor), self::TUTORS);

        foreach (self::TUTORS as $index => $tutor) {
            DB::transaction(function () use ($tutor, $resolved, $index, $bands): void {
                $this->seedTutor($tutor, $resolved[$index], $bands);
            });
        }
    }

    /**
     * @param  array{name: string, email: string, timezone: string, headline: string, bio: string, curriculum: CurriculumCode, subjects: list<string>, from: string, to: string, tier: LevelTier, rate: int, rules: list<array{int, string, string}>}  $tutor
     * @return array{curriculum: Curriculum, from: YearGroup, to: YearGroup, subjects: list<Subject>}
     */
    private function referenceRows(array $tutor): array
    {
        $curriculum = Curriculum::query()->where('code', $tutor['curriculum']->value)->first()
            ?? throw new RuntimeException("Curriculum {$tutor['curriculum']->value} is missing; run the base seeders first.");

        $from = $this->yearGroup($curriculum, $tutor['from']);
        $to = $this->yearGroup($curriculum, $tutor['to']);

        // R33: the tier is derived from the year groups, never typed. A rehearsal admin's edit to a
        // year group must abort the run here, before anything is written.
        $tier = YearGroupTiers::derive($from, $to);

        if ($tier !== $tutor['tier']) {
            throw new RuntimeException("Demo tutor {$tutor['email']}: year groups {$tutor['from']}-{$tutor['to']} derive tier ".($tier->value ?? 'none').", expected {$tutor['tier']->value}.");
        }

        $band = PriceBand::query()
            ->where('curriculum_id', $curriculum->id)
            ->where('level_tier', $tier)
            ->where('effective_from', '<=', Date::today()->toDateString())
            ->orderByDesc('effective_from')
            ->first()
            ?? throw new RuntimeException("Demo tutor {$tutor['email']}: no current price band for {$curriculum->code->value} {$tier->value}.");

        if ($tutor['rate'] < $band->min_rate->toFils() || $tutor['rate'] > $band->max_rate->toFils()) {
            throw new RuntimeException("Demo tutor {$tutor['email']}: rate {$tutor['rate']} is outside the current {$curriculum->code->value} {$tier->value} band.");
        }

        return [
            'curriculum' => $curriculum,
            'from' => $from,
            'to' => $to,
            'subjects' => array_map(
                fn (string $slug): Subject => Subject::query()->where('slug', $slug)->first()
                    ?? throw new RuntimeException("Subject {$slug} is missing; run the base seeders first."),
                $tutor['subjects'],
            ),
        ];
    }

    /**
     * @param  array{name: string, email: string, timezone: string, headline: string, bio: string, curriculum: CurriculumCode, subjects: list<string>, from: string, to: string, tier: LevelTier, rate: int, rules: list<array{int, string, string}>}  $tutor
     * @param  array{curriculum: Curriculum, from: YearGroup, to: YearGroup, subjects: list<Subject>}  $reference
     */
    private function seedTutor(array $tutor, array $reference, TutorRateBands $bands): void
    {
        if (User::query()->where('email', $tutor['email'])->exists()) {
            return;
        }

        ['curriculum' => $curriculum, 'from' => $from, 'to' => $to, 'subjects' => $subjects] = $reference;

        $user = new User;
        $user->forceFill([
            'name' => $tutor['name'],
            'email' => $tutor['email'],
            'timezone' => $tutor['timezone'],
            'role' => Role::Tutor,
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'password' => Hash::make(Str::random(64)),
        ])->save();

        $profile = new TutorProfile([
            'user_id' => $user->id,
            'headline' => $tutor['headline'],
            'bio' => $tutor['bio'],
            'hourly_rate' => $tutor['rate'],
            'permit_number' => 'DEMO-'.strtoupper(Str::between($tutor['email'], 'demo.', '@')),
            'permit_expires_at' => Date::today()->addMonths(self::PERMIT_MONTHS),
        ]);
        $profile->forceFill(['status' => TutorProfileStatus::Approved, 'approved_at' => now()])->save();

        foreach ($subjects as $subject) {
            TutorSubject::query()->create([
                'tutor_profile_id' => $profile->id,
                'curriculum_id' => $curriculum->id,
                'subject_id' => $subject->id,
                'level_min_id' => $from->id,
                'level_max_id' => $to->id,
                'level_tier' => $tutor['tier'],
            ]);
        }

        foreach ($tutor['rules'] as [$weekday, $start, $end]) {
            AvailabilityRule::query()->create([
                'tutor_profile_id' => $profile->id,
                'weekday' => $weekday,
                'start_time' => $start,
                'end_time' => $end,
                'timezone' => $tutor['timezone'],
            ]);
        }

        // The app's own rule (R26/R27): a rate outside the band aborts this tutor, so nothing commits.
        if (($problem = $bands->problemWithRate($profile->fresh())) !== null) {
            throw new RuntimeException("Demo tutor {$tutor['email']}: {$problem}.");
        }
    }

    private function yearGroup(Curriculum $curriculum, string $code): YearGroup
    {
        return YearGroup::query()->where('curriculum_id', $curriculum->id)->where('code', $code)->first()
            ?? throw new RuntimeException("Year group {$code} is missing for {$curriculum->code->value}; run the base seeders first.");
    }
}
