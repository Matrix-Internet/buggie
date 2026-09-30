import { AppLayout } from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Summary {
    workspaces: number;
    users: number;
    projects: number;
    issues: number;
    reports_this_month: number;
    signups_this_week: number;
    trials_ending: number;
}

interface WorkspaceRow {
    slug: string;
    name: string;
    url: string;
    owner: { name: string; email: string } | null;
    created_at: string | null;
    plan: string;
    trial_ends_at: string | null;
    on_trial: boolean;
    subscribed: boolean;
    projects: number;
    members: number;
    reports_this_month: number;
    last_activity: string | null;
}

function Stat({ label, value, tone }: { label: string; value: number; tone?: 'warn' }) {
    return (
        <div className="rounded-xl border border-border bg-raised p-4">
            <span className="text-xs text-ink-subtle">{label}</span>
            <p
                className={`mt-1 text-2xl font-semibold ${
                    tone === 'warn' && value > 0 ? 'text-amber-600 dark:text-amber-500' : 'text-ink'
                }`}
            >
                {value.toLocaleString()}
            </p>
        </div>
    );
}

/** What a workspace is paying, or not, in one word. */
function Status({ row }: { row: WorkspaceRow }) {
    if (row.subscribed) {
        return <span className="text-success">{row.plan}</span>;
    }

    if (row.on_trial) {
        return (
            <span className="text-amber-600 dark:text-amber-500">
                trial → {row.trial_ends_at}
            </span>
        );
    }

    return <span className="text-ink-subtle">{row.plan}</span>;
}

export default function OperatorConsole({
    summary,
    workspaces,
    filters,
}: {
    summary: Summary;
    workspaces: WorkspaceRow[];
    filters: { q: string };
}) {
    const [q, setQ] = useState(filters.q);

    return (
        <AppLayout title="Operator">
            <Head title="Operator" />

            <div className="space-y-6">
                <p className="max-w-2xl text-sm text-ink-muted">
                    Everyone using the hosted service. Counts and dates only — nothing
                    here opens an issue, reads a comment or shows the contents of a
                    report.
                </p>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat label="Workspaces" value={summary.workspaces} />
                    <Stat label="Signed up this week" value={summary.signups_this_week} />
                    <Stat label="Trials ending in 3 days" value={summary.trials_ending} tone="warn" />
                    <Stat label="Reports this month" value={summary.reports_this_month} />
                    <Stat label="People" value={summary.users} />
                    <Stat label="Projects" value={summary.projects} />
                    <Stat label="Issues" value={summary.issues} />
                </div>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get('/operator', q ? { q } : {}, { preserveState: true });
                    }}
                >
                    <input
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="Find a workspace by name or subdomain"
                        aria-label="Search workspaces"
                        className="w-full max-w-sm rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink"
                    />
                </form>

                {workspaces.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-ink-muted">
                        {filters.q
                            ? 'No workspace matches that.'
                            : 'Nobody has signed up yet.'}
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-border">
                        <table className="w-full text-sm">
                            <thead className="border-b border-border text-left text-xs text-ink-subtle">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Workspace</th>
                                    <th className="px-3 py-2 font-medium">Owner</th>
                                    <th className="px-3 py-2 font-medium">Status</th>
                                    <th className="px-3 py-2 text-right font-medium">Projects</th>
                                    <th className="px-3 py-2 text-right font-medium">People</th>
                                    <th className="px-3 py-2 text-right font-medium">Reports</th>
                                    <th className="px-3 py-2 font-medium">Last activity</th>
                                    <th className="px-3 py-2 font-medium">Signed up</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {workspaces.map((row) => (
                                    <tr key={row.slug}>
                                        <td className="px-3 py-2">
                                            <a
                                                href={row.url}
                                                className="text-accent underline underline-offset-2"
                                            >
                                                {row.name}
                                            </a>
                                            <span className="ml-2 font-mono text-xs text-ink-subtle">
                                                {row.slug}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2 text-ink-muted">
                                            {row.owner ? (
                                                <a
                                                    href={`mailto:${row.owner.email}`}
                                                    className="hover:text-accent"
                                                    title={row.owner.email}
                                                >
                                                    {row.owner.name}
                                                </a>
                                            ) : (
                                                <span className="text-ink-subtle">—</span>
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2 text-xs">
                                            <Status row={row} />
                                        </td>
                                        <td className="px-3 py-2 text-right tabular-nums text-ink-muted">
                                            {row.projects}
                                        </td>
                                        <td className="px-3 py-2 text-right tabular-nums text-ink-muted">
                                            {row.members}
                                        </td>
                                        <td className="px-3 py-2 text-right tabular-nums text-ink-muted">
                                            {row.reports_this_month}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2 text-xs text-ink-subtle">
                                            {/* Blank is meaningful here: nobody has
                                                touched an issue since they signed up. */}
                                            {row.last_activity ?? 'never'}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2 text-xs text-ink-subtle">
                                            {row.created_at}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
