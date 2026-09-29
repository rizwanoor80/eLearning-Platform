<?php

use App\Actions\Admin\SuspendAccount;
use App\Enums\LessonStatus;
use App\Enums\UserStatus;
use App\Filament\Resources\Audit\AuditLogResource;
use App\Filament\Resources\Audit\Pages\ListAuditLogs;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Filament v5 renders a mounted action's modal body behind a `wire:partial` (see
 * `vendor/filament/actions/resources/views/components/modals.blade.php`) -- content Livewire
 * only ships on a later AJAX diff, never inside `Livewire\Testing\TestableLivewire::html()`. So
 * `assertSee()` after `mountTableAction()` cannot see infolist entries; this reaches the mounted
 * action's schema directly (the same object the blade would render) and renders it the same way
 * Blade does (`Schema::toHtml()`), the one place this codebase needs to prove modal *content*
 * rather than a modal *field*'s data (`assertTableActionDataSet()`, used elsewhere, is for forms).
 */
function auditModalHtml(Testable $test): string
{
    $instance = $test->instance();
    $action = $instance->getMountedAction();

    $method = new ReflectionMethod($instance, 'getMountedActionSchema');
    $method->setAccessible(true);

    return $method->invoke($instance, null, $action)->toHtml();
}

/**
 * CP8 9d (R152). Mirrors SafeguardingResourceTest.php's access/no-write conventions.
 */
it('refuses a non-admin and an inactive admin access to the audit log', function () {
    $tutor = User::factory()->tutor()->create();
    $inactiveAdmin = User::factory()->admin()->create(['status' => UserStatus::Suspended]);

    test()->actingAs($tutor)->get(ListAuditLogs::getUrl())->assertForbidden();
    test()->actingAs($inactiveAdmin)->get(ListAuditLogs::getUrl())->assertForbidden();
});

it('offers no create, edit or delete action anywhere on the resource', function () {
    $log = AuditLog::factory()->create();

    expect(AuditLogResource::canCreate())->toBeFalse()
        ->and(AuditLogResource::canEdit($log))->toBeFalse()
        ->and(AuditLogResource::canDelete($log))->toBeFalse()
        ->and(AuditLogResource::canDeleteAny())->toBeFalse();
});

it('lists audit entries for an active admin', function () {
    $admin = User::factory()->admin()->create();
    $log = AuditLog::factory()->create();

    Livewire::actingAs($admin)
        ->test(ListAuditLogs::class)
        ->assertCanSeeTableRecords([$log]);
});

it('shows System, not a blank actor, for a system-originated row', function () {
    $admin = User::factory()->admin()->create();
    $log = AuditLog::factory()->create(['actor_user_id' => null, 'action' => 'tutor.permit_expired']);

    Livewire::actingAs($admin)
        ->test(ListAuditLogs::class)
        ->assertCanSeeTableRecords([$log])
        ->assertSee('System');
});

it('filters by actor, including the synthetic System option for a null actor', function () {
    $admin = User::factory()->admin()->create();
    $withActor = AuditLog::factory()->create(['actor_user_id' => $admin->id]);
    $system = AuditLog::factory()->create(['actor_user_id' => null]);

    Livewire::actingAs($admin)
        ->test(ListAuditLogs::class)
        ->filterTable('actor_user_id', ['value' => '__system__'])
        ->assertCanSeeTableRecords([$system])
        ->assertCanNotSeeTableRecords([$withActor]);
});

it('filters by action substring', function () {
    $admin = User::factory()->admin()->create();
    $suspended = AuditLog::factory()->create(['action' => 'tutor.suspended']);
    $approved = AuditLog::factory()->create(['action' => 'tutor.approved']);

    Livewire::actingAs($admin)
        ->test(ListAuditLogs::class)
        ->filterTable('action', ['value' => 'suspend'])
        ->assertCanSeeTableRecords([$suspended])
        ->assertCanNotSeeTableRecords([$approved]);
});

it('filters by subject type and id', function () {
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->create();
    $onProfile = AuditLog::factory()->create(['subject_type' => TutorProfile::class, 'subject_id' => $profile->id]);
    $onOther = AuditLog::factory()->create(['subject_type' => User::class]);

    Livewire::actingAs($admin)
        ->test(ListAuditLogs::class)
        ->filterTable('subject', ['type' => 'TutorProfile', 'id' => $profile->id])
        ->assertCanSeeTableRecords([$onProfile])
        ->assertCanNotSeeTableRecords([$onOther]);
});

it('deep-links to one exact row by id, the mechanism the Safeguarding queue uses to link a report to its sweep row', function () {
    $admin = User::factory()->admin()->create();
    $target = AuditLog::factory()->create(['action' => 'user.suspension_sweep']);
    $other = AuditLog::factory()->create(['action' => 'user.suspension_sweep']);

    Livewire::actingAs($admin)
        ->test(ListAuditLogs::class)
        ->filterTable('id', ['value' => $target->id])
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$other]);
});

it('shows before/after and the confirmed lesson ids as plain text inside the view action, never a link', function () {
    $admin = User::factory()->admin()->create();
    $log = AuditLog::factory()->create([
        'action' => 'user.suspension_sweep',
        'before' => ['status' => 'active'],
        'after' => ['status' => 'suspended', 'confirmed_lesson_ids' => [11, 22]],
    ]);

    $test = Livewire::actingAs($admin)
        ->test(ListAuditLogs::class)
        ->assertTableActionVisible('view', $log)
        ->mountTableAction('view', $log);

    $html = auditModalHtml($test);

    expect($html)->toContain('#11, #22')
        ->and($html)->toContain('suspended')
        // These lessons were deliberately left untouched by CancelSuspendedAccountLessons
        // (R138: an admin decision, not automated) -- the label must say so, not the opposite.
        ->and($html)->toContain('still active')
        ->and($html)->not->toContain('href="'); // never a link (STATUS §6 item P decision)
});

/**
 * CYCLE-LOG 2026-09-29 12:13 ADVISOR: every other content test hand-writes an `after`
 * payload, which proves the rendering logic but never proves the real `SuspendAccount` ->
 * `CancelSuspendedAccountLessons` cascade actually produces a `user.suspension_sweep` row shaped
 * the way the reader expects. This runs the genuine action end to end -- no fabricated
 * `AuditLog::factory()` `after` array. Also proves the one fact the label depends on: the
 * confirmed lesson is left `Confirmed`, not cancelled -- `CancelSuspendedAccountLessons.php:36`
 * ("`confirmed` lessons are left untouched -- R138: an admin decision, not automated") is the
 * source of truth the label text must match, and this test would fail if that ever changed
 * without the label being updated to match.
 *
 * DEVIATION from the advisor's literal suggestion ("run the real `suspendTutor` table action"):
 * `confirmed_lesson_ids` exists only on the `user.suspension_sweep` path
 * (`CancelSuspendedAccountLessons`), never `tutor.suspension_sweep` (`CancelSuspendedTutorLessons`
 * cancels confirmed lessons itself and records only a count -- see `AuditLogsTable.php`'s
 * `confirmedLessonIdsEntry()` docblock), so `SuspendAccount` is the path that actually exercises
 * the code this test is meant to cover.
 */
it('renders the real confirmed_lesson_ids a live SuspendAccount sweep writes, from the real user.suspension_sweep row', function () {
    $admin = User::factory()->admin()->create();
    $parent = User::factory()->create(['status' => UserStatus::Active]);
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $confirmed = Lesson::factory()->create(['learner_id' => $learner->id]); // default status: Confirmed

    app(SuspendAccount::class)($admin, $parent, 'Safeguarding concern upheld.');

    $sweep = AuditLog::query()
        ->where('subject_type', User::class)
        ->where('subject_id', $parent->id)
        ->where('action', 'user.suspension_sweep')
        ->sole();

    expect($sweep->after['confirmed_lesson_ids'] ?? null)->toBe([$confirmed->id])
        // The sweep must NOT have cancelled it -- this is what makes "still active" the honest
        // label, not "cancelled".
        ->and($confirmed->fresh()->status)->toBe(LessonStatus::Confirmed);

    $test = Livewire::actingAs($admin)
        ->test(ListAuditLogs::class)
        ->mountTableAction('view', $sweep);

    $html = auditModalHtml($test);

    expect($html)->toContain('#'.$confirmed->id)
        ->and($html)->toContain('still active')
        ->and($html)->not->toContain('href="'); // never a link (STATUS §6 item P decision, CYCLE-LOG 4727-4738)
});

it('leaves the confirmed lesson ids entry out entirely for a row with none', function () {
    $admin = User::factory()->admin()->create();
    $log = AuditLog::factory()->create(['action' => 'tutor.approved', 'before' => null, 'after' => ['status' => 'approved']]);

    $test = Livewire::actingAs($admin)
        ->test(ListAuditLogs::class)
        ->mountTableAction('view', $log);

    expect(auditModalHtml($test))->not->toContain('Confirmed lessons still active');
});
