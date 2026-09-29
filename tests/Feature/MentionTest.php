<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\InAppNotification;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * @-mentions arrive from the browser as a user id and a label, and both are believed
 * by nothing: the id decides who watches and is told, and the label is shown to
 * everybody who reads the text — including clients, who read the team as the
 * workspace.
 */
class MentionTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $staff;

    private User $colleague;

    private User $client;

    private Project $project;

    private Issue $issue;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->workspace, $this->staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');
        $this->workspace->update(['name' => 'Matrix']);

        $this->colleague = User::factory()->create(['name' => 'Dave Colleague']);
        $this->workspace->members()->attach($this->colleague->id, ['role' => WorkspaceRole::Member->value, 'joined_at' => now()]);

        $this->client = User::factory()->create(['name' => 'Client Person']);
        $this->workspace->members()->attach($this->client->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);

        [$this->project, $this->issue] = app(Tenancy::class)->run($this->workspace, function () {
            $project = Project::factory()->create(['key' => 'WEB']);

            return [$project, Issue::factory()->clientVisible()->create(['project_id' => $project->id])];
        });

        $this->project->clients()->attach($this->client->id, ['role' => 'client_manager']);
    }

    /** @return array<string, mixed> */
    private function doc(int|string $id, string $label, string $after = ' can you look?'): array
    {
        return ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'mention', 'attrs' => ['id' => $id, 'label' => $label]],
                ['type' => 'text', 'text' => $after],
            ],
        ]]];
    }

    private function comment(User $author, array $body, bool $internal = true)
    {
        return $this->actingAs($author)->post(
            $this->workspaceUrl($this->workspace, "/issues/{$this->issue->key}/comments"),
            ['body' => $body, 'is_internal' => $internal],
        );
    }

    private function mentionsOf(User $user): int
    {
        return InAppNotification::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('reason', 'mentioned')
            ->count();
    }

    private function storedBody(): array
    {
        return $this->issue->comments()->latest('id')->firstOrFail()->body;
    }

    #[Test]
    public function mentioning_a_colleague_makes_them_a_watcher_and_tells_them(): void
    {
        $this->comment($this->staff, $this->doc($this->colleague->id, 'Dave'))->assertSessionHasNoErrors();

        $this->assertTrue($this->issue->watchers()->whereKey($this->colleague->id)->exists());
        $this->assertSame(1, $this->mentionsOf($this->colleague));
    }

    #[Test]
    public function the_label_is_the_persons_real_name_not_what_the_browser_sent(): void
    {
        $this->comment($this->staff, $this->doc((string) $this->colleague->id, 'The CEO'));

        $this->assertEquals(
            ['id' => $this->colleague->id, 'label' => 'Dave Colleague'],
            $this->storedBody()['content'][0]['content'][0]['attrs'],
        );
    }

    #[Test]
    public function somebody_outside_the_workspace_is_just_text(): void
    {
        [, $stranger] = $this->workspaceWithMember(slug: 'globex');

        $this->comment($this->staff, $this->doc($stranger->id, 'Stranger'));

        $this->assertEquals(['type' => 'text', 'text' => '@Stranger'], $this->storedBody()['content'][0]['content'][0]);
        $this->assertFalse($this->issue->watchers()->whereKey($stranger->id)->exists());
        $this->assertSame(0, $this->mentionsOf($stranger));
    }

    #[Test]
    public function a_client_cannot_be_mentioned_in_an_internal_note(): void
    {
        $this->comment($this->staff, $this->doc($this->client->id, 'Client Person'), internal: true);

        $this->assertSame('text', $this->storedBody()['content'][0]['content'][0]['type']);
        $this->assertFalse($this->issue->watchers()->whereKey($this->client->id)->exists());
        $this->assertSame(0, $this->mentionsOf($this->client));
    }

    #[Test]
    public function a_client_who_can_see_the_issue_can_be_mentioned_publicly(): void
    {
        $this->comment($this->staff, $this->doc($this->client->id, 'Client Person'), internal: false);

        $this->assertSame('mention', $this->storedBody()['content'][0]['content'][0]['type']);
        $this->assertSame(1, $this->mentionsOf($this->client));
    }

    #[Test]
    public function a_clients_mentions_are_kept_as_text_only(): void
    {
        $this->comment($this->client, $this->doc($this->colleague->id, 'Dave'), internal: false)
            ->assertSessionHasNoErrors();

        $this->assertSame('text', $this->storedBody()['content'][0]['content'][0]['type']);
        $this->assertSame(0, $this->mentionsOf($this->colleague));
    }

    #[Test]
    public function a_client_reads_a_mentioned_colleague_as_the_workspace(): void
    {
        // Watching, so the client is also sent the excerpt.
        $this->issue->watch($this->client, \App\Enums\WatchReason::Reported);

        $this->comment($this->staff, $this->doc($this->colleague->id, 'Dave', ' is on it'), internal: false);

        $page = $this->actingAs($this->client)
            ->get($this->workspaceUrl($this->workspace, "/issues/{$this->issue->key}"));

        $page->assertInertia(fn ($p) => $p
            ->where('comments.0.body.content.0.content.0.attrs.label', 'Matrix')
            ->where('mentionable', []));
        $this->assertStringNotContainsString('Dave Colleague', $page->getContent());

        $excerpt = InAppNotification::withoutGlobalScopes()
            ->where('user_id', $this->client->id)
            ->where('reason', 'commented')
            ->value('data');
        $this->assertStringContainsString('@Matrix is on it', json_encode($excerpt));
        $this->assertStringNotContainsString('Dave', json_encode($excerpt));

        // Staff read the name.
        $this->actingAs($this->staff)
            ->get($this->workspaceUrl($this->workspace, "/issues/{$this->issue->key}"))
            ->assertInertia(fn ($p) => $p->where('comments.0.body.content.0.content.0.attrs.label', 'Dave Colleague'));
    }

    #[Test]
    public function the_space_after_a_mention_survives_the_request(): void
    {
        $this->comment($this->staff, $this->doc($this->colleague->id, 'Dave', ' is on it'));

        $this->assertSame('@Dave Colleague is on it', $this->issue->comments()->latest('id')->value('body_text'));
    }

    #[Test]
    public function a_workspace_that_shows_staff_names_shows_them_in_mentions_too(): void
    {
        $this->workspace->update(['settings' => [...($this->workspace->settings ?? []), 'show_staff_names_to_clients' => true]]);

        $this->comment($this->staff, $this->doc($this->colleague->id, 'Dave'), internal: false);

        $this->actingAs($this->client)
            ->get($this->workspaceUrl($this->workspace, "/issues/{$this->issue->key}"))
            ->assertInertia(fn ($p) => $p->where('comments.0.body.content.0.content.0.attrs.label', 'Dave Colleague'));
    }

    #[Test]
    public function a_description_edit_tells_only_the_newly_mentioned(): void
    {
        $url = $this->workspaceUrl($this->workspace, "/issues/{$this->issue->key}");

        $this->actingAs($this->staff)->patch($url, ['description' => $this->doc($this->colleague->id, 'Dave')])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->patch($url, ['description' => $this->doc($this->colleague->id, 'Dave', ' typo fixed')]);

        $this->assertSame(1, $this->mentionsOf($this->colleague));
        $this->assertTrue($this->issue->watchers()->whereKey($this->colleague->id)->exists());
    }

    #[Test]
    public function a_mention_added_by_editing_a_comment_is_told_once(): void
    {
        $this->comment($this->staff, ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Looking']]]]]);
        $comment = $this->issue->comments()->latest('id')->firstOrFail();
        $url = $this->workspaceUrl($this->workspace, "/comments/{$comment->id}");

        $this->actingAs($this->staff)->patch($url, ['body' => $this->doc($this->colleague->id, 'Dave')])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->patch($url, ['body' => $this->doc($this->colleague->id, 'Dave', ' again')]);

        $this->assertSame(1, $this->mentionsOf($this->colleague));
    }

    #[Test]
    public function staff_are_offered_clients_marked_as_clients(): void
    {
        $this->actingAs($this->staff)
            ->get($this->workspaceUrl($this->workspace, "/issues/{$this->issue->key}"))
            ->assertInertia(fn ($p) => $p->where(
                'mentionable',
                fn ($people) => collect($people)->firstWhere('id', $this->client->id)['client'] === true
                    && collect($people)->firstWhere('id', $this->colleague->id)['client'] === false,
            ));
    }
}
