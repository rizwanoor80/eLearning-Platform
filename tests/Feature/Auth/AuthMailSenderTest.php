<?php

use App\Enums\SettingGroup;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Support\Facades\Settings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Email;

/**
 * The last email the (array) mail transport received.
 */
function amLastEmail(): Email
{
    $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last();
    $email = $sent->getOriginalMessage();
    assert($email instanceof Email);

    return $email;
}

it('is still Laravel’s verification and reset notification, queued', function () {
    expect(new VerifyEmailNotification)->toBeInstanceOf(VerifyEmail::class)->toBeInstanceOf(ShouldQueue::class)
        ->and(new ResetPasswordNotification('t'))->toBeInstanceOf(ResetPassword::class)->toBeInstanceOf(ShouldQueue::class);
});

it('sends the verification email from the settings sender (R36 Fortify mails)', function () {
    Settings::set('from_name', 'Support Team', SettingGroup::Mail);
    Settings::set('from_address', 'hello@example.test', SettingGroup::Mail);
    Settings::set('reply_to', 'replies@example.test', SettingGroup::Mail);
    $user = User::factory()->unverified()->create();

    $user->sendEmailVerificationNotification();

    $email = amLastEmail();
    expect($email->getFrom()[0]->getAddress())->toBe('hello@example.test')
        ->and($email->getFrom()[0]->getName())->toBe('Support Team')
        ->and($email->getReplyTo()[0]->getAddress())->toBe('replies@example.test')
        ->and($email->getTo()[0]->getAddress())->toBe($user->email)
        ->and($email->getSubject())->toBe('Verify your email address');
});

it('sends the password-reset email from the settings sender, with a working link', function () {
    Settings::set('from_name', 'Support Team', SettingGroup::Mail);
    Settings::set('from_address', 'hello@example.test', SettingGroup::Mail);
    $user = User::factory()->create();

    $user->sendPasswordResetNotification('reset-token-123');

    $email = amLastEmail();
    expect($email->getFrom()[0]->getAddress())->toBe('hello@example.test')
        ->and($email->getFrom()[0]->getName())->toBe('Support Team')
        ->and($email->getReplyTo())->toBe([])
        ->and($email->getTextBody().$email->getHtmlBody())->toContain('reset-token-123');
});

it('reads the sender at send time and falls back to the configured address when none is stored', function () {
    config(['mail.from.address' => 'fallback@example.test']);
    $user = User::factory()->create();

    $user->sendPasswordResetNotification('t1');
    expect(amLastEmail()->getFrom()[0]->getAddress())->toBe('fallback@example.test');

    Settings::set('from_address', 'changed@example.test', SettingGroup::Mail);
    $user->sendPasswordResetNotification('t2');
    expect(amLastEmail()->getFrom()[0]->getAddress())->toBe('changed@example.test');
});

it('is what a reset request and a resend actually dispatch', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->post(route('password.email'), ['email' => $user->email]);
    $this->actingAs($user)->post(route('verification.send'));

    Notification::assertSentTo($user, ResetPasswordNotification::class);
    Notification::assertSentTo($user, VerifyEmailNotification::class);
});
