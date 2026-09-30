<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Billing\Plan;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who is using the service.
 *
 * Hosted service only, behind the `hosted` middleware, so a self-hosted install
 * answers 404 here. A self-hosted install has one workspace and its operator is
 * already inside it, so there is nothing here for them. This exists because
 * running a service for other people means answering "did anybody sign up" without
 * opening a database console, which was the honest answer before this existed.
 *
 * **Read only, deliberately.** The dangerous operator tools are the ones that reach
 * into a customer's data — impersonation above all — and the first version of a
 * console is the wrong place to add them. Everything here is a count or a date that
 * the service already needs for billing. Nothing opens an issue, reads a comment or
 * touches a report's contents.
 *
 * Every query crosses tenancy on purpose, which is why each one says
 * `withoutGlobalScopes()` out loud rather than relying on running outside a tenant.
 */
class ConsoleController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $this->authorize('operate');

        $search = trim((string) $request->query('q'));

        return Inertia::render('operator/console', [
            'summary' => $this->summary(),
            'workspaces' => $this->workspaces($search),
            'filters' => ['q' => $search],
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        $month = now()->startOfMonth();

        return [
            'workspaces' => Workspace::withoutGlobalScopes()->count(),
            'users' => User::count(),
            'projects' => Project::withoutGlobalScopes()->count(),
            'issues' => Issue::withoutGlobalScopes()->count(),

            // The metered quantity, so the one worth watching across the whole
            // service rather than per workspace.
            'reports_this_month' => Report::withoutGlobalScopes()
                ->where('created_at', '>=', $month)->count(),

            'signups_this_week' => Workspace::withoutGlobalScopes()
                ->where('created_at', '>=', now()->subWeek())->count(),

            // Trials about to lapse, which is the only thing on this page that is
            // ever urgent.
            'trials_ending' => Workspace::withoutGlobalScopes()
                ->whereNotNull('trial_ends_at')
                ->whereBetween('trial_ends_at', [now(), now()->addDays(3)])
                ->count(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function workspaces(string $search): array
    {
        return Workspace::withoutGlobalScopes()
            ->when($search !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('name', 'ilike', "%{$search}%")
                    ->orWhere('slug', 'ilike', "%{$search}%"),
            ))
            // subscriptions as well as the owner: plan() and subscribed() both reach
            // for them, and strict mode turns a lazy load inside a 200-row loop into
            // a 500 rather than into two hundred quiet queries.
            ->with(['owner:id,name,email', 'subscriptions'])
            ->withCount([
                'projects as projects_count' => fn ($q) => $q->withoutGlobalScopes(),
                'members as members_count',
            ])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(function (Workspace $workspace) {
                $plan = $workspace->plan();

                return [
                    'slug' => $workspace->slug,
                    'name' => $workspace->name,
                    'url' => workspace_url($workspace->slug),

                    // The owner's address, because the only support channel that
                    // exists is replying to them.
                    'owner' => $workspace->owner?->only(['name', 'email']),

                    'created_at' => $workspace->created_at?->toDateString(),
                    'plan' => $plan->name(),
                    'trial_ends_at' => $workspace->trial_ends_at?->toDateString(),
                    'on_trial' => (bool) $workspace->trial_ends_at?->isFuture(),
                    'subscribed' => $workspace->subscribed(),
                    'projects' => $workspace->projects_count,
                    'members' => $workspace->members_count,
                    'reports_this_month' => $this->reportsThisMonth($workspace),

                    // Whether anything is actually happening in there. A workspace
                    // that signed up and never returned is a different problem from
                    // one that is busy, and the difference is invisible on a list
                    // sorted by signup date.
                    'last_activity' => $this->lastActivity($workspace)?->toDateString(),
                ];
            })
            ->all();
    }

    private function reportsThisMonth(Workspace $workspace): int
    {
        return Report::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    private function lastActivity(Workspace $workspace): ?\Illuminate\Support\Carbon
    {
        // The newest issue touched. Cheap, and a good enough proxy: a workspace where
        // nobody has touched an issue is a workspace nobody is using.
        $at = Issue::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->max('updated_at');

        return $at === null ? null : \Illuminate\Support\Carbon::parse($at);
    }
}
