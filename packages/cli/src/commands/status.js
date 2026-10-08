import { createClient } from '../client.js';
import { findProjectRoot, issuesDir, loadConfig, loadToken } from '../config.js';
import { writeBrief } from '../brief.js';
import { remoteNeedleFor, statusMap } from '../status-map.js';
import { mkdirSync } from 'node:fs';
import { readBriefByKey, writeBriefFile } from '../store.js';

/**
 * Resolve a status name or category to a status_id using the project catalog.
 * @param {{ id: number, name: string, category: string }[]} statuses
 * @param {string} needle
 * @returns {number}
 */
export function resolveStatusId(statuses, needle) {
    const want = needle.trim().toLowerCase();
    if (!want) {
        throw new Error('Status name or category is required.');
    }

    const byName = statuses.find((s) => s.name.toLowerCase() === want);
    if (byName) {
        return byName.id;
    }

    const byCategory = statuses.filter((s) => s.category.toLowerCase() === want);
    if (byCategory.length === 1) {
        return byCategory[0].id;
    }
    if (byCategory.length > 1) {
        return byCategory[0].id;
    }

    const available = statuses.map((s) => `${s.name} (${s.category})`).join(', ');
    throw new Error(`No status matching "${needle}". Available: ${available}`);
}

/**
 * @param {Record<string, string>} meta
 * @param {string} body
 */
function refreshLocalSection(meta, body) {
    const without = body.replace(/\n## Local queue[\s\S]*?(?=\n## |\n*$)/, '\n').trimEnd();
    if (!meta.agent && !meta.local_status) {
        return `${without}\n`;
    }
    const lines = ['', '## Local queue', ''];
    if (meta.agent) {
        lines.push(`- Agent: **${meta.agent}**`);
    }
    if (meta.local_status) {
        lines.push(
            `- Local status: **${meta.local_status}**${meta.dirty === 'true' ? ' (unpushed)' : ''}`,
        );
    }
    lines.push('');
    return `${without}\n${lines.join('\n')}`;
}

/**
 * Set local_status only (default), or push immediately with --push / --remote.
 * @param {string[]} args
 */
export async function statusCommand(args) {
    const pushNow = args.includes('--push') || args.includes('--remote');
    const positional = args.filter((a) => !a.startsWith('--'));
    const [key, ...rest] = positional;
    const needle = rest.join(' ').trim();
    if (!key || !needle) {
        throw new Error(
            `Usage: buggie status <KEY> <local_status|Buggie name> [--push]

Local-first: updates the brief only and marks it dirty.
Pass --push to write through to Buggie immediately.

Common local statuses: todo, in_progress, ready_for_review, done, triage`,
        );
    }

    const root = findProjectRoot();
    const config = loadConfig(root);

    if (!pushNow) {
        const brief = readBriefByKey(root, key);
        brief.meta.local_status = needle.trim().toLowerCase().replace(/\s+/g, '_');
        brief.meta.dirty = 'true';
        writeBriefFile(brief.path, brief.meta, refreshLocalSection(brief.meta, brief.body));
        console.log(`${key} local_status → ${brief.meta.local_status} (unpushed)`);
        console.log(brief.path);
        console.log(`Run \`buggie push\` (or \`buggie push ${key}\`) to sync to Buggie.`);
        return;
    }

    await pushStatus(root, config, key, needle);
}

/**
 * @param {string} root
 * @param {{ host: string, project: string, status_map?: Record<string, string> }} config
 * @param {string} key
 * @param {string} localOrRemote
 */
export async function pushStatus(root, config, key, localOrRemote) {
    const token = loadToken(root);
    const client = createClient({ host: config.host, token });
    const map = statusMap(config.status_map);
    const remoteNeedle = remoteNeedleFor(localOrRemote, map);

    const issueResponse = /** @type {{ data: Record<string, unknown> }} */ (
        await client.getIssue(key)
    );
    const projectKey =
        config.project ||
        String(
            /** @type {{ key?: string } | undefined} */ (issueResponse.data.project)?.key ?? '',
        );
    if (!projectKey) {
        throw new Error('No project key: set "project" in .buggie/config.json');
    }

    const projectResponse = /** @type {{ data: { statuses?: { id: number, name: string, category: string }[] } }} */ (
        await client.getProject(projectKey)
    );
    const statuses = projectResponse.data.statuses ?? [];
    const statusId = resolveStatusId(statuses, remoteNeedle);

    const updated = /** @type {{ data: Record<string, unknown> }} */ (
        await client.updateIssue(key, { status_id: statusId })
    );

    const dir = issuesDir(root);
    mkdirSync(dir, { recursive: true });
    const path = writeBrief(dir, updated.data, config);

    // After a successful push, clear dirty and align local_status.
    try {
        const brief = readBriefByKey(root, key);
        brief.meta.local_status = localOrRemote.trim().toLowerCase().replace(/\s+/g, '_');
        brief.meta.dirty = 'false';
        writeBriefFile(brief.path, brief.meta, refreshLocalSection(brief.meta, brief.body));
    } catch {
        // File just written by writeBrief; re-read and patch.
    }

    const status = /** @type {{ name?: string }} */ (updated.data.status);
    console.log(`${key} → ${status?.name ?? statusId} (pushed)`);
    console.log(path);
}
