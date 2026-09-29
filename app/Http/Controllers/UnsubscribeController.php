<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Stopping notification email without signing in.
 *
 * The signed URL is the whole credential, like the portal's token: whoever holds the
 * email may stop it arriving, which is all it can do. It never expires, because an
 * unsubscribe link in a month-old email that no longer works is the kind of thing
 * people report as spam instead.
 *
 * Opening the link changes nothing. Mail scanners and link previews fetch every URL
 * in a message, so a GET that unsubscribed would unsubscribe people who never asked.
 * The page offers the choice, and a POST makes it. Mail clients' one-click button
 * (RFC 8058) posts `List-Unsubscribe=One-Click` to the same URL, and means all email.
 */
class UnsubscribeController extends Controller
{
    public static function url(User $user, ?Issue $issue = null): string
    {
        return URL::signedRoute('unsubscribe', array_filter([
            'user' => $user->id,
            'issue' => $issue?->id,
        ]));
    }

    public function show(Request $request, User $user): Response
    {
        $issue = $this->issue($request, $user);

        return Inertia::render('unsubscribe', [
            'email' => $user->email,
            'emailOn' => $user->wantsEmail(),
            'issue' => $issue ? app(Tenancy::class)->run(
                $issue->workspace,
                fn () => ['key' => $issue->key, 'title' => $issue->title],
            ) : null,
            // Posted back to itself, signature and all.
            'action' => $request->fullUrl(),
        ]);
    }

    public function store(Request $request, User $user): RedirectResponse|SymfonyResponse
    {
        $oneClick = $request->input('List-Unsubscribe') === 'One-Click';

        if (! $oneClick && $request->input('scope') === 'issue' && $issue = $this->issue($request, $user)) {
            app(Tenancy::class)->run($issue->workspace, fn () => $issue->unwatch($user));

            $key = app(Tenancy::class)->run($issue->workspace, fn () => $issue->key);

            return back()->with('success', "You will not be emailed about {$key} again unless you are mentioned or assigned it.");
        }

        $user->forceFill([
            'notification_settings' => [...($user->notification_settings ?? []), 'email' => false],
        ])->save();

        // A mail client's button expects a plain answer, not a page.
        if ($oneClick) {
            return response()->noContent(200);
        }

        return back()->with('success', 'You will not get notification emails any more. You can switch them back on in your notification settings.');
    }

    /** The issue named in the link, only while this person is watching it. */
    private function issue(Request $request, User $user): ?Issue
    {
        if (! $request->filled('issue')) {
            return null;
        }

        // Found with tenancy off, as the scheduled sweeps do: the issue's own scopes
        // reach its project, and no workspace is bound on the central domain.
        $issue = app(Tenancy::class)->withoutTenancy(
            fn () => Issue::query()->acrossAllWorkspaces()->with('workspace')->find($request->integer('issue')),
        );

        if ($issue === null) {
            return null;
        }

        // Everything about an issue is read inside its own workspace, as everywhere.
        return app(Tenancy::class)->run(
            $issue->workspace,
            fn () => $issue->watchers()->whereKey($user->id)->exists() ? $issue : null,
        );
    }
}
