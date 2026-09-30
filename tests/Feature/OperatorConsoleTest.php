<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The hosted service's operator console.
 *
 * Hosted only, through the `hosted` middleware: on a self-hosted install the route is
 * registered but answers 404, the same as an address that does not exist.
 */
class OperatorConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Who counts as an operator. Runtime config, so unlike the routes this can be
        // set after the application has booted.
        config(['buggie.operators' => ['ops@buggie.eu']]);
    }

    /**
     * Run as the hosted service for this test.
     *
     * Called explicitly rather than done in setUp, because one test here needs the
     * self-hosted answer. The `hosted` middleware reads config per request, so this
     * can be set after the application has booted.
     */
    private function asHostedService(): void
    {
        config(['buggie.hosted' => true]);
    }

    private function operator(): User
    {
        return User::factory()->create(['email' => 'ops@buggie.eu']);
    }

    #[Test]
    public function an_operator_sees_every_workspace_on_the_service(): void
    {
        $this->asHostedService();

        [$acme] = $this->workspaceWithMember(slug: 'acme');
        [$other] = $this->workspaceWithMember(WorkspaceRole::Owner, 'northwind');

        $this->actingAs($this->operator())
            ->get($this->centralUrl('/operator'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('operator/console')
                ->where('summary.workspaces', 2)
                ->has('workspaces', 2));
    }

    #[Test]
    public function a_workspace_owner_is_not_an_operator(): void
    {
        $this->asHostedService();

        // The whole point of the gate. Owning a workspace on the service says nothing
        // about being allowed to see everybody else's.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)
            ->get($this->centralUrl('/operator'))
            ->assertForbidden();
    }

    #[Test]
    public function a_guest_is_sent_to_sign_in_rather_than_refused(): void
    {
        $this->asHostedService();

        // The paired control for the self-hosted test below: hosted, the route exists
        // and sends a stranger to sign in; not hosted, the same request 404s.
        $this->get($this->centralUrl('/operator'))->assertRedirect();
    }

    #[Test]
    public function the_route_does_not_exist_on_a_self_hosted_install(): void
    {
        /*
         * A 404 rather than a 403. Somebody running Buggie on their own server should
         * not find a page that exists and refuses them — that advertises a feature
         * they do not have and cannot use.
         *
         * This is the one test that does not call asHostedService(), so it sees the
         * application exactly as a self-hosted install boots it.
         */
        $this->assertFalse(config('buggie.hosted'), 'The suite is not booting self-hosted.');

        // A stranger, the paired control for the guest test above: hosted sends them
        // to sign in, self-hosted must not admit the address exists at all.
        $this->get($this->centralUrl('/operator'))->assertNotFound();

        // And an operator, so the 404 is the middleware's rather than the gate's.
        $this->actingAs($this->operator())
            ->get($this->centralUrl('/operator'))
            ->assertNotFound();
    }

    #[Test]
    public function it_reports_what_an_operator_actually_needs_to_know(): void
    {
        $this->asHostedService();

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $workspace->forceFill(['trial_ends_at' => now()->addDays(2)])->save();

        app(Tenancy::class)->run($workspace, function () use ($owner) {
            $project = app(\App\Actions\CreateProject::class)->handle(['name' => 'Site']);
            app(\App\Actions\CreateIssue::class)->handle($project, ['title' => 'One'], $owner);
        });

        $this->actingAs($this->operator())
            ->get($this->centralUrl('/operator'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.trials_ending', 1)
                ->where('summary.signups_this_week', 1)
                ->where('workspaces.0.slug', 'acme')
                ->where('workspaces.0.owner.email', $owner->email)
                ->where('workspaces.0.projects', 1)
                ->where('workspaces.0.on_trial', true));
    }

    #[Test]
    public function a_workspace_nobody_has_touched_says_so(): void
    {
        $this->asHostedService();

        // A workspace that signed up and never came back is a different problem from
        // a busy one, and the difference is invisible on a list sorted by signup date.
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($this->operator())
            ->get($this->centralUrl('/operator'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('workspaces.0.last_activity', null));
    }

    #[Test]
    public function searching_narrows_to_one_workspace(): void
    {
        $this->asHostedService();

        $this->workspaceWithMember(slug: 'acme');
        $this->workspaceWithMember(WorkspaceRole::Owner, 'northwind');

        $this->actingAs($this->operator())
            ->get($this->centralUrl('/operator?q=north'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('workspaces', 1)
                ->where('workspaces.0.slug', 'northwind'));
    }

    #[Test]
    public function no_issue_titles_or_report_contents_reach_the_page(): void
    {
        $this->asHostedService();

        /*
         * The console is counts and dates. An operator of a multi-tenant service
         * reading customers' issue titles from an admin page is exactly the thing
         * customers assume is not happening, and the restraint is worth a test rather
         * than a promise.
         */
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () use ($owner) {
            $project = app(\App\Actions\CreateProject::class)->handle(['name' => 'Site']);

            app(\App\Actions\CreateIssue::class)->handle($project, [
                'title' => 'CUSTOMER-CONFIDENTIAL-TITLE',
            ], $owner);
        });

        $html = $this->actingAs($this->operator())
            ->get($this->centralUrl('/operator'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('CUSTOMER-CONFIDENTIAL-TITLE', $html);

        // The paired control: the counts that should be there, are.
        $this->assertStringContainsString('acme', $html);
    }
}
