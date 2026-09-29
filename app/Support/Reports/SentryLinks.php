<?php

namespace App\Support\Reports;

/**
 * The Sentry ids a report arrived with, and where they lead.
 *
 * The widget sends the last Sentry error, the trace and the replay that were current
 * when somebody reported the bug (see resources/widget/sentry.ts). Sentry holds the
 * other half of the story — above all the backend exception — and these ids are what
 * let the team go from the person's account straight to it.
 *
 * Only ids are stored. The Sentry address belongs to the project, so the links are
 * built when the page is drawn: a team that sets or corrects the address gets working
 * links on every report already received.
 */
class SentryLinks
{
    /** What each id is called on the page, in the order it is shown. */
    private const KINDS = [
        'event_id' => 'Error',
        'trace_id' => 'Trace',
        'replay_id' => 'Replay',
    ];

    /**
     * Keep only well-formed ids from an anonymous payload. Every Sentry id is 32 hex
     * characters, and an id ends up in a link, so anything else is dropped rather
     * than trimmed into shape.
     *
     * @return array<string, string>|null
     */
    public static function sanitize(mixed $sentry): ?array
    {
        if (! is_array($sentry)) {
            return null;
        }

        $ids = [];

        foreach (array_keys(self::KINDS) as $kind) {
            $value = $sentry[$kind] ?? null;

            if (is_string($value) && preg_match('/^[0-9a-f]{32}$/i', $value)) {
                $ids[$kind] = strtolower($value);
            }
        }

        return $ids ?: null;
    }

    /**
     * One entry per id the report carries, each with a link when the project has a
     * Sentry address and without one when it does not, so the team can still search
     * for the id by hand.
     *
     * @param  array<string, mixed>|null  $environment
     * @return list<array{kind: string, label: string, id: string, url: string|null}>|null
     */
    public static function for(?array $environment, ?string $base): ?array
    {
        $ids = self::sanitize($environment['sentry'] ?? null);

        if ($ids === null) {
            return null;
        }

        $links = [];

        foreach (self::KINDS as $kind => $label) {
            if (! isset($ids[$kind])) {
                continue;
            }

            $links[] = [
                'kind' => $kind,
                'label' => $label,
                'id' => $ids[$kind],
                'url' => $base === null ? null : $base.match ($kind) {
                    // Sentry answers a search for an event id by opening that event.
                    'event_id' => '/issues/?query='.$ids[$kind],
                    'trace_id' => '/performance/trace/'.$ids[$kind].'/',
                    'replay_id' => '/replays/'.$ids[$kind].'/',
                },
            ];
        }

        return $links;
    }
}
