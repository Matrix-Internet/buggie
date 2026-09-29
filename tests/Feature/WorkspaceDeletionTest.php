<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * Deleting a workspace has to stop its billing. It used to soft-delete the row and
 * leave the Stripe subscription renewing every month for a workspace nobody could
 * reach any more.
 */
class WorkspaceDeletionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{method: string, url: string}> */
    private array $stripeCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashier.secret' => 'sk_test_fake']);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    /** Answer every Stripe request with the given status and error code, and record it. */
    private function stripeAnswers(int $status, ?string $errorCode = null): void
    {
        $test = $this;

        ApiRequestor::setHttpClient(new class($test, $status, $errorCode) implements ClientInterface
        {
            public function __construct(private $test, private int $status, private ?string $code) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->test->recordStripeCall($method, $absUrl);

                $body = $this->status === 200
                    ? ['id' => basename(parse_url($absUrl, PHP_URL_PATH)), 'object' => 'subscription', 'status' => 'canceled']
                    : ['error' => ['type' => 'invalid_request_error', 'code' => $this->code, 'message' => 'No.']];

                return [json_encode($body), $this->status, []];
            }
        });
    }

    public function recordStripeCall(string $method, string $url): void
    {
        $this->stripeCalls[] = ['method' => $method, 'url' => $url];
    }

    private function subscribe(Workspace $workspace, string $stripeId, array $state = []): void
    {
        DB::table('subscriptions')->insert([
            'workspace_id' => $workspace->id,
            'type' => 'default',
            'stripe_id' => $stripeId,
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            ...$state,
        ]);
    }

    private function deleteWorkspace(Workspace $workspace, $owner)
    {
        return $this->actingAs($owner)
            ->from($this->workspaceUrl($workspace, '/settings/workspace'))
            ->delete($this->workspaceUrl($workspace, '/settings/workspace'), ['confirm' => $workspace->slug]);
    }

    #[Test]
    public function deleting_a_workspace_cancels_its_subscription_at_once(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $this->subscribe($workspace, 'sub_live');
        $this->stripeAnswers(200);

        $this->deleteWorkspace($workspace, $owner)->assertRedirect();

        $this->assertSoftDeleted($workspace);
        $this->assertSame([['method' => 'delete', 'url' => 'https://api.stripe.com/v1/subscriptions/sub_live']], $this->stripeCalls);
        $this->assertSame('canceled', DB::table('subscriptions')->where('stripe_id', 'sub_live')->value('stripe_status'));
    }

    #[Test]
    public function nothing_is_deleted_when_stripe_will_not_cancel(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $this->subscribe($workspace, 'sub_live');
        $this->stripeAnswers(500);

        $this->deleteWorkspace($workspace, $owner)->assertSessionHasErrors('confirm');

        $this->assertNotSoftDeleted($workspace);
    }

    #[Test]
    public function a_subscription_stripe_no_longer_has_does_not_block_deletion(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $this->subscribe($workspace, 'sub_gone');
        $this->stripeAnswers(404, 'resource_missing');

        $this->deleteWorkspace($workspace, $owner)->assertSessionHasNoErrors();

        $this->assertSoftDeleted($workspace);
        $this->assertNotNull(DB::table('subscriptions')->where('stripe_id', 'sub_gone')->value('ends_at'));
    }

    #[Test]
    public function subscriptions_that_will_not_charge_again_are_left_alone(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $this->subscribe($workspace, 'sub_ending', ['ends_at' => now()->addWeek()]);
        $this->subscribe($workspace, 'sub_expired', ['stripe_status' => 'incomplete_expired']);
        $this->stripeAnswers(200);

        $this->deleteWorkspace($workspace, $owner)->assertSessionHasNoErrors();

        $this->assertSoftDeleted($workspace);
        $this->assertSame([], $this->stripeCalls);
    }

    #[Test]
    public function only_the_owner_can_delete(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');
        $admin = \App\Models\User::factory()->create();
        $workspace->members()->attach($admin->id, ['role' => WorkspaceRole::Admin->value, 'joined_at' => now()]);
        $this->subscribe($workspace, 'sub_live');
        $this->stripeAnswers(200);

        $this->deleteWorkspace($workspace, $admin)->assertForbidden();

        $this->assertNotSoftDeleted($workspace);
        $this->assertSame([], $this->stripeCalls);
    }
}
