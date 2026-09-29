<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PaymentFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Events\WebhookReceived;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A declined card used to drop a paying workspace to the free plan at once, with no
 * word to anybody. Now the owner is told, the plan is kept while Stripe retries, and
 * only a decline left unpaid past the grace period costs the plan.
 */
class DunningTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'buggie.hosted' => true,
            'plans.plans.studio.prices.EUR.month.price_id' => 'price_studio',
            'plans.past_due_grace_days' => 14,
        ]);

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'acme');
        $this->workspace->forceFill(['stripe_id' => 'cus_acme', 'trial_ends_at' => now()->subMonth()])->save();

        DB::table('subscriptions')->insert([
            'workspace_id' => $this->workspace->id,
            'type' => 'default',
            'stripe_id' => 'sub_acme',
            'stripe_status' => 'active',
            'stripe_price' => 'price_studio',
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function webhook(string $type): void
    {
        WebhookReceived::dispatch([
            'type' => $type,
            'data' => ['object' => [
                'customer' => 'cus_acme',
                'amount_due' => 1900,
                'currency' => 'eur',
                'next_payment_attempt' => now()->addDays(3)->timestamp,
            ]],
        ]);
    }

    private function pastDue(): void
    {
        DB::table('subscriptions')->where('stripe_id', 'sub_acme')->update(['stripe_status' => 'past_due']);
    }

    private function planName(): string
    {
        return Workspace::find($this->workspace->id)->plan()->name();
    }

    #[Test]
    public function the_owner_is_emailed_and_the_plan_is_kept(): void
    {
        Notification::fake();
        $member = User::factory()->create();
        $this->workspace->members()->attach($member->id, ['role' => WorkspaceRole::Admin->value, 'joined_at' => now()]);
        $paid = $this->planName();

        $this->pastDue();
        $this->webhook('invoice.payment_failed');

        Notification::assertSentTo($this->owner, PaymentFailed::class);
        // Only an owner can change the card, so only an owner is asked to.
        Notification::assertNotSentTo($member, PaymentFailed::class);

        $this->assertNotNull($this->workspace->fresh()->payment_failed_at);
        $this->assertSame('Studio', $paid);
        $this->assertSame('Studio', $this->planName());
    }

    #[Test]
    public function the_plan_ends_once_the_grace_period_passes_unpaid(): void
    {
        Notification::fake();
        $paid = $this->planName();

        $this->pastDue();
        $this->webhook('invoice.payment_failed');

        $this->travel(10)->days();
        // A retry that fails again does not restart the clock.
        $this->webhook('invoice.payment_failed');
        $this->assertSame($paid, $this->planName());

        $this->travel(5)->days();
        $this->assertSame('Studio', $paid);
        $this->assertSame('Free', $this->planName());
        Notification::assertSentToTimes($this->owner, PaymentFailed::class, 2);
    }

    #[Test]
    public function a_successful_payment_clears_it(): void
    {
        Notification::fake();

        $this->pastDue();
        $this->webhook('invoice.payment_failed');
        $this->webhook('invoice.paid');

        $this->assertNull($this->workspace->fresh()->payment_failed_at);
    }

    #[Test]
    public function staff_see_a_banner_while_it_is_overdue(): void
    {
        Notification::fake();
        $this->pastDue();
        $this->webhook('invoice.payment_failed');

        $this->actingAs($this->owner)
            ->get($this->workspaceUrl($this->workspace, '/'))
            ->assertInertia(fn ($page) => $page->where('billing.payment_failed', true));
    }

    #[Test]
    public function the_email_says_what_happens_and_when(): void
    {
        $this->workspace->forceFill(['payment_failed_at' => now()])->save();

        $mail = (new PaymentFailed($this->workspace->fresh(), 1900, 'eur', now()->addDays(3)->timestamp))
            ->toMail($this->owner);
        $text = implode("\n", $mail->introLines).implode("\n", $mail->outroLines);

        $this->assertStringContainsString('€19.00', $text);
        $this->assertStringContainsString(now()->addDays(3)->toFormattedDayDateString(), $text);
        $this->assertStringContainsString(now()->addDays(14)->toFormattedDayDateString(), $text);
    }
}
