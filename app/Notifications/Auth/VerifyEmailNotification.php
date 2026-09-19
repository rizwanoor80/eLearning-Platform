<?php

namespace App\Notifications\Auth;

use App\Support\Mail\UsesSettingsSender;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Laravel's verification email, sent from the admin-configured sender (the
 * `from_name` / `from_address` / `reply_to` settings, read at send time —
 * CP1 box 8) and queued like every other email the platform sends.
 */
class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
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
