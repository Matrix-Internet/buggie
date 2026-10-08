import { mkdirSync } from 'node:fs';
import { writeBrief } from '../brief.js';
import { createClient } from '../client.js';
import { findProjectRoot, issuesDir, loadConfig, loadToken } from '../config.js';

/**
 * @param {string[]} args
 */
export async function showCommand(args) {
    const key = args[0];
    if (!key) {
        throw new Error('Usage: buggie show <KEY>');
    }

    const root = findProjectRoot();
    const config = loadConfig(root);
    const token = loadToken(root);
    const client = createClient({ host: config.host, token });
    const dir = issuesDir(root);
    mkdirSync(dir, { recursive: true });

    const response = /** @type {{ data: Record<string, unknown> }} */ (await client.getIssue(key));
    const path = writeBrief(dir, response.data, config);
    console.log(path);
}
