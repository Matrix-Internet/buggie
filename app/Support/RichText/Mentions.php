<?php

namespace App\Support\RichText;

use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Issues\AuthorLabel;
use App\Support\Issues\ClientAudienceSummary;
use Illuminate\Support\Collection;

/**
 * Who may be @-mentioned, and how a mention reads to a client.
 *
 * A mention node carries a user id and a label, and both arrive from the browser. The
 * id decides who becomes a watcher and who is notified, and the label is shown to
 * everyone who reads the text, so neither is believed:
 *
 * - On the way in, a mention survives only if its person may be mentioned there, and
 *   its label is reset to their name. Anything else becomes the plain text it showed.
 * - On the way out to a client, a staff member's mention reads as the workspace, the
 *   way their comments do (AuthorLabel), unless the workspace shows staff names.
 */
final class Mentions
{
    /**
     * The people who may be mentioned in something written about this issue.
     *
     * Staff always. Clients only in text they will be able to read — a public comment,
     * or the description of an issue shared with them — and only those the issue is
     * shared with. A client writing is offered nobody: they may not know the team's
     * names, and a mention would tell them.
     *
     * @return Collection<int, User>
     */
    public static function candidates(Workspace $workspace, ?Issue $issue, bool $internal, ?User $author): Collection
    {
        if ($author !== null && $author->membershipIn($workspace) === WorkspaceRole::Client) {
            return collect();
        }

        $staff = $workspace->members()
            ->wherePivot('role', '!=', WorkspaceRole::Client->value)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name']);

        if ($internal || $issue === null) {
            return $staff->values();
        }

        return $staff->concat(app(ClientAudienceSummary::class)->visibleTo($issue))->values();
    }

    /**
     * Keep only the mentions of people in $allowed, each labelled with their current
     * name; turn every other mention into its text.
     *
     * @param  array<string, mixed>|null  $document
     * @param  Collection<int, User>  $allowed
     * @return array<string, mixed>|null
     */
    public static function normalise(?array $document, Collection $allowed): ?array
    {
        if ($document === null) {
            return null;
        }

        $names = $allowed->pluck('name', 'id');

        return self::map($document, function (array $node) use ($names) {
            $id = $node['attrs']['id'] ?? null;

            if (is_numeric($id) && $names->has((int) $id)) {
                return ['type' => 'mention', 'attrs' => ['id' => (int) $id, 'label' => $names[(int) $id]]];
            }

            return ['type' => 'text', 'text' => '@'.self::label($node)];
        });
    }

    /**
     * The document as a client should read it: staff mentioned by the workspace's
     * name unless the workspace shows staff names.
     *
     * @param  array<string, mixed>|null  $document
     * @return array<string, mixed>|null
     */
    public static function forClients(?array $document, Workspace $workspace): ?array
    {
        if ($document === null || AuthorLabel::showsStaffNames($workspace)) {
            return $document;
        }

        $ids = TiptapDocument::mentionedUserIds($document);

        if ($ids === []) {
            return $document;
        }

        $clients = $workspace->members()
            ->whereIn('users.id', $ids)
            ->wherePivot('role', WorkspaceRole::Client->value)
            ->pluck('users.id')
            ->all();

        return self::map($document, function (array $node) use ($clients, $workspace) {
            $id = $node['attrs']['id'] ?? null;

            return in_array((int) $id, $clients, true)
                ? $node
                : ['type' => 'mention', 'attrs' => ['id' => $id, 'label' => $workspace->name]];
        });
    }

    /** @param array<string, mixed> $node */
    private static function label(array $node): string
    {
        $label = $node['attrs']['label'] ?? null;

        return is_string($label) && $label !== '' ? mb_substr($label, 0, 120) : 'someone';
    }

    /**
     * Rebuild the document with every mention node passed through $replace.
     *
     * @param  array<string, mixed>  $node
     * @param  callable(array<string, mixed>): array<string, mixed>  $replace
     * @return array<string, mixed>
     */
    private static function map(array $node, callable $replace): array
    {
        if (($node['type'] ?? null) === 'mention') {
            return $replace($node);
        }

        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = array_map(
                fn ($child) => is_array($child) ? self::map($child, $replace) : $child,
                $node['content'],
            );
        }

        return $node;
    }
}
