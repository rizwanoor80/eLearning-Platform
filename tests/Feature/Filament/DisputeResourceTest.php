<?php

use App\Actions\Lessons\OpenDispute;
use App\Actions\Lessons\ResolveDispute;
use App\Enums\CurriculumCode;
use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use App\Enums\LedgerAccount;
use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Enums\UserStatus;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Filament\Resources\Disputes\Pages\ListDisputes;
use App\Models\Curriculum;
use App\Models\Dispute;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Livewire\Livewire;

/**
 * A disputable lesson (Completed, ends_at 2h ago) between a fresh parent+learner and an approved
 * tutor, with a real held escrow — mirrors `disputeSetup()` (OpenDisputeTest.php) and `lgLesson()`
 * (LedgerServiceTest.php) combined, since resolving needs both a real dispute and a real ledger.
 *
 * @return array{lesson: Lesson, dispute: Dispute}
 */
function drSetup(LessonType $type = LessonType::Regular, DisputeReason $reason = DisputeReason::Quality): array
{
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create([
        'account_user_id' => $parent->id,
        'curriculum_id' => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
    ]);

    $lesson = Lesson::factory()->withStatus(LessonStatus::Completed)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'type' => $type,
        'starts_at' => now()->copy()->subHours(3),
        'ends_at' => now()->copy()->subHours(2),
        'completed_at' => now()->copy()->subHours(2),
        'price' => 10000,
        'commission_pct' => 25,
        'commission_amount' => 2500,
        'tutor_amount' => 7500,
    ]);

    app(LedgerService::class)->hold($lesson);

    $dispute = app(OpenDispute::class)($parent, $lesson->fresh(), $reason, 'Something was wrong.');

    return ['lesson' => $lesson->fresh(), 'dispute' => $dispute];
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

// ---- access / no delete or edit -----------------------------------------------------------------

it('refuses a non-admin and an inactive admin access to the disputes queue', function () {
    $tutor = User::factory()->tutor()->create();
    $inactiveAdmin = User::factory()->admin()->create(['status' => UserStatus::Suspended]);

    test()->actingAs($tutor)->get(ListDisputes::getUrl())->assertForbidden();
    test()->actingAs($inactiveAdmin)->get(ListDisputes::getUrl())->assertForbidden();
});

it('offers no create, edit or delete action anywhere on the resource', function () {
    ['dispute' => $dispute] = drSetup();

    expect(DisputeResource::canDelete($dispute))->toBeFalse()
        ->and(DisputeResource::canDeleteAny())->toBeFalse()
        ->and(DisputeResource::canCreate())->toBeFalse()
        ->and(DisputeResource::canEdit($dispute))->toBeFalse();
});

it('lists disputes for an active admin', function () {
    ['dispute' => $dispute] = drSetup();

    Livewire::actingAs($this->admin)
        ->test(ListDisputes::class)
        ->assertCanSeeTableRecords([$dispute]);
});

// ---- resolve visibility ---------------------------------------------------------------------------

it('offers resolve only for an open dispute', function () {
    ['dispute' => $dispute] = drSetup();

    Livewire::actingAs($this->admin)
        ->test(ListDisputes::class)
        ->assertTableActionVisible('resolve', $dispute)
        ->callTableAction('resolve', $dispute, ['parent_refund_pct' => 100, 'tutor_pay_pct' => 0, 'note' => 'Refunded in full.'])
        ->assertTableActionHidden('resolve', $dispute->fresh());
});

// ---- resolve actually settles (R150) --------------------------------------------------------------

it('resolves a dispute over the table action, settling the ledger and moving the lesson to settled', function () {
    ['lesson' => $lesson, 'dispute' => $dispute] = drSetup();

    Livewire::actingAs($this->admin)
        ->test(ListDisputes::class)
        ->callTableAction('resolve', $dispute, [
            'parent_refund_pct' => 37,
            'tutor_pay_pct' => 63,
            'note' => 'Split per the call recording.',
        ]);

    $fresh = $dispute->fresh();
    expect($fresh->status)->toBe(DisputeStatus::Resolved)
        ->and($fresh->parent_refund_pct)->toBe(37)
        ->and($fresh->tutor_pay_pct)->toBe(63)
        ->and($fresh->resolved_by)->toBe($this->admin->id)
        ->and($lesson->fresh()->status)->toBe(LessonStatus::Settled);

    expect(app(LedgerService::class)->sum($lesson))->toBe(0)
        ->and(app(LedgerService::class)->balance($lesson, LedgerAccount::Refund))->toBe(3700)
        ->and(app(LedgerService::class)->balance($lesson, LedgerAccount::Tutor))->toBe(4725);
});

// ---- prefill (PRD §2.10) --------------------------------------------------------------------------

it('prefills 100/0 for a trial lesson\'s resolve form', function () {
    ['dispute' => $dispute] = drSetup(type: LessonType::Trial);

    Livewire::actingAs($this->admin)
        ->test(ListDisputes::class)
        ->mountTableAction('resolve', $dispute)
        ->assertTableActionDataSet(['parent_refund_pct' => 100, 'tutor_pay_pct' => 0]);
});

// ---- the double-submit race, over the Livewire action (advisor-flagged bug class) -----------------

// The plain-PHP proof that ResolveDispute itself throws LessonTransitionException (not
// DisputeException) on a second resolve lives in ResolveDisputeTest.php -- that is why the table
// action's own catch clause names both exceptions, not just DisputeException. Empirically (this
// test, first written to assert a toast and failing with "action ... is not visible" instead):
// Filament's own action dispatch re-fetches the record and re-checks `visible()` before running
// the action's closure at all, so a *sequential* double-submit through the Livewire test harness
// never reaches ResolveDispute's second call in the first place -- `visible()` is a second,
// earlier gate, on top of the one proven in ResolveDisputeTest.php. This is the honest, provable
// behaviour: a stale resolve attempt on an already-resolved dispute is refused before the action
// even runs, not caught after. The LessonTransitionException catch remains as defense in depth
// for the true-concurrency window (two requests racing inside the same instant) that a sequential
// test cannot drive -- matching `ResolveDispute`'s own docblock on layered guards.
it('refuses a second, stale resolve attempt before the action even runs (visible() re-checks the fresh record)', function () {
    ['dispute' => $dispute] = drSetup();

    $component = Livewire::actingAs($this->admin)->test(ListDisputes::class)
        ->assertTableActionVisible('resolve', $dispute);

    app(ResolveDispute::class)($this->admin, $dispute, 100, 0, 'First resolution.');

    $component->assertTableActionHidden('resolve', $dispute->fresh());

    expect($dispute->fresh()->parent_refund_pct)->toBe(100)
        ->and($dispute->fresh()->admin_note)->toBe('First resolution.');
});
