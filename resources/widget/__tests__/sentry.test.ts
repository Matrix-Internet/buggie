import { afterEach, describe, expect, it, vi } from 'vitest';
import { markReported, sentryContext, setSentry } from '../sentry';

const EVENT = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
const TRACE = '0123456789abcdef0123456789abcdef';
const REPLAY = 'fedcba9876543210fedcba9876543210';

afterEach(() => {
    setSentry(null);
    delete (window as unknown as { Sentry?: unknown }).Sentry;
});

describe('sentry context', () => {
    it('is absent when the page has no Sentry', () => {
        expect(sentryContext()).toBeNull();
    });

    it('reads the error, trace and replay from a v8 SDK handed over explicitly', () => {
        setSentry({
            lastEventId: () => EVENT,
            getTraceData: () => ({ 'sentry-trace': `${TRACE}-1234567890abcdef-1` }),
            getReplay: () => ({ getReplayId: () => REPLAY, getRecordingMode: () => 'session' }),
        });

        expect(sentryContext()).toEqual({ event_id: EVENT, trace_id: TRACE, replay_id: REPLAY });
    });

    it('finds window.Sentry from the CDN loader, and falls back to the scope for the trace', () => {
        (window as unknown as { Sentry: unknown }).Sentry = {
            getCurrentScope: () => ({ getPropagationContext: () => ({ traceId: TRACE }) }),
        };

        expect(sentryContext()).toEqual({ event_id: undefined, trace_id: TRACE, replay_id: undefined });
    });

    it('leaves out a replay that is only buffered, since nothing was uploaded to link to', () => {
        setSentry({
            lastEventId: () => EVENT,
            getReplay: () => ({ getReplayId: () => REPLAY, getRecordingMode: () => 'buffer' }),
        });

        expect(sentryContext()?.replay_id).toBeUndefined();
    });

    it('never lets a broken or unexpected SDK break the report', () => {
        setSentry({
            lastEventId: () => {
                throw new Error('not initialised');
            },
            getTraceData: () => ({ 'sentry-trace': 'not-a-trace' }),
            getReplay: () => ({ getReplayId: () => '<script>' }),
        });

        expect(sentryContext()).toBeNull();
    });

    it('leaves the reference on Sentry without creating an event', () => {
        const sentry = { addBreadcrumb: vi.fn(), setTag: vi.fn(), captureMessage: vi.fn() };
        setSentry(sentry);

        markReported('R-42');

        expect(sentry.setTag).toHaveBeenCalledWith('buggie.report', 'R-42');
        expect(sentry.addBreadcrumb).toHaveBeenCalledOnce();
        expect(sentry.captureMessage).not.toHaveBeenCalled();
    });
});
