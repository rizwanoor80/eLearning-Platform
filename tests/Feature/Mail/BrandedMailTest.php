<?php

use App\Enums\SettingGroup;
use App\Mail\Tutor\TutorApprovedMail;
use App\Models\TutorProfile;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Support\Facades\Settings;
use App\Support\Mail\MailBrand;

/**
 * R174 (a)(b): the sender name follows the `mail` settings group and defaults to `TrusTutor`; every email
 * renders through the TrusTutor layout — logo lockup, first-name greeting, settings footer.
 */
function brandedMailProfile(string $fullName = 'Amira Khan'): TutorProfile
{
    return TutorProfile::factory()->for(User::factory()->create(['name' => $fullName]), 'user')->create();
}

it('defaults the sender name to TrusTutor when from_name is unset or blank, ignoring the site name', function () {
    Settings::set('site_name', 'trusTutor Team', SettingGroup::Site);

    expect((new TutorApprovedMail(brandedMailProfile()))->envelope()->from->name)->toBe('TrusTutor');

    Settings::set('from_name', '   ', SettingGroup::Mail);
    expect((new TutorApprovedMail(brandedMailProfile()))->envelope()->from->name)->toBe('TrusTutor');
});

it('sends from the from_name setting, read at send time', function () {
    $mail = new TutorApprovedMail(brandedMailProfile());

    Settings::set('from_name', 'Acme Tutors', SettingGroup::Mail);
    expect($mail->envelope()->from->name)->toBe('Acme Tutors');

    Settings::set('from_name', 'Acme Tutoring Ltd', SettingGroup::Mail);
    expect($mail->envelope()->from->name)->toBe('Acme Tutoring Ltd');
});

it('renders a mailable through the branded layout: lockup, first-name greeting, settings footer', function () {
    Settings::set('email_footer', 'TrusTutor FZ-LLC · Dubai', SettingGroup::Mail);
    Settings::set('support_address', 'help@example.test', SettingGroup::Mail);

    $html = (new TutorApprovedMail(brandedMailProfile('Amira Khan')))->render();

    expect($html)
        ->toContain('data-brand="lockup"')
        ->toContain('/brand/trustutor-wordmark-colour-600.png')
        ->toContain('alt="TrusTutor"')
        ->toContain(MailBrand::TAGLINE)
        ->toContain('Hi Amira,')
        ->not->toContain('Hi Amira Khan')
        ->toContain('TrusTutor FZ-LLC · Dubai')
        ->toContain('help@example.test')
        ->toContain('Great news');
});

it('shows the setting-driven sender name in the footer', function () {
    Settings::set('from_name', 'Acme Tutors', SettingGroup::Mail);

    expect((new TutorApprovedMail(brandedMailProfile()))->render())->toContain('Acme Tutors');
});

it('greets by first name and falls back to "there"', function () {
    expect(MailBrand::greeting('Amira Khan'))->toBe('Hi Amira,')
        ->and(MailBrand::greeting('  Omar  '))->toBe('Hi Omar,')
        ->and(MailBrand::greeting(''))->toBe('Hi there,')
        ->and(MailBrand::greeting(null))->toBe('Hi there,');
});

it('renders the verification email through the branded theme, not Laravel’s default', function () {
    Settings::set('email_footer', 'TrusTutor FZ-LLC · Dubai', SettingGroup::Mail);
    $user = User::factory()->unverified()->create(['name' => 'Amira Khan']);

    $html = (string) (new VerifyEmailNotification)->toMail($user)->render();

    expect($html)
        ->toContain('data-brand="lockup"')
        ->toContain('/brand/trustutor-wordmark-colour-600.png')
        ->toContain('Hi Amira,')
        ->toContain('Regards,')
        ->toContain('TrusTutor FZ-LLC · Dubai')
        ->not->toContain('Hello!')
        ->not->toContain('laravel.com')
        ->not->toContain('#18181b');
});

it('renders the password-reset email through the branded theme and keeps the reset link', function () {
    $user = User::factory()->create(['name' => 'Amira Khan']);

    $html = (string) (new ResetPasswordNotification('reset-token-123'))->toMail($user)->render();

    expect($html)
        ->toContain('data-brand="lockup"')
        ->toContain('Hi Amira,')
        ->toContain('reset-token-123')
        ->not->toContain('Hello!');
});

it('has no mailable view outside the branded layout', function () {
    $views = collect(File::allFiles(resource_path('views/emails')))
        ->reject(fn ($file) => $file->getRelativePathname() === 'layout.blade.php');

    expect($views)->not->toBeEmpty();

    foreach ($views as $view) {
        expect(file_get_contents($view->getRealPath()))
            ->toContain("@extends('emails.layout')", $view->getRelativePathname());
    }
});

it('has every mailable take its sender from the settings, and every mail-channel notification use the brand', function () {
    foreach (File::allFiles(app_path('Mail')) as $file) {
        expect(file_get_contents($file->getRealPath()))
            ->toContain('UsesSettingsSender', $file->getRelativePathname())
            ->toContain('settingsFromAddress()', $file->getRelativePathname());
    }

    foreach (File::allFiles(app_path('Notifications')) as $file) {
        $source = file_get_contents($file->getRealPath());

        if (str_contains($source, 'toMail(')) {
            expect($source)->toContain('MailBrand::brandNotification(', $file->getRelativePathname());
        }
    }
});

it('does not use the published Laravel theme colours any more', function () {
    $css = file_get_contents(resource_path('views/vendor/mail/html/themes/default.css'));

    expect($css)->toContain('#64011D')->not->toContain('#18181b');
});
