<?php

namespace App\Notifications\Auth;

use App\Support\Mail\UsesSettingsSender;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Laravel's password-reset email, sent from the admin-configured sender
 * (read at send time — CP1 box 8) and queued. The reset token is a constructor
 * argument, so the queued payload is encrypted (`ShouldBeEncrypted`, read by
 * `SendQueuedNotifications` in the framework): the token is not readable in Redis
 * or in `failed_jobs`.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, UsesSettingsSender;

    public function toMail($notifiable): MailMessage
    {
        $from = $this->settingsFromAddress();
        $message = parent::toMail($notifiable)->from($from->address, $from->name);

        if ($replyTo = $this->settingsReplyToAddress()) {
            $message->replyTo($replyTo->address);
        }

        return $message;
    }
}
