import { Button } from '@/components/button';
import type { SharedProps } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Bug, CheckCircle2 } from 'lucide-react';

/**
 * Where the link at the foot of a notification email lands. Nothing changes until a
 * button is pressed: mail scanners open every link, and must not unsubscribe anybody.
 */
export default function Unsubscribe({
    email,
    emailOn,
    issue,
    action,
}: {
    email: string;
    emailOn: boolean;
    issue: { key: string; title: string } | null;
    action: string;
}) {
    const { flash } = usePage<SharedProps>().props;
    const { post, processing, transform } = useForm({ scope: 'all' });

    function send(scope: 'issue' | 'all') {
        transform(() => ({ scope }));
        post(action, { preserveScroll: true });
    }

    return (
        <>
            <Head title="Email notifications" />

            <div className="min-h-screen bg-surface py-16">
                <div className="mx-auto w-full max-w-md px-4">
                    <div className="mb-6 flex items-center gap-2 text-ink-muted">
                        <Bug className="size-5 text-accent" />
                        <span className="text-sm font-medium">Buggie</span>
                    </div>

                    <div className="rounded-xl border border-border bg-raised p-6">
                        <h1 className="text-lg font-semibold text-ink">Email notifications</h1>
                        <p className="mt-1 text-sm text-ink-muted">
                            For <span className="text-ink">{email}</span>
                        </p>

                        {flash.success ? (
                            <p className="mt-5 flex items-start gap-2 text-sm text-ink">
                                <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-accent" />
                                {flash.success}
                            </p>
                        ) : (
                            <div className="mt-5 space-y-3">
                                {issue && (
                                    <div>
                                        <Button
                                            variant="secondary"
                                            className="w-full"
                                            disabled={processing}
                                            onClick={() => send('issue')}
                                        >
                                            Stop watching {issue.key}
                                        </Button>
                                        <p className="mt-1 truncate text-xs text-ink-subtle">{issue.title}</p>
                                    </div>
                                )}

                                {emailOn ? (
                                    <Button
                                        variant="danger"
                                        className="w-full"
                                        disabled={processing}
                                        onClick={() => send('all')}
                                    >
                                        Stop all notification email
                                    </Button>
                                ) : (
                                    <p className="text-sm text-ink-muted">
                                        Notification email is already off for this address.
                                    </p>
                                )}

                                <p className="text-xs text-ink-subtle">
                                    You will still see notifications when you sign in, and can
                                    switch email back on, or choose what it covers, in your
                                    notification settings.
                                </p>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
