import { findProjectRoot } from '../config.js';
import { listBriefPaths, readBriefFile } from '../store.js';

/**
 * @param {string[]} args
 */
export async function listCommand(args) {
    const root = findProjectRoot();
    const onlyAgent = args.find((a) => a.startsWith('--agent='))?.slice('--agent='.length);
    const onlyDirty = args.includes('--dirty');
    const onlyUnassigned = args.includes('--unassigned');

    const rows = listBriefPaths(root).map((path) => {
        const { meta } = readBriefFile(path);
        return {
            key: meta.key || path.split('/').pop()?.replace(/\.md$/, '') || '?',
            title: meta.title || '',
            remote: meta.status || '',
            local: meta.local_status || '—',
            agent: meta.agent || '—',
            dirty: meta.dirty === 'true' ? 'yes' : '',
        };
    });

    const filtered = rows.filter((row) => {
        if (onlyDirty && row.dirty !== 'yes') {
            return false;
        }
        if (onlyUnassigned && row.agent !== '—') {
            return false;
        }
        if (onlyAgent && row.agent !== onlyAgent) {
            return false;
        }
        return true;
    });

    if (filtered.length === 0) {
        console.log('No local issues. Run `buggie pull` first.');
        return;
    }

    const pad = (value, width) => String(value).slice(0, width).padEnd(width);
    console.log(
        `${pad('KEY', 10)} ${pad('AGENT', 14)} ${pad('LOCAL', 18)} ${pad('REMOTE', 14)} ${pad('DIRTY', 5)} TITLE`,
    );
    for (const row of filtered) {
        console.log(
            `${pad(row.key, 10)} ${pad(row.agent, 14)} ${pad(row.local, 18)} ${pad(row.remote, 14)} ${pad(row.dirty, 5)} ${row.title}`,
        );
    }
}
