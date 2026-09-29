<?php

namespace App\Notifications;

use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Cashier;

/**
 * A card was declined. Sent to the workspace's owners at every failed attempt, with
 * when Stripe will try again and when the plan will end if nothing changes.
 */
class PaymentFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Workspace $workspace,
        public int $amount,
        public string $currency,
        public ?int $nextAttempt,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = $this->currency !== '' ? Cashier::formatAmount($this->amount, $this->currency) : null;
        $graceEnds = ($this->workspace->payment_failed_at ?? now())
            ->copy()
            ->addDays((int) config('plans.past_due_grace_days', 14));

        $mail = (new MailMessage)
            ->subject("Payment failed for {$this->workspace->name} on Buggie")
            ->line(($amount ? "We could not take the payment of {$amount}" : 'We could not take your latest payment')
                ." for {$this->workspace->name}. Your card was declined or has expired.");

        if ($this->nextAttempt !== null) {
            $mail->line('We will try again on '.Carbon::createFromTimestamp($this->nextAttempt)->toFormattedDayDateString().'.');
        }

        return $mail
            ->line('Nothing has changed yet. If the payment still has not gone through by '
                .$graceEnds->toFormattedDayDateString().', the workspace moves to the free plan. '
                .'Your projects, issues and reports are kept either way.')
            ->action('Update payment details', workspace_url($this->workspace->slug, 'settings/billing'));
    }
}
