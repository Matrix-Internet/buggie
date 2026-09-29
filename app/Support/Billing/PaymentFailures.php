<?php

namespace App\Support\Billing;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PaymentFailed;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Dunning: telling a workspace its card was declined, before anything is taken away.
 *
 * Stripe retries a failed invoice on its own schedule. Until now the first failure
 * marked the subscription past due, Cashier stopped counting it as active, and the
 * workspace fell to the free plan with no word to anybody. Now the plan is kept for
 * a grace period (Workspace::plan()), the owner is emailed at every failed attempt,
 * and the first successful payment clears it.
 *
 * Registered in AppServiceProvider rather than discovered, so it cannot be
 * registered twice.
 */
class PaymentFailures
{
    public function handle(WebhookReceived $event): void
    {
        $type = $event->payload['type'] ?? null;
        $invoice = $event->payload['data']['object'] ?? [];

        // Either success event clears it: `php artisan cashier:webhook` subscribes to
        // invoice.payment_succeeded, and an endpoint set up by hand may send invoice.paid.
        $succeeded = in_array($type, ['invoice.paid', 'invoice.payment_succeeded'], true);

        if (! $succeeded && $type !== 'invoice.payment_failed') {
            return;
        }

        $workspace = Cashier::findBillable($invoice['customer'] ?? null);

        // A deleted workspace's subscription was cancelled when it was deleted; a
        // late webhook about it tells nobody anything.
        if (! $workspace instanceof Workspace || $workspace->trashed()) {
            return;
        }

        if ($succeeded) {
            $workspace->forceFill(['payment_failed_at' => null])->save();

            return;
        }

        // The start of this run of failures, not the latest: the grace period is
        // measured from the first decline, however many retries follow.
        if ($workspace->payment_failed_at === null) {
            $workspace->forceFill(['payment_failed_at' => now()])->save();
        }

        $notice = new PaymentFailed(
            $workspace,
            amount: (int) ($invoice['amount_due'] ?? 0),
            currency: (string) ($invoice['currency'] ?? ''),
            nextAttempt: isset($invoice['next_payment_attempt']) ? (int) $invoice['next_payment_attempt'] : null,
        );

        foreach (self::billingOwners($workspace) as $owner) {
            $owner->notify($notice);
        }
    }

    /** @return iterable<User> The people who can change the card: the owners. */
    private static function billingOwners(Workspace $workspace): iterable
    {
        return $workspace->members()->wherePivot('role', WorkspaceRole::Owner->value)->get();
    }
}
