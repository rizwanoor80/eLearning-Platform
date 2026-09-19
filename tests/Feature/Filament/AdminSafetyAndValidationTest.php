<?php

use App\Actions\Admin\DisableAdminUser;
use App\Actions\RecordAuditLog;
use App\Enums\LevelTier;
use App\Enums\UserStatus;
use App\Filament\Resources\DocumentTypes\Pages\CreateDocumentType;
use App\Filament\Resources\DocumentTypes\Pages\EditDocumentType;
use App\Filament\Resources\PriceBands\Pages\CreatePriceBand;
use App\Filament\Resources\PriceBands\Pages\EditPriceBand;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\DocumentType;
use App\Models\PriceBand;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

// ---- the last-active-admin check is transactional (R36 Low) -----------------------------

it('counts and disables inside one transaction, with the active admin rows locked', function () {
    $other = User::factory()->admin()->create();
    $baseline = DB::transactionLevel();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = ['sql' => strtolower($query->sql), 'level' => DB::transactionLevel()];
    });

    (new DisableAdminUser(app(RecordAuditLog::class)))($this->admin, $other, 'Left.');

    $lock = collect($queries)->first(fn ($q) => str_contains($q['sql'], 'from "users"') && str_contains($q['sql'], 'for update'));
    $write = collect($queries)->first(fn ($q) => str_starts_with($q['sql'], 'update "users"'));
    $audit = collect($queries)->first(fn ($q) => str_contains($q['sql'], 'insert into "audit_logs"'));

    expect($lock)->not->toBeNull()->and($write)->not->toBeNull()->and($audit)->not->toBeNull()
        ->and($lock['level'])->toBeGreaterThan($baseline)
        ->and($write['level'])->toBeGreaterThan($baseline)
        ->and($audit['level'])->toBeGreaterThan($baseline);
});

it('refuses the second of two admins disabling each other, so one active admin always remains', function () {
    $disable = new DisableAdminUser(app(RecordAuditLog::class));
    $second = User::factory()->admin()->create();

    // B disables A; then A (now suspended, with a stale session) tries to disable B.
    $disable($second, $this->admin, 'x');
    expect(fn () => $disable($this->admin->fresh(), $second, 'y'))
        ->toThrow(RuntimeException::class, 'last active admin');

    expect($second->fresh()->status)->toBe(UserStatus::Active)
        ->and($this->admin->fresh()->status)->toBe(UserStatus::Suspended)
        ->and(AuditLog::query()->where('action', 'admin_user.disabled')->count())->toBe(1);
});

it('writes neither the status nor an audit row when the check refuses', function () {
    $lonely = $this->admin;
    $other = User::factory()->admin()->create(['status' => UserStatus::Suspended]);

    expect(fn () => (new DisableAdminUser(app(RecordAuditLog::class)))($other, $lonely, 'x'))->toThrow(RuntimeException::class);

    expect($lonely->fresh()->status)->toBe(UserStatus::Active)
        ->and(AuditLog::query()->where('action', 'admin_user.disabled')->exists())->toBeFalse();
});

it('asks for confirmation before disabling an admin', function () {
    $other = User::factory()->admin()->create();

    $action = Livewire::actingAs($this->admin)->test(ListUsers::class)
        ->instance()->getTable()->getAction('disable');

    expect($action->isConfirmationRequired())->toBeTrue();
    // The confirmation does not change what the action does.
    Livewire::actingAs($this->admin)->test(ListUsers::class)
        ->callTableAction('disable', $other, ['reason' => 'Left the company.']);
    expect($other->fresh()->status)->toBe(UserStatus::Suspended);
});

// ---- a duplicate price band gets a message, not a 500 ---------------------------------

it('refuses a second band for the same curriculum, level and start date with a message', function () {
    $curriculum = Curriculum::factory()->create();
    PriceBand::factory()->create(['curriculum_id' => $curriculum->id, 'level_tier' => LevelTier::Exam1, 'effective_from' => '2026-01-01', 'min_rate' => 5000, 'max_rate' => 9000]);
    $form = ['curriculum_id' => $curriculum->id, 'level_tier' => LevelTier::Exam1->value, 'min_rate' => 6000, 'max_rate' => 8000, 'effective_from' => '2026-01-01'];

    Livewire::actingAs($this->admin)->test(CreatePriceBand::class)
        ->fillForm($form)->call('create')
        ->assertHasFormErrors(['effective_from' => 'unique']);

    expect(PriceBand::query()->where('curriculum_id', $curriculum->id)->count())->toBe(1);

    // A different date, level or curriculum is fine.
    Livewire::actingAs($this->admin)->test(CreatePriceBand::class)
        ->fillForm(array_merge($form, ['effective_from' => '2026-02-01']))->call('create')->assertHasNoFormErrors();
    Livewire::actingAs($this->admin)->test(CreatePriceBand::class)
        ->fillForm(array_merge($form, ['level_tier' => LevelTier::Exam2->value]))->call('create')->assertHasNoFormErrors();
    expect(PriceBand::query()->where('curriculum_id', $curriculum->id)->count())->toBe(3);
});

it('refuses an edit that moves a band onto another band’s date, but lets a band keep its own', function () {
    $curriculum = Curriculum::factory()->create();
    PriceBand::factory()->create(['curriculum_id' => $curriculum->id, 'level_tier' => LevelTier::Exam1, 'effective_from' => '2026-01-01', 'min_rate' => 5000, 'max_rate' => 9000]);
    $later = PriceBand::factory()->create(['curriculum_id' => $curriculum->id, 'level_tier' => LevelTier::Exam1, 'effective_from' => '2026-06-01', 'min_rate' => 5000, 'max_rate' => 9000]);

    Livewire::actingAs($this->admin)->test(EditPriceBand::class, ['record' => $later->getRouteKey()])
        ->fillForm(['effective_from' => '2026-01-01'])->call('save')
        ->assertHasFormErrors(['effective_from' => 'unique']);

    Livewire::actingAs($this->admin)->test(EditPriceBand::class, ['record' => $later->getRouteKey()])
        ->fillForm(['max_rate' => 9500])->call('save')->assertHasNoFormErrors();

    expect($later->fresh()->max_rate->toFils())->toBe(9500)
        ->and($later->fresh()->effective_from->toDateString())->toBe('2026-06-01');
});

// ---- document-type code and sort ------------------------------------------------------

it('refuses a document-type code that is duplicated or not a lower-case slug', function (string $code, string $rule) {
    DocumentType::factory()->create(['code' => 'existing_type']);

    Livewire::actingAs($this->admin)->test(CreateDocumentType::class)
        ->fillForm(['code' => $code, 'name' => 'N', 'description' => 'D', 'required' => false, 'active' => true, 'sort' => 1])
        ->call('create')->assertHasFormErrors(['code' => $rule]);

    expect(DocumentType::query()->where('name', 'N')->exists())->toBeFalse();
})->with([
    'duplicate' => ['existing_type', 'unique'],
    'upper case' => ['Police_Clearance', 'regex'],
    'spaces' => ['police clearance', 'regex'],
    'leading digit' => ['1st_letter', 'regex'],
    'hyphen' => ['police-clearance', 'regex'],
]);

it('accepts a well-formed document-type code', function () {
    Livewire::actingAs($this->admin)->test(CreateDocumentType::class)
        ->fillForm(['code' => 'reference_letter_2', 'name' => 'Reference', 'description' => 'D', 'required' => false, 'active' => true, 'sort' => 4])
        ->call('create')->assertHasNoFormErrors();

    expect(DocumentType::query()->where('code', 'reference_letter_2')->exists())->toBeTrue();
});

it('refuses a sort that is negative, fractional or larger than the column holds', function (mixed $sort) {
    Livewire::actingAs($this->admin)->test(CreateDocumentType::class)
        ->fillForm(['code' => 'sorted', 'name' => 'S', 'description' => 'D', 'required' => false, 'active' => true, 'sort' => $sort])
        ->call('create')->assertHasFormErrors(['sort']);

    expect(DocumentType::query()->where('code', 'sorted')->exists())->toBeFalse();
})->with(['negative' => [-1], 'fractional' => [1.5], 'too large' => [32768], 'text' => ['abc']]);

it('accepts the whole valid sort range', function (int $sort) {
    Livewire::actingAs($this->admin)->test(CreateDocumentType::class)
        ->fillForm(['code' => 'sort_'.$sort, 'name' => 'S', 'description' => 'D', 'required' => false, 'active' => true, 'sort' => $sort])
        ->call('create')->assertHasNoFormErrors();
})->with([0, 32767]);

it('keeps a document type’s code fixed once it exists, even against a crafted request', function () {
    $type = DocumentType::factory()->create(['code' => DocumentType::PERMIT_CODE, 'name' => 'Permit']);

    Livewire::actingAs($this->admin)->test(EditDocumentType::class, ['record' => $type->getRouteKey()])
        ->assertFormFieldIsDisabled('code')
        ->set('data.code', 'renamed')
        ->set('data.name', 'Work permit')
        ->call('save');

    expect($type->fresh()->code)->toBe(DocumentType::PERMIT_CODE)->and($type->fresh()->name)->toBe('Work permit');
});
