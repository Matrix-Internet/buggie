<?php

namespace Tests\Feature;

use App\Enums\WatchReason;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\UnsubscribeController;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\IssueDigest;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Every notification email can be stopped from the email itself, without signing in,
 * and opening the link alone never stops anything.
 */
class UnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $user;

    private Issue $issue;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->workspace, $this->user] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $this->issue = app(Tenancy::class)->run($this->workspace, function () {
            $issue = Issue::factory()->create(['project_id' => Project::factory()->create()->id]);
            $issue->watch($this->user, WatchReason::Manual);

            return $issue;
        });
    }

    private function url(): string
    {
        return UnsubscribeController::url($this->user, $this->issue);
    }

    #[Test]
    public function the_digest_carries_a_link_and_one_click_headers(): void
    {
        $mail = (new IssueDigest($this->issue, collect()))->toMail($this->user);

        $this->assertStringContainsString($this->url(), implode("\n", $mail->outroLines));

        $email = new Email;
        foreach ($mail->callbacks as $callback) {
            $callback($email);
        }

        $this->assertSame('<'.$this->url().'>', $email->getHeaders()->get('List-Unsubscribe')->getBodyAsString());
        $this->assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString());
    }

    #[Test]
    public function opening_the_link_changes_nothing(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('unsubscribe')->where('issue.key', $this->issue->key));

        $this->assertTrue($this->user->fresh()->wantsEmail());
        $this->assertTrue($this->issue->watchers()->whereKey($this->user->id)->exists());
    }

    #[Test]
    public function a_tampered_link_is_refused(): void
    {
        $other = User::factory()->create();
        $forged = str_replace("unsubscribe/{$this->user->id}", "unsubscribe/{$other->id}", $this->url());

        $this->get($forged)->assertForbidden();
        $this->post($forged, ['scope' => 'all'])->assertForbidden();

        $this->assertTrue($other->fresh()->wantsEmail());
    }

    #[Test]
    public function stopping_all_email_keeps_the_in_app_list(): void
    {
        $this->post($this->url(), ['scope' => 'all'])->assertRedirect();

        $user = $this->user->fresh();
        $this->assertFalse($user->wantsEmail());
        $this->assertSame(['database'], (new IssueDigest($this->issue, collect()))->via($user));
    }

    #[Test]
    public function stopping_one_issue_only_unwatches_it(): void
    {
        $this->post($this->url(), ['scope' => 'issue'])->assertRedirect();

        $this->assertFalse($this->issue->watchers()->whereKey($this->user->id)->exists());
        $this->assertTrue($this->user->fresh()->wantsEmail());
    }

    #[Test]
    public function a_mail_clients_one_click_post_needs_no_session_or_token(): void
    {
        // No CSRF token, no cookies: what Gmail's button sends.
        $this->call('POST', $this->url(), ['List-Unsubscribe' => 'One-Click'])->assertOk();

        $this->assertFalse($this->user->fresh()->wantsEmail());
    }

    #[Test]
    public function email_can_be_switched_back_on_and_is_kept_when_saving_reasons(): void
    {
        $this->post($this->url(), ['scope' => 'all']);
        $url = $this->workspaceUrl($this->workspace, '/settings/notifications');

        // Saving reasons without mentioning email leaves it off.
        $this->actingAs($this->user->fresh())->patch($url, ['reasons' => ['assigned' => true]]);
        $this->assertFalse($this->user->fresh()->wantsEmail());

        $this->actingAs($this->user->fresh())->patch($url, ['reasons' => ['assigned' => true], 'email' => true]);
        $this->assertTrue($this->user->fresh()->wantsEmail());
    }
}
