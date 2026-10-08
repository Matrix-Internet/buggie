import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { buggieDir, findProjectRoot, writeConfig } from '../config.js';

/**
 * @param {string[]} args
 */
export async function initCommand(args) {
    const root = findProjectRoot();
    const configPath = join(buggieDir(root), 'config.json');

    if (existsSync(configPath) && !args.includes('--force')) {
        throw new Error(`${configPath} already exists. Pass --force to overwrite.`);
    }

    writeConfig(
        {
            host: 'http://acme.buggie.localhost:8088',
            project: 'CP',
            query: 'is:open project:customer-portal',
            agents: ['agent-1', 'agent-2'],
            status_map: {
                todo: 'Todo',
                triage: 'New',
                in_progress: 'In Progress',
                ready_for_review: 'In Review',
                done: 'Done',
            },
        },
        root,
    );

    console.log(`Wrote ${configPath}`);
    console.log('Edit host / project / query / agents, then set BUGGIE_TOKEN for pull & push.');
    console.log('Note: q= project:… filters by slug (customer-portal), not key (CP).');
    console.log('');
    console.log('Loop:  buggie pull → assign → status ready_for_review → buggie push');
}
