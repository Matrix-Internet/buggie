import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { issuesDir } from './config.js';

/**
 * @typedef {{
 *   key: string,
 *   title: string,
 *   status: string,
 *   status_category: string,
 *   status_id: string,
 *   priority: string,
 *   type: string,
 *   project: string,
 *   labels: string,
 *   url: string,
 *   updated_at: string,
 *   agent: string,
 *   local_status: string,
 *   dirty: string,
 * }} BriefMeta
 */

/**
 * @param {string} text
 * @returns {{ meta: Record<string, string>, body: string }}
 */
export function parseBrief(text) {
    const match = text.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n([\s\S]*)$/);
    if (!match) {
        return { meta: {}, body: text };
    }

    /** @type {Record<string, string>} */
    const meta = {};
    for (const line of match[1].split(/\r?\n/)) {
        const idx = line.indexOf(':');
        if (idx === -1) {
            continue;
        }
        const key = line.slice(0, idx).trim();
        let value = line.slice(idx + 1).trim();
        if (
            (value.startsWith('"') && value.endsWith('"')) ||
            (value.startsWith("'") && value.endsWith("'"))
        ) {
            value = value.slice(1, -1).replace(/\\"/g, '"').replace(/\\\\/g, '\\');
        }
        meta[key] = value;
    }

    return { meta, body: match[2] };
}

/**
 * @param {string} value
 */
function yamlQuote(value) {
    return `"${String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`;
}

/**
 * @param {Record<string, string>} meta
 * @param {string} body
 */
export function serializeBrief(meta, body) {
    const order = [
        'key',
        'title',
        'status',
        'status_category',
        'status_id',
        'priority',
        'type',
        'project',
        'labels',
        'url',
        'updated_at',
        'agent',
        'local_status',
        'dirty',
    ];

    const lines = ['---'];
    const seen = new Set();
    for (const key of order) {
        if (meta[key] === undefined || meta[key] === '') {
            // Always emit agent/local_status/dirty so agents can edit them.
            if (!['agent', 'local_status', 'dirty'].includes(key)) {
                continue;
            }
        }
        const raw = meta[key] ?? '';
        if (key === 'dirty') {
            lines.push(`dirty: ${raw === 'true' ? 'true' : 'false'}`);
        } else if (key === 'labels' && raw.startsWith('[')) {
            // Already a YAML/JSON-ish list from remoteMetaFromIssue — don't wrap again.
            lines.push(`labels: ${raw}`);
        } else if (['title', 'status', 'url', 'agent', 'local_status', 'labels'].includes(key)) {
            lines.push(`${key}: ${yamlQuote(raw)}`);
        } else {
            lines.push(`${key}: ${raw || '""'}`);
        }
        seen.add(key);
    }
    for (const [key, value] of Object.entries(meta)) {
        if (seen.has(key)) {
            continue;
        }
        lines.push(`${key}: ${yamlQuote(value)}`);
    }
    lines.push('---', '');
    return `${lines.join('\n')}${body.replace(/^\n*/, '')}`;
}

/**
 * @param {string} root
 * @returns {string[]}
 */
export function listBriefPaths(root) {
    const dir = issuesDir(root);
    if (!existsSync(dir)) {
        return [];
    }
    return readdirSync(dir)
        .filter((name) => name.endsWith('.md'))
        .map((name) => join(dir, name))
        .sort();
}

/**
 * @param {string} path
 */
export function readBriefFile(path) {
    const text = readFileSync(path, 'utf8');
    const { meta, body } = parseBrief(text);
    return { path, meta, body, text };
}

/**
 * @param {string} root
 * @param {string} key
 */
export function briefPathFor(root, key) {
    return join(issuesDir(root), `${key}.md`);
}

/**
 * @param {string} root
 * @param {string} key
 */
export function readBriefByKey(root, key) {
    const path = briefPathFor(root, key);
    if (!existsSync(path)) {
        throw new Error(`No local brief for ${key}. Run \`buggie pull\` or \`buggie show ${key}\` first.`);
    }
    return readBriefFile(path);
}

/**
 * @param {string} path
 * @param {Record<string, string>} meta
 * @param {string} body
 */
export function writeBriefFile(path, meta, body) {
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, serializeBrief(meta, body), 'utf8');
}

/**
 * Local fields we must not clobber when refreshing from Buggie.
 * @param {Record<string, string>} previous
 * @param {Record<string, string>} next
 */
export function preserveLocalFields(previous, next) {
    return {
        ...next,
        agent: previous.agent ?? next.agent ?? '',
        local_status: previous.local_status ?? next.local_status ?? '',
        dirty: previous.dirty ?? next.dirty ?? 'false',
    };
}
