/**
 * Links a report to what Sentry recorded at the same moment, when Sentry is on the page.
 *
 * Buggie has the person's account of the bug and Sentry has the machine's — most
 * usefully the server-side exception, which the widget can never see. Three ids join
 * them: the last error Sentry captured, the trace the page is in (which reaches the
 * backend), and the session replay.
 *
 * Nothing here imports Sentry or adds weight to the bundle. It reads the SDK that the
 * page already has — `window.Sentry` from Sentry's CDN loader, or whatever the host
 * hands to `buggie.setSentry()` when Sentry is bundled and so not global. Every call
 * is optional and guarded: SDK versions differ, and a report must never fail because
 * of something another vendor's script did.
 */

/** The parts of the Sentry browser SDK this reads. All optional; versions differ. */
export interface SentryLike {
    lastEventId?: () => string | undefined;
    getTraceData?: () => Record<string, string | undefined>;
    getActiveSpan?: () => unknown;
    spanToJSON?: (span: unknown) => { trace_id?: string };
    getCurrentScope?: () => { getPropagationContext?: () => { traceId?: string } };
    getReplay?: () => { getReplayId?: () => string | undefined; getRecordingMode?: () => string | undefined } | undefined;
    addBreadcrumb?: (breadcrumb: { category: string; message: string; level?: string }) => void;
    setTag?: (key: string, value: string) => void;
}

export interface SentryContext {
    event_id?: string;
    trace_id?: string;
    replay_id?: string;
}

let explicit: SentryLike | null = null;

export function setSentry(sentry: unknown) {
    explicit = sentry && typeof sentry === 'object' ? (sentry as SentryLike) : null;
}

function sdk(): SentryLike | null {
    if (explicit) return explicit;

    const global = (window as unknown as { Sentry?: unknown }).Sentry;

    return global && typeof global === 'object' ? (global as SentryLike) : null;
}

/** Sentry's event, trace and replay ids are all 32 hex characters. Anything else is dropped. */
function id(value: unknown): string | undefined {
    return typeof value === 'string' && /^[0-9a-f]{32}$/i.test(value) ? value.toLowerCase() : undefined;
}

function attempt<T>(read: () => T): T | undefined {
    try {
        return read();
    } catch {
        return undefined;
    }
}

function traceId(sentry: SentryLike): string | undefined {
    // `sentry-trace` is "<trace>-<span>[-<sampled>]": the header the SDK would send
    // with the next request, so it names the trace the backend will be part of.
    const header = attempt(() => sentry.getTraceData?.()['sentry-trace']);
    const fromHeader = id(typeof header === 'string' ? header.split('-')[0] : undefined);
    if (fromHeader) return fromHeader;

    const span = attempt(() => sentry.getActiveSpan?.());
    const fromSpan = span ? id(attempt(() => sentry.spanToJSON?.(span).trace_id)) : undefined;
    if (fromSpan) return fromSpan;

    return id(attempt(() => sentry.getCurrentScope?.().getPropagationContext?.().traceId));
}

function replayId(sentry: SentryLike): string | undefined {
    const replay = attempt(() => sentry.getReplay?.());
    if (!replay) return undefined;

    // A replay held in the error buffer has an id but has never been uploaded, so a
    // link to it would open nothing. Only a recording in progress is worth naming.
    const mode = attempt(() => replay.getRecordingMode?.());
    if (mode !== undefined && mode !== 'session') return undefined;

    return id(attempt(() => replay.getReplayId?.()));
}

/** The ids to send with a report, or null when Sentry is not on the page. */
export function sentryContext(): SentryContext | null {
    const sentry = sdk();
    if (!sentry) return null;

    const context: SentryContext = {
        event_id: id(attempt(() => sentry.lastEventId?.())),
        trace_id: traceId(sentry),
        replay_id: replayId(sentry),
    };

    return context.event_id || context.trace_id || context.replay_id ? context : null;
}

/**
 * The other direction: leave the report's reference on Sentry's scope, so any error
 * later in this session carries it as a breadcrumb and is searchable by the tag.
 * Creates no Sentry event of its own.
 */
export function markReported(reference: string) {
    const sentry = sdk();
    if (!sentry) return;

    attempt(() => sentry.addBreadcrumb?.({ category: 'buggie', message: `Bug report ${reference} sent`, level: 'info' }));
    attempt(() => sentry.setTag?.('buggie.report', reference));
}
