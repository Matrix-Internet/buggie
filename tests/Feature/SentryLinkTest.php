<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Project;
use App\Models\Report;
use App\Models\WidgetKey;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A report filed from a page running Sentry carries Sentry's ids, and the issue page
 * turns them into links to the project's Sentry organisation.
 *
 * The ids come from an anonymous endpoint and end up in an href, so most of this is
 * about what is refused.
 */
class SentryLinkTest extends TestCase
{
    use RefreshDatabase;

    private const EVENT = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private const TRACE = '0123456789abcdef0123456789abcdef';

    private const REPLAY = 'fedcba9876543210fedcba9876543210';

    private ?WidgetKey $key = null;

    private function ingest(mixed $sentry): Report
    {
        Queue::fake();

        if ($this->key === null) {
            [$workspace] = $this->workspaceWithMember(slug: 'acme');

            $this->key = app(Tenancy::class)->run($workspace, fn () => WidgetKey::factory()->create([
                'project_id' => Project::factory()->create()->id,
            ]));
        }

        $id = $this->postJson("/api/ingest/{$this->key->public_key}", [
            'title' => 'Checkout fails',
            'environment' => ['url' => 'https://acme.test/checkout', 'sentry' => $sentry],
        ])->assertStatus(202)->json('id');

        return Report::withoutGlobalScopes()->findOrFail($id);
    }

    #[Test]
    public function well_formed_ids_are_kept(): void
    {
        $report = $this->ingest([
            'event_id' => strtoupper(self::EVENT),
            'trace_id' => self::TRACE,
            'replay_id' => self::REPLAY,
        ]);

        $this->assertSame([
            'event_id' => self::EVENT,
            'trace_id' => self::TRACE,
            'replay_id' => self::REPLAY,
        ], $report->environment['sentry']);
    }

    #[Test]
    public function anything_that_is_not_an_id_is_dropped(): void
    {
        $report = $this->ingest([
            'event_id' => self::EVENT,
            'trace_id' => 'javascript:alert(1)//0123456789abcdef',
            'replay_id' => '../../settings',
            'extra' => 'kept nowhere',
        ]);

        $this->assertSame(['event_id' => self::EVENT], $report->environment['sentry']);

        $this->assertArrayNotHasKey('sentry', $this->ingest('not an object')->environment);
        $this->assertArrayNotHasKey('sentry', $this->ingest(['trace_id' => 'short'])->environment);
    }

    #[Test]
    public function the_issue_page_links_to_the_projects_sentry(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(slug: 'acme');

        $issue = app(Tenancy::class)->run($workspace, function () {
            $project = Project::factory()->create();
            $project->forceFill(['settings' => ['sentry_url' => 'https://acme.sentry.io']])->save();

            $issue = Issue::factory()->create(['project_id' => $project->id]);

            Report::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id])
                ->forceFill(['environment' => ['sentry' => ['event_id' => self::EVENT, 'replay_id' => self::REPLAY]]])
                ->save();

            return $issue;
        });

        $this->actingAs($staff)
            ->get($this->workspaceUrl($workspace, "/issues/{$issue->key}"))
            ->assertInertia(fn ($page) => $page
                ->where('diagnostics.sentry.0.url', 'https://acme.sentry.io/issues/?query='.self::EVENT)
                ->where('diagnostics.sentry.1.url', 'https://acme.sentry.io/replays/'.self::REPLAY.'/')
                ->count('diagnostics.sentry', 2));
    }

    #[Test]
    public function without_a_sentry_address_the_ids_are_shown_unlinked(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(slug: 'acme');

        $issue = app(Tenancy::class)->run($workspace, function () {
            $project = Project::factory()->create();
            $issue = Issue::factory()->create(['project_id' => $project->id]);

            Report::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id])
                ->forceFill(['environment' => ['sentry' => ['trace_id' => self::TRACE]]])
                ->save();

            return $issue;
        });

        $this->actingAs($staff)
            ->get($this->workspaceUrl($workspace, "/issues/{$issue->key}"))
            ->assertInertia(fn ($page) => $page
                ->where('diagnostics.sentry.0.id', self::TRACE)
                ->where('diagnostics.sentry.0.url', null));
    }

    #[Test]
    public function the_sentry_address_is_saved_from_project_settings(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $project = app(Tenancy::class)->run($workspace, fn () => Project::factory()->create(['name' => 'Web']));

        $url = $this->workspaceUrl($workspace, "/projects/{$project->slug}");

        $this->actingAs($owner)
            ->put($url, ['name' => 'Web', 'sentry_url' => 'javascript:alert(1)'])
            ->assertSessionHasErrors('sentry_url');

        $this->actingAs($owner)
            ->put($url, ['name' => 'Web', 'sentry_url' => 'https://sentry.example.com/organizations/acme/'])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'https://sentry.example.com/organizations/acme',
            $project->fresh()->sentryUrl(),
        );
    }
}
