<?php

use App\Actions\Admin\AnonymizeUser;
use App\Actions\Payouts\SavePayoutPreference;
use App\Events\Payouts\PayoutDetailsChanged;
use App\Filament\Resources\PayoutMethods\Pages\EditPayoutMethod;
use App\Filament\Resources\PayoutMethods\Pages\ListPayoutMethods;
use App\Filament\Resources\PayoutMethods\PayoutMethodResource;
use App\Filament\Resources\TutorProfiles\Pages\ListTutorProfiles;
use App\Filament\Resources\TutorProfiles\Schemas\TutorProfileInfolist;
use App\Listeners\Payouts\SendPayoutDetailsChangedMail;
use App\Mail\Payouts\PayoutDetailsChangedMail;
use App\Models\AuditLog;
use App\Models\PayoutMethod;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * R249 (23f): the payout-method registry, a tutor's preference, and the admin's view of who cannot be paid yet.
 */
function payTutor(array $profile = [], array $user = []): TutorProfile
{
    return TutorProfile::factory()->approved()->create($profile + [
        'country' => 'AE',
        'user_id' => User::factory()->tutor()->create($user),
    ]);
}

function setMethod(string $code, bool $active, array $countries = []): void
{
    PayoutMethod::query()->where('code', $code)->update(['is_active' => $active, 'countries' => json_encode($countries)]);
}

function confirmed(User $user)
{
    return test()->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
}

it('seeds the five methods with only bank transfer active, for every country', function () {
    $methods = PayoutMethod::query()->orderBy('sort')->get();

    expect($methods->pluck('code')->all())->toBe(['bank_transfer', 'payoneer', 'paypal', 'stripe', 'wise'])
        ->and($methods->where('is_active', true)->pluck('code')->all())->toBe(['bank_transfer'])
        ->and($methods->every(fn (PayoutMethod $m) => $m->countries === []))->toBeTrue();
});

it('offers only active methods open to the tutor\'s country', function () {
    setMethod('wise', true, ['PK']);
    setMethod('paypal', true, ['AE', 'PK']);
    setMethod('stripe', false, []);

    expect(PayoutMethod::query()->offeredFor('AE')->pluck('code')->sort()->values()->all())->toBe(['bank_transfer', 'paypal'])
        ->and(PayoutMethod::query()->offeredFor(' pk ')->pluck('code')->sort()->values()->all())->toBe(['bank_transfer', 'paypal', 'wise'])
        ->and(PayoutMethod::query()->offeredFor(null)->pluck('code')->all())->toBe(['bank_transfer'])
        ->and(PayoutMethod::query()->offeredFor('UAE')->pluck('code')->all())->toBe(['bank_transfer'])
        ->and(PayoutMethod::query()->offeredFor('GB')->pluck('code')->all())->toBe(['bank_transfer']);
});

it('tells a tutor why they cannot be paid yet, and when they can', function () {
    $profile = payTutor();
    expect($profile->payoutMethodProblem())->toBe('No payout method chosen.')->and($profile->canBePaid())->toBeFalse();

    $profile->forceFill(['payout_method' => 'bank_transfer'])->save();
    expect($profile->fresh()->canBePaid())->toBeTrue();

    $profile->forceFill(['bank_iban' => null])->save();
    expect($profile->fresh()->payoutMethodProblem())->toBe('The bank details are incomplete.');

    $profile->forceFill(['payout_method' => 'wise', 'payout_email' => null])->save();
    setMethod('wise', true);
    expect($profile->fresh()->payoutMethodProblem())->toBe('The payout account email is missing.');

    $profile->forceFill(['payout_email' => 'tutor@example.test'])->save();
    expect($profile->fresh()->canBePaid())->toBeTrue();

    setMethod('wise', false);
    expect($profile->fresh()->payoutMethodProblem())->toBe('The chosen payout method is not available for this tutor.');

    setMethod('wise', true, ['PK']);
    expect($profile->fresh()->canBePaid())->toBeFalse();
});

it('keeps the payout columns out of serialisation and mass assignment', function () {
    $profile = payTutor(['payout_method' => 'wise', 'payout_email' => 'secret@example.test']);
    $array = $profile->fresh()->toArray();

    expect($array)->not->toHaveKey('payout_email')
        ->and(json_encode($array))->not->toContain('secret@example.test')
        ->and($profile->getFillable())->not->toContain('payout_method')
        ->and($profile->getFillable())->not->toContain('payout_email')
        ->and(DB_raw_payout_email($profile))->not->toContain('secret@example.test');

    $profile->fill(['payout_method' => 'paypal', 'payout_email' => 'x@example.test']);
    expect($profile->payout_method)->toBe('wise');
});

function DB_raw_payout_email(TutorProfile $profile): string
{
    return (string) DB::table('tutor_profiles')->where('id', $profile->id)->value('payout_email');
}

it('saves a bank transfer from Settings, audits no values, and queues a change mail', function () {
    Mail::fake();
    $profile = payTutor(['bank_name' => null, 'bank_account_name' => null, 'bank_iban' => null]);

    confirmed($profile->user)->put(route('payout.update'), [
        'method' => 'bank_transfer',
        'bank_name' => 'Test Bank',
        'bank_account_name' => 'Test Holder',
        'bank_iban' => 'AE070331234567890123456',
        'bank_swift' => '',
    ])->assertRedirect(route('payout.edit'));

    $fresh = $profile->fresh();
    expect($fresh->payout_method)->toBe('bank_transfer')
        ->and($fresh->bank_iban)->toBe('AE070331234567890123456')
        ->and($fresh->bank_swift)->toBeNull()
        ->and($fresh->payout_details_changed_at)->not->toBeNull();

    $log = AuditLog::query()->where('action', 'tutor.payout_method_saved')->sole();
    expect($log->before)->toBe(['payout_method' => null])
        ->and($log->after)->toBe(['payout_method' => 'bank_transfer', 'details_changed' => true])
        ->and(json_encode($log->toArray()))->not->toContain('AE0703')->not->toContain('Test Bank');

});

it('dispatches the change event, whose listener queues a mail to the account address only', function () {
    Mail::fake();
    $profile = payTutor(['payout_method' => 'bank_transfer'], ['email' => 'owner@example.test']);

    (new SendPayoutDetailsChangedMail)->handle(new PayoutDetailsChanged($profile, 'Bank transfer'));

    Mail::assertQueued(PayoutDetailsChangedMail::class, fn (PayoutDetailsChangedMail $mail) => $mail->hasTo('owner@example.test')
        && ! str_contains($mail->render(), $profile->bank_iban));
});

it('sends nothing for an anonymised tutor', function () {
    Mail::fake();
    $profile = payTutor();
    $profile->user->delete();

    (new SendPayoutDetailsChangedMail)->handle(new PayoutDetailsChanged($profile, 'Bank transfer'));

    Mail::assertNothingQueued();
});

it('saves an email method, keeps the stored email when the input is blank, and fires the event once per change', function () {
    Event::fake([PayoutDetailsChanged::class]);
    setMethod('wise', true);
    $profile = payTutor();

    confirmed($profile->user)->put(route('payout.update'), ['method' => 'wise', 'payout_email' => 'Pay@Example.test'])->assertSessionHasNoErrors();
    expect($profile->fresh()->payout_email)->toBe('Pay@Example.test');

    confirmed($profile->user)->put(route('payout.update'), ['method' => 'wise', 'payout_email' => ''])->assertSessionHasNoErrors();
    expect($profile->fresh()->payout_email)->toBe('Pay@Example.test');

    Event::assertDispatchedTimes(PayoutDetailsChanged::class, 1);
});

it('refuses a method not offered for the tutor\'s country and an unknown code', function () {
    setMethod('wise', true, ['PK']);
    setMethod('paypal', false);
    $profile = payTutor();

    foreach (['wise', 'paypal', 'cash', ''] as $code) {
        confirmed($profile->user)->put(route('payout.update'), ['method' => $code, 'payout_email' => 'a@example.test'])->assertSessionHasErrors('method');
    }

    expect($profile->fresh()->payout_method)->toBeNull();
});

it('refuses, in the action, a method an admin switched off since the form was rendered', function () {
    $profile = payTutor();
    setMethod('bank_transfer', false);

    expect(fn () => app(SavePayoutPreference::class)($profile->user, $profile, 'bank_transfer', []))->toThrow(ValidationException::class);
    expect($profile->fresh()->payout_method)->toBeNull();
});

it('demands the details its method needs', function () {
    setMethod('wise', true);
    $profile = payTutor(['bank_iban' => null]);

    expect(fn () => app(SavePayoutPreference::class)($profile->user, $profile, 'bank_transfer', ['bank_name' => 'B', 'bank_account_name' => 'H']))->toThrow(ValidationException::class)
        ->and(fn () => app(SavePayoutPreference::class)($profile->user, $profile, 'wise', ['payout_email' => 'not-an-email']))->toThrow(ValidationException::class);
});

it('clears bank_verified_at when the bank details change', function () {
    $profile = payTutor(['bank_verified_at' => now(), 'payout_method' => 'bank_transfer']);

    app(SavePayoutPreference::class)($profile->user, $profile, 'bank_transfer', ['bank_iban' => 'AE999999999999999999999']);

    expect($profile->fresh()->bank_verified_at)->toBeNull();
});

it('writes nothing and sends nothing when nothing changed', function () {
    Event::fake([PayoutDetailsChanged::class]);
    $profile = payTutor(['payout_method' => 'bank_transfer']);

    app(SavePayoutPreference::class)($profile->user, $profile, 'bank_transfer', []);

    expect(AuditLog::query()->where('action', 'tutor.payout_method_saved')->exists())->toBeFalse();
    Event::assertNotDispatched(PayoutDetailsChanged::class);
});

it('refuses to change another tutor\'s profile', function () {
    $profile = payTutor();
    $other = payTutor();

    expect(fn () => app(SavePayoutPreference::class)($other->user, $profile, 'bank_transfer', []))->toThrow(ValidationException::class);
});

it('shows the Settings page with the offered methods, bank pre-selected, and only masked values', function () {
    setMethod('wise', true, ['PK']);
    $profile = payTutor(['payout_email' => 'secret@example.test']);

    $response = confirmed($profile->user)->get(route('payout.edit'))->assertOk();
    $props = $response->viewData('page')['props'];

    expect(array_column($props['methods'], 'code'))->toBe(['bank_transfer'])
        ->and($props['selected'])->toBe('bank_transfer')
        ->and($props['saved_method'])->toBeNull()
        ->and($props['problem'])->toBe('No payout method chosen.')
        ->and($props['bank']['iban_masked'])->toEndWith(substr($profile->bank_iban, -4))
        ->and(json_encode($props))->not->toContain($profile->bank_iban)->not->toContain('secret@example.test')
        ->and(json_encode($response->viewData('page')['props']['auth']))->not->toContain('payout_email');
});

it('pre-selects nothing when the onboarding bank details are incomplete', function () {
    $profile = payTutor(['bank_iban' => null]);

    expect(confirmed($profile->user)->get(route('payout.edit'))->viewData('page')['props']['selected'])->toBeNull();
});

it('is for tutors only and needs a recent password confirmation', function () {
    $profile = payTutor();

    test()->actingAs($profile->user)->get(route('payout.edit'))->assertRedirect(route('password.confirm'));
    test()->actingAs($profile->user)->put(route('payout.update'), ['method' => 'bank_transfer'])->assertRedirect(route('password.confirm'));

    foreach ([User::factory()->create(), User::factory()->admin()->create()] as $outsider) {
        confirmed($outsider)->get(route('payout.edit'))->assertForbidden();
    }

});

it('sends a guest to log in', function () {
    test()->get(route('payout.edit'))->assertRedirect(route('login'));
});

it('shares the tutor flag that shows the Payouts nav item', function () {
    $tutor = payTutor()->user;
    $parent = User::factory()->create();

    expect(test()->actingAs($tutor)->get(route('profile.edit'))->viewData('page')['props']['auth']['is_tutor'])->toBeTrue()
        ->and(test()->actingAs($parent)->get(route('profile.edit'))->viewData('page')['props']['auth']['is_tutor'])->toBeFalse();
});

it('clears the payout method and email when the account is anonymised', function () {
    $admin = User::factory()->admin()->create();
    $profile = payTutor(['payout_method' => 'wise', 'payout_email' => 'secret@example.test', 'payout_details_changed_at' => now()]);

    app(AnonymizeUser::class)($admin, $profile->user);

    $row = DB::table('tutor_profiles')->where('id', $profile->id)->first();
    expect($row->payout_method)->toBeNull()->and($row->payout_email)->toBeNull()->and($row->payout_details_changed_at)->toBeNull();
});

it('lets an admin edit a method, saving countries upper-case and unique, with an audit row', function () {
    $admin = User::factory()->admin()->create();
    $method = PayoutMethod::query()->where('code', 'wise')->firstOrFail();

    Livewire::actingAs($admin)
        ->test(EditPayoutMethod::class, ['record' => $method->getRouteKey()])
        ->fillForm(['is_active' => true, 'countries' => ['ae', 'AE', ' pk ']])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $method->fresh();
    expect($fresh->is_active)->toBeTrue()
        ->and($fresh->countries)->toBe(['AE', 'PK'])
        ->and($fresh->updated_by)->toBe($admin->id);

    $log = AuditLog::query()->where('action', 'payout_method.updated')->sole();
    expect($log->after)->toBe(['is_active' => true, 'countries' => ['AE', 'PK']]);
});

it('rejects a country that is not two letters, and saves an empty list as every country', function () {
    $admin = User::factory()->admin()->create();
    $method = PayoutMethod::query()->where('code', 'wise')->firstOrFail();
    $method->update(['countries' => ['AE']]);

    Livewire::actingAs($admin)
        ->test(EditPayoutMethod::class, ['record' => $method->getRouteKey()])
        ->fillForm(['countries' => ['UAE']])
        ->call('save')
        ->assertHasFormErrors();

    Livewire::actingAs($admin)
        ->test(EditPayoutMethod::class, ['record' => $method->getRouteKey()])
        ->fillForm(['countries' => []])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($method->fresh()->countries)->toBe([]);
});

it('lists the registry for an admin only, with no create', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(ListPayoutMethods::class)->assertCanSeeTableRecords(PayoutMethod::query()->get());
    test()->actingAs(User::factory()->tutor()->create())->get(ListPayoutMethods::getUrl())->assertForbidden();
    expect(PayoutMethodResource::canCreate())->toBeFalse();
});

it('lists tutors who cannot be paid yet, and filters on it', function () {
    $admin = User::factory()->admin()->create();
    $ready = payTutor(['payout_method' => 'bank_transfer']);
    $none = payTutor();
    $noBank = payTutor(['payout_method' => 'bank_transfer', 'bank_iban' => null]);
    setMethod('wise', true);
    $noEmail = payTutor(['payout_method' => 'wise']);
    $withEmail = payTutor(['payout_method' => 'wise', 'payout_email' => 'w@example.test']);
    $disabled = payTutor(['payout_method' => 'paypal', 'payout_email' => 'p@example.test']);

    expect(TutorProfile::query()->cannotBePaid()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$none, $noBank, $noEmail, $disabled])->pluck('id')->sort()->values()->all());

    foreach ([$ready, $none, $noBank, $noEmail, $withEmail, $disabled] as $profile) {
        expect(TutorProfile::query()->cannotBePaid()->whereKey($profile->id)->exists())->toBe(! $profile->fresh()->canBePaid());
    }

    Livewire::actingAs($admin)
        ->test(ListTutorProfiles::class)
        ->removeTableFilter('status')
        ->filterTable('cannot_be_paid', true)
        ->assertCanSeeTableRecords([$none, $noBank, $noEmail, $disabled])
        ->assertCanNotSeeTableRecords([$ready, $withEmail])
        ->assertTableColumnStateSet('payout', 'Cannot be paid yet', $none);
});

it('matches the SQL scope to the country rule, including a lower-case or missing country', function () {
    setMethod('wise', true, ['PK']);
    $lower = payTutor(['country' => 'pk', 'payout_method' => 'wise', 'payout_email' => 'a@example.test']);
    $other = payTutor(['country' => 'AE', 'payout_method' => 'wise', 'payout_email' => 'b@example.test']);
    $noCountry = payTutor(['country' => null, 'payout_method' => 'wise', 'payout_email' => 'c@example.test']);

    foreach ([$lower, $other, $noCountry] as $profile) {
        expect(TutorProfile::query()->cannotBePaid()->whereKey($profile->id)->exists())->toBe(! $profile->fresh()->canBePaid());
    }

    expect($lower->fresh()->canBePaid())->toBeTrue();
});

it('masks the payout details in the admin infolist state', function () {
    $profile = payTutor(['payout_method' => 'wise', 'payout_email' => 'secret@example.test']);
    setMethod('wise', true);

    $lines = TutorProfileInfolist::payoutLines($profile->fresh());

    expect(implode('|', $lines))->not->toContain('secret@example.test')->toContain('s•••@example.test');
});
