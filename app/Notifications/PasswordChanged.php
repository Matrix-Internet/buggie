<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent whenever an account's password changes, by whatever route.
 *
 * This is how somebody finds out their account was taken over: the attacker can
 * change the password, but not stop this arriving at the address it was changed
 * away from. Queued, like verification, so a mail outage cannot fail a reset.
 */
class PasswordChanged extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Buggie password was changed')
            ->line('The password for '.$notifiable->email.' was changed just now, and every other signed-in browser was signed out.')
            ->line('If that was you, there is nothing to do.')
            ->line('If it was not, reset your password straight away. The reset link goes to this address, so whoever changed it cannot stop you.')
            ->action('Reset my password', central_url('/forgot-password'));
    }
}
