<?php

namespace App\Support\Attachments;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * The one way a file becomes an attachment, whether it was uploaded on the issue page
 * or arrived with an email. Two copies of these rules is how one of them ends up
 * accepting SVG.
 */
final class AttachmentStore
{
    /**
     * What may be stored.
     *
     * An allowlist rather than a blocklist, and deliberately without SVG: it is an
     * XML document that can carry script, and anything we serve back to a browser
     * from our own origin can act on our behalf. Judged by the file's content, not
     * by the type a browser or mail client claimed for it.
     */
    public const ALLOWED_MIMES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp',
        'application/pdf',
        'text/plain', 'text/csv', 'application/json',
        'application/zip',
    ];

    public const MAX_KILOBYTES = 10240;

    /** Why a file would be refused, or null when it is acceptable. */
    public static function refusal(UploadedFile $file): ?string
    {
        if (! $file->isValid()) {
            return 'it did not arrive intact';
        }

        if ($file->getSize() > self::MAX_KILOBYTES * 1024) {
            return 'it is larger than '.(self::MAX_KILOBYTES / 1024).'MB';
        }

        if (! in_array($file->getMimeType(), self::ALLOWED_MIMES, true)) {
            return 'that type of file is not accepted';
        }

        return null;
    }

    public static function store(UploadedFile $file, Issue|Comment $attachable, Issue $issue, ?User $by): Attachment
    {
        // Stored under a generated name: the original is kept as a label only, so a
        // crafted filename cannot escape the directory or collide with anything.
        $path = $file->store("workspaces/{$issue->workspace_id}/attachments", 'local');

        $dimensions = self::dimensions($file);

        return Attachment::create([
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
            'disk' => 'local',
            'path' => $path,
            'filename' => self::safeName($file->getClientOriginalName()),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
            'uploaded_by_id' => $by?->id,
        ]);
    }

    /** Keep something recognisable, drop anything that could be read as a path. */
    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^\w\s.\-()]/u', '', $name);

        return mb_substr(trim($name) ?: 'attachment', 0, 120);
    }

    /** @return array{0: int|null, 1: int|null} */
    private static function dimensions(UploadedFile $file): array
    {
        if (! str_starts_with((string) $file->getMimeType(), 'image/')) {
            return [null, null];
        }

        $size = @getimagesize($file->getRealPath());

        return [$size[0] ?? null, $size[1] ?? null];
    }
}
