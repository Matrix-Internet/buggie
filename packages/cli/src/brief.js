import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { issueUrl } from './config.js';
import { preserveLocalFields, readBriefFile, serializeBrief } from './store.js';

/**
 * Flatten TipTap JSON (or a plain string) into readable text for agents.
 * @param {unknown} doc
 * @returns {string}
 */
export function descriptionToText(doc) {
    if (doc == null) {
        return '';
    }
    if (typeof doc === 'string') {
        return doc.trim();
    }
    if (typeof doc !== 'object') {
        return String(doc);
    }

    const lines = [];

    /**
     * @param {unknown} node
     * @returns {string}
     */
    function inline(node) {
        if (!node || typeof node !== 'object') {
            return '';
        }
        const n = /** @type {{ type?: string, text?: string, content?: unknown[] }} */ (node);
        if (n.type === 'text') {
            return n.text ?? '';
        }
        if (Array.isArray(n.content)) {
            return n.content.map(inline).join('');
        }
        return '';
    }

    /**
     * @param {unknown} node
     */
    function walk(node) {
        if (!node || typeof node !== 'object') {
            return;
        }
        const n = /** @type {{ type?: string, content?: unknown[], attrs?: Record<string, unknown> }} */ (
            node
        );

        switch (n.type) {
            case 'doc':
                (n.content ?? []).forEach(walk);
                break;
            case 'paragraph':
                lines.push(inline(n));
                break;
            case 'heading':
                lines.push(`## ${inline(n)}`);
                break;
            case 'bulletList':
            case 'orderedList':
                (n.content ?? []).forEach(walk);
                break;
            case 'listItem':
                lines.push(`- ${inline(n)}`);
                break;
            case 'codeBlock':
                lines.push('```');
                lines.push(inline(n));
                lines.push('```');
                break;
            case 'hardBreak':
                lines.push('');
                break;
            default:
                if (Array.isArray(n.content)) {
                    n.content.forEach(walk);
                }
                break;
        }
    }

    walk(doc);
    return lines.join('\n').replace(/\n{3,}/g, '\n\n').trim();
}

/**
 * @param {string} value
 */
function yamlQuote(value) {
    return `"${value.replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`;
}

/**
 * Build remote meta from an API issue payload (no local fields).
 * @param {Record<string, unknown>} issue
 * @param {{ host: string }} config
 * @returns {Record<string, string>}
 */
export function remoteMetaFromIssue(issue, config) {
    const key = String(issue.key ?? '');
    const status = /** @type {{ name?: string, category?: string, id?: number } | undefined} */ (
        issue.status
    );
    const project = /** @type {{ key?: string, name?: string } | undefined} */ (issue.project);
    const labels = Array.isArray(issue.labels)
        ? issue.labels.map((label) =>
              typeof label === 'string'
                  ? label
                  : String(/** @type {{ name?: string }} */ (label).name ?? ''),
          )
        : [];

    return {
        key,
        title: String(issue.title ?? ''),
        status: status?.name ?? '',
        status_category: status?.category ?? '',
        status_id: status?.id != null ? String(status.id) : '',
        priority: String(issue.priority ?? 0),
        type: String(issue.type ?? 'bug'),
        project: project?.key ?? '',
        labels: `[${labels.map((name) => yamlQuote(name)).join(', ')}]`,
        url: issueUrl(config, key),
        updated_at: String(issue.updated_at ?? ''),
        agent: '',
        local_status: '',
        dirty: 'false',
    };
}

/**
 * @param {Record<string, string>} meta
 * @param {string} descriptionText
 */
function bodyFrom(meta, descriptionText) {
    const parts = [`# ${meta.key}: ${meta.title}`, ''];

    if (descriptionText) {
        parts.push(descriptionText, '');
    } else {
        parts.push('_No description._', '');
    }

    parts.push('## Links', '', `- Issue: ${meta.url}`, '');

    if (meta.agent || meta.local_status) {
        parts.push('## Local queue', '');
        if (meta.agent) {
            parts.push(`- Agent: **${meta.agent}**`);
        }
        if (meta.local_status) {
            parts.push(
                `- Local status: **${meta.local_status}**${meta.dirty === 'true' ? ' (unpushed)' : ''}`,
            );
        }
        parts.push('');
    }

    return parts.join('\n');
}

/**
 * @param {Record<string, unknown>} issue
 * @param {{ host: string }} config
 * @param {Record<string, string>} [local]
 * @returns {string}
 */
export function renderBrief(issue, config, local = {}) {
    const meta = preserveLocalFields(local, remoteMetaFromIssue(issue, config));
    return serializeBrief(meta, bodyFrom(meta, descriptionToText(issue.description)));
}

/**
 * Write/update a brief, preserving agent / local_status / dirty when the file exists.
 * @param {string} dir
 * @param {Record<string, unknown>} issue
 * @param {{ host: string }} config
 * @returns {string} path written
 */
export function writeBrief(dir, issue, config) {
    const key = String(issue.key ?? 'UNKNOWN');
    const path = join(dir, `${key}.md`);
    mkdirSync(dir, { recursive: true });

    /** @type {Record<string, string>} */
    let previous = {};
    if (existsSync(path)) {
        previous = readBriefFile(path).meta;
    }

    writeFileSync(path, renderBrief(issue, config, previous), 'utf8');
    return path;
}
