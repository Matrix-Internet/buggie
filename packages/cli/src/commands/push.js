import { createClient } from '../client.js';
import { findProjectRoot, issuesDir, loadConfig, loadToken } from '../config.js';
import { writeBrief } from '../brief.js';
import { remoteNeedleFor, statusMap } from '../status-map.js';
import { resolveStatusId } from './status.js';
import { mkdirSync } from 'node:fs';
import { listBriefPaths, readBriefByKey, readBriefFile, writeBriefFile } from '../store.js';

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
 * Push dirty local_status values to Buggie (triage sync).
 * @param {string[]} args
 */
export async function pushCommand(args) {
    const root = findProjectRoot();
    const config = loadConfig(root);
    const token = loadToken(root);
    const client = createClient({ host: config.host, token });
    const map = statusMap(config.status_map);
    const forceAll = args.includes('--all');
    const keys = args.filter((a) => !a.startsWith('--'));

    /** @type {string[]} */
    let targets;
    if (keys.length > 0) {
        targets = keys;
    } else {
        targets = listBriefPaths(root)
            .map((path) => readBriefFile(path).meta)
            .filter((meta) => forceAll || meta.dirty === 'true')
            .filter((meta) => meta.local_status)
            .map((meta) => meta.key);
    }

    if (targets.length === 0) {
        console.log('Nothing to push. Mark issues dirty with `buggie status <KEY> …` first.');
        return;
    }

    // Cache project catalogs by project key
    /** @type {Map<string, { id: number, name: string, category: string }[]>} */
    const catalogs = new Map();
    mkdirSync(issuesDir(root), { recursive: true });

    let ok = 0;
    for (const key of targets) {
        const brief = readBriefByKey(root, key);
        const localStatus = brief.meta.local_status;
        if (!localStatus) {
            console.error(`${key}: no local_status, skip`);
            continue;
        }

        const projectKey = config.project || brief.meta.project;
        if (!projectKey) {
            console.error(`${key}: no project key`);
            continue;
        }

        if (!catalogs.has(projectKey)) {
            const projectResponse = /** @type {{ data: { statuses?: { id: number, name: string, category: string }[] } }} */ (
                await client.getProject(projectKey)
            );
            catalogs.set(projectKey, projectResponse.data.statuses ?? []);
        }

        const statuses = catalogs.get(projectKey) ?? [];
        const needle = remoteNeedleFor(localStatus, map);
        let statusId;
        try {
            statusId = resolveStatusId(statuses, needle);
        } catch (error) {
            console.error(`${key}: ${error instanceof Error ? error.message : error}`);
            continue;
        }

        const updated = /** @type {{ data: Record<string, unknown> }} */ (
            await client.updateIssue(key, { status_id: statusId })
        );

        // Preserve agent; clear dirty after successful remote write.
        const previous = { ...brief.meta, dirty: 'false', local_status: localStatus };
        writeBrief(issuesDir(root), updated.data, config);
        const refreshed = readBriefByKey(root, key);
        refreshed.meta.agent = previous.agent ?? '';
        refreshed.meta.local_status = localStatus;
        refreshed.meta.dirty = 'false';
        writeBriefFile(refreshed.path, refreshed.meta, refreshLocalSection(refreshed.meta, refreshed.body));

        const status = /** @type {{ name?: string }} */ (updated.data.status);
        console.log(`${key} → ${status?.name ?? statusId}`);
        ok += 1;
    }

    console.log(`Pushed ${ok}/${targets.length} issue(s) to Buggie.`);
}
