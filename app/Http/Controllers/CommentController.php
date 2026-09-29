<?php

namespace App\Http\Controllers;

use App\Actions\AddComment;
use App\Enums\NotificationReason;
use App\Enums\WatchReason;
use App\Http\Requests\StoreCommentRequest;
use App\Models\Comment;
use App\Models\Issue;
use App\Support\Issues\ClientConversation;
use App\Support\Notifications\Notifier;
use App\Support\RichText\Mentions;
use App\Support\RichText\TiptapDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function store(StoreCommentRequest $request, Issue $issue, AddComment $action): RedirectResponse
    {
        $this->authorize('comment', $issue);

        $internal = $request->boolean('is_internal');

        // A client cannot write an internal note, whatever the form posted.
        if ($internal) {
            $this->authorize('commentInternally', $issue);
        }

        $action->handle($issue, [
            'body' => $request->array('body'),
            'is_internal' => $internal,
        ], $request->user());

        return back();
    }

    /**
     * Reply to the client in public and wait on them. Staff only: it moves the issue,
     * and only staff change state.
     */
    public function await(StoreCommentRequest $request, Issue $issue, ClientConversation $conversation): RedirectResponse
    {
        $this->authorize('update', $issue);
        $this->authorize('comment', $issue);

        $conversation->replyAndAwait($issue, $request->array('body'), $request->user());

        return back()->with('success', 'Replied. Waiting on the client.');
    }

    public function update(Request $request, Comment $comment, Notifier $notifier): RedirectResponse
    {
        $this->authorize('update', $comment);

        $validated = $request->validate(['body' => ['required', 'array']]);

        $issue = $comment->issue;
        $candidates = Mentions::candidates($issue->workspace, $issue, $comment->is_internal, $request->user());
        $before = TiptapDocument::mentionedUserIds($comment->body);
        $body = Mentions::normalise($validated['body'], $candidates);

        // edited_at is not fillable, so it is set by name; mass-assigning it threw and
        // no edit had ever been saved.
        $comment->fill(['body' => $body, 'body_text' => TiptapDocument::toPlainText($body)]);
        $comment->edited_at = now();
        $comment->save();

        // Somebody named for the first time in an edit hears about it, as they would
        // have if it had been there from the start. Nobody already named is told again.
        $added = array_diff(TiptapDocument::mentionedUserIds($body), $before);

        foreach ($candidates->whereIn('id', $added) as $mentioned) {
            $issue->watch($mentioned, WatchReason::Mentioned);
            $notifier->record($mentioned, $issue, NotificationReason::Mentioned, $request->user(), [
                'internal' => $comment->is_internal,
            ]);
        }

        return back();
    }

    public function destroy(Comment $comment): RedirectResponse
    {
        $this->authorize('delete', $comment);

        $comment->delete();

        return back();
    }
}
