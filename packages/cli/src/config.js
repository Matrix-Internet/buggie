import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';

const CONFIG_DIR = '.buggie';
const CONFIG_FILE = 'config.json';
const CREDENTIALS_FILE = 'credentials';
const ISSUES_DIR = 'issues';

/**
 * Walk up from cwd looking for .buggie/config.json or a .git directory.
 * @param {string} [start]
 * @returns {string}
 */
export function findProjectRoot(start = process.cwd()) {
    let dir = resolve(start);

    for (let i = 0; i < 12; i++) {
        if (existsSync(join(dir, CONFIG_DIR, CONFIG_FILE)) || existsSync(join(dir, '.git'))) {
            return dir;
        }
        const parent = dirname(dir);
        if (parent === dir) {
            break;
        }
        dir = parent;
    }

    return resolve(start);
}

/**
 * @param {string} [root]
 */
export function buggieDir(root = findProjectRoot()) {
    return join(root, CONFIG_DIR);
}

/**
 * @param {string} [root]
 */
export function issuesDir(root = findProjectRoot()) {
    return join(buggieDir(root), ISSUES_DIR);
}

/**
 * @typedef {{
 *   host: string,
 *   project: string,
 *   query: string,
 *   agents: string[],
 *   status_map: Record<string, string>,
 * }} BuggieConfig
 */

/**
 * @param {string} [root]
 * @returns {BuggieConfig}
 */
export function loadConfig(root = findProjectRoot()) {
    const path = join(buggieDir(root), CONFIG_FILE);
    if (!existsSync(path)) {
        throw new Error(
            `No ${CONFIG_DIR}/${CONFIG_FILE} found. Run \`buggie init\` in the project root.`,
        );
    }

    /** @type {Record<string, unknown>} */
    const raw = JSON.parse(readFileSync(path, 'utf8'));
    const host = String(raw.host ?? '').replace(/\/$/, '');
    const project = String(raw.project ?? '').trim();
    const query = String(raw.query ?? (project ? `is:open project:${project}` : 'is:open')).trim();
    const agents = Array.isArray(raw.agents) ? raw.agents.map(String) : [];
    /** @type {Record<string, string>} */
    const status_map =
        raw.status_map && typeof raw.status_map === 'object'
            ? Object.fromEntries(
                  Object.entries(/** @type {Record<string, unknown>} */ (raw.status_map)).map(
                      ([k, v]) => [k, String(v)],
                  ),
              )
            : {};

    if (!host) {
        throw new Error(`${CONFIG_DIR}/${CONFIG_FILE}: "host" is required (workspace API base URL).`);
    }

    return { host, project, query, agents, status_map };
}

/**
 * @param {string} [root]
 * @returns {string}
 */
export function loadToken(root = findProjectRoot()) {
    const fromEnv = process.env.BUGGIE_TOKEN?.trim();
    if (fromEnv) {
        return fromEnv;
    }

    const path = join(buggieDir(root), CREDENTIALS_FILE);
    if (existsSync(path)) {
        const token = readFileSync(path, 'utf8').trim().split(/\r?\n/)[0]?.trim() ?? '';
        if (token) {
            return token;
        }
    }

    throw new Error(
        'No API token. Set BUGGIE_TOKEN or write the token to .buggie/credentials (gitignored).\n' +
            'Needed to pull from / push status to Buggie — local agent assignment does not use it.',
    );
}

/**
 * @param {Partial<BuggieConfig> & { host: string, project: string, query: string }} config
 * @param {string} [root]
 */
export function writeConfig(config, root = findProjectRoot()) {
    const dir = buggieDir(root);
    mkdirSync(dir, { recursive: true });
    mkdirSync(issuesDir(root), { recursive: true });

    const payload = {
        host: config.host,
        project: config.project,
        query: config.query,
        agents: config.agents ?? ['agent-1', 'agent-2'],
        status_map: config.status_map ?? {
            todo: 'Todo',
            triage: 'New',
            in_progress: 'In Progress',
            ready_for_review: 'In Review',
            done: 'Done',
        },
    };

    writeFileSync(join(dir, CONFIG_FILE), `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
    ensureGitignore(root);
}

/**
 * @param {string} [root]
 */
export function ensureGitignore(root = findProjectRoot()) {
    const path = join(buggieDir(root), '.gitignore');
    const lines = ['credentials', 'issues/', ''];
    if (!existsSync(path)) {
        writeFileSync(path, lines.join('\n'), 'utf8');
        return;
    }

    const existing = readFileSync(path, 'utf8');
    const missing = ['credentials', 'issues/'].filter(
        (entry) => !existing.split(/\r?\n/).includes(entry),
    );
    if (missing.length > 0) {
        writeFileSync(path, `${existing.trimEnd()}\n${missing.join('\n')}\n`, 'utf8');
    }
}

/**
 * Issue page URL on the workspace host.
 * @param {{ host: string }} config
 * @param {string} key
 */
export function issueUrl(config, key) {
    return `${config.host.replace(/\/$/, '')}/issues/${encodeURIComponent(key)}`;
}
