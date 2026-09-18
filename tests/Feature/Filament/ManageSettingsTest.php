<?php

use App\Enums\SettingGroup;
use App\Filament\Pages\ManageSettings;
use App\Mail\Tutor\TutorApprovedMail;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\TutorProfile;
use App\Models\User;
use App\Support\Facades\Settings;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->admin = User::factory()->admin()->create();
});

it('refuses a non-admin access to the settings page', function () {
    $tutor = User::factory()->tutor()->create();

    test()->actingAs($tutor)->get(ManageSettings::getUrl())->assertForbidden();
});

it('loads the current settings into the editor', function () {
    Livewire::actingAs($this->admin)
        ->test(ManageSettings::class)
        ->assertFormSet(['site_name' => 'project-elearning', 'commission_pct' => 25, 'reviews' => true]);
});

it('saves a changed setting, writing an audit row only for what changed', function () {
    Livewire::actingAs($this->admin)
        ->test(ManageSettings::class)
        ->fillForm(['site_name' => 'Acme Tutors', 'commission_pct' => 30])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Settings::get('site_name'))->toBe('Acme Tutors')
        ->and(Settings::get('commission_pct'))->toBe(30);

    $actions = AuditLog::query()->where('action', 'setting.updated')->get();
    expect($actions)->toHaveCount(2);
    $siteNameLog = $actions->first(fn ($log) => ($log->after['value'] ?? null) === 'Acme Tutors');
    expect($siteNameLog->before['value'])->toBe('project-elearning')
        ->and($siteNameLog->actor_user_id)->toBe($this->admin->id);
});

it('keeps money-like and percentage settings as integers, never floats', function () {
    Livewire::actingAs($this->admin)
        ->test(ManageSettings::class)
        ->fillForm(['payout_min' => '25000', 'vat_pct' => '5'])
        ->call('save');

    expect(Settings::get('payout_min'))->toBeInt()->toBe(25000)
        ->and(Settings::get('vat_pct'))->toBeInt();
});

it('rejects an invalid timezone and an out-of-range percentage', function () {
    Livewire::actingAs($this->admin)
        ->test(ManageSettings::class)
        ->fillForm(['default_timezone' => 'Not/AZone', 'commission_pct' => 150])
        ->call('save')
        ->assertHasFormErrors(['default_timezone', 'commission_pct']);
});

it('changes the layout title and the shared site name without a deploy (CP1 box 8, layout half)', function () {
    Livewire::actingAs($this->admin)
        ->test(ManageSettings::class)
        ->fillForm(['site_name' => 'Brand New Name'])
        ->call('save');

    $response = test()->get('/');

    expect($response->getContent())->toMatch('/<title[^>]*>\s*Brand New Name\s*<\/title>/');
    $response->assertInertia(fn ($page) => $page->where('name', 'Brand New Name'));
});

it('changes the next email\'s sender name without a deploy (CP1 box 8, email half)', function () {
    $mail = new TutorApprovedMail(TutorProfile::factory()->create());

    // Sender is read at envelope() time, so a Mailable built (or queued)
    // before the change still sends with the new name.
    expect($mail->envelope()->from->name)->toBe('project-elearning');

    Livewire::actingAs($this->admin)
        ->test(ManageSettings::class)
        ->fillForm(['site_name' => 'Brand New Name'])
        ->call('save');

    expect($mail->envelope()->from->name)->toBe('Brand New Name');

    Settings::set('from_name', 'Support Team', SettingGroup::Mail);
    Settings::set('from_address', 'hello@example.test', SettingGroup::Mail);

    $from = $mail->envelope()->from;
    expect($from->name)->toBe('Support Team')
        ->and($from->address)->toBe('hello@example.test');
});

it('renders head scripts raw in the head and shares the footer text', function () {
    Settings::set('head_scripts', '<script>window.analyticsReady = true;</script>', SettingGroup::Site);
    Settings::set('footer_text', 'Registered in Dubai', SettingGroup::Site);

    $response = test()->get('/');

    expect($response->getContent())->toContain('<script>window.analyticsReady = true;</script>');
    $response->assertInertia(fn ($page) => $page->where('site.footer_text', 'Registered in Dubai'));
});

it('puts the email footer from settings at the bottom of an email', function () {
    Settings::set('email_footer', 'You receive this because you applied to teach.', SettingGroup::Mail);

    $html = (new TutorApprovedMail(TutorProfile::factory()->create()))->render();

    expect($html)->toContain('You receive this because you applied to teach.');
});

it('never lets a re-seed overwrite a value an admin changed (R29)', function () {
    Livewire::actingAs($this->admin)
        ->test(ManageSettings::class)
        ->fillForm(['site_name' => 'Admin Chosen Name', 'commission_pct' => 33])
        ->call('save');

    $this->seed(SettingsSeeder::class);

    expect(Settings::get('site_name'))->toBe('Admin Chosen Name')
        ->and(Settings::get('commission_pct'))->toBe(33);
});

it('still inserts a key that is missing on re-seed (R29)', function () {
    Setting::query()->where('key', 'reviews')->delete();
    Cache::forget('settings.reviews');

    $this->seed(SettingsSeeder::class);

    expect(Setting::query()->where('key', 'reviews')->exists())->toBeTrue();
});
