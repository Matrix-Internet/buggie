import { mkdirSync } from 'node:fs';
import { writeBrief } from '../brief.js';
import { createClient } from '../client.js';
import { findProjectRoot, issuesDir, loadConfig, loadToken } from '../config.js';

/**
 * @param {string[]} args
 */
export async function pullCommand(args) {
    const root = findProjectRoot();
    const config = loadConfig(root);
    const token = loadToken(root);
    const client = createClient({ host: config.host, token });

    let query = config.query;
    for (const arg of args) {
        if (arg.startsWith('--q=')) {
            query = arg.slice('--q='.length);
        } else if (arg === '-q' || arg === '--q') {
            throw new Error('Pass the query as --q="is:open project:WEB"');
        }
    }

    const dir = issuesDir(root);
    mkdirSync(dir, { recursive: true });

    let page = 1;
    let written = 0;
    let total = Infinity;

    while (written < total) {
        const response = /** @type {{ data?: unknown[], meta?: { total?: number, last_page?: number } }} */ (
            await client.listIssues(query, page)
        );
        const items = Array.isArray(response.data) ? response.data : [];
        total = response.meta?.total ?? items.length;
        const lastPage = response.meta?.last_page ?? 1;

        for (const issue of items) {
            const key = String(/** @type {{ key?: string }} */ (issue).key ?? '');
            // List payload is a summary (no description). Fetch detail for agent briefs.
            const detail = key
                ? /** @type {{ data: Record<string, unknown> }} */ (await client.getIssue(key)).data
                : /** @type {Record<string, unknown>} */ (issue);
            const path = writeBrief(dir, detail, config);
            console.log(path);
            written += 1;
        }

        if (page >= lastPage || items.length === 0) {
            break;
        }
        page += 1;
    }

    console.log(`Pulled ${written} issue(s) → ${dir}`);
}
