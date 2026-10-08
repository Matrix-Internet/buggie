import { findProjectRoot, loadConfig } from '../config.js';
import { listBriefPaths, readBriefByKey, readBriefFile, writeBriefFile } from '../store.js';

/**
 * Refresh the Local queue section in the body after meta changes.
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
 * @param {string} root
 * @param {string} key
 * @param {string} agent
 * @param {string} [localStatus]
 */
function assignOne(root, key, agent, localStatus) {
    const brief = readBriefByKey(root, key);
    brief.meta.agent = agent;
    if (localStatus) {
        brief.meta.local_status = localStatus;
        brief.meta.dirty = 'true';
    }
    writeBriefFile(brief.path, brief.meta, refreshLocalSection(brief.meta, brief.body));
    return brief.path;
}

/**
 * @param {string[]} args
 */
export async function assignCommand(args) {
    const root = findProjectRoot();
    loadConfig(root); // ensure project is initialised

    const roundRobin = args.includes('--round-robin');
    const all = args.includes('--all');
    const unassignedOnly = args.includes('--unassigned');
    const statusFlag = args.find((a) => a.startsWith('--status='))?.slice('--status='.length);

    const positional = args.filter((a) => !a.startsWith('--'));

    if (roundRobin) {
        const agents = positional;
        if (agents.length === 0) {
            throw new Error(
                'Usage: buggie assign --round-robin <agent1> <agent2> […] [--unassigned] [--status=todo]',
            );
        }

        let paths = listBriefPaths(root);
        if (unassignedOnly) {
            paths = paths.filter((path) => !readBriefFile(path).meta.agent);
        }
        if (paths.length === 0) {
            console.log('Nothing to assign. Run `buggie pull` first.');
            return;
        }

        let i = 0;
        for (const path of paths) {
            const { meta } = readBriefFile(path);
            const agent = agents[i % agents.length];
            const key = meta.key;
            assignOne(root, key, agent, statusFlag ?? (meta.local_status || 'todo'));
            console.log(`${key} → ${agent}`);
            i += 1;
        }
        console.log(`Assigned ${paths.length} issue(s) across ${agents.length} agent(s).`);
        return;
    }

    if (all) {
        const agent = positional[0];
        if (!agent) {
            throw new Error('Usage: buggie assign --all <agent> [--unassigned] [--status=todo]');
        }
        let paths = listBriefPaths(root);
        if (unassignedOnly) {
            paths = paths.filter((path) => !readBriefFile(path).meta.agent);
        }
        for (const path of paths) {
            const key = readBriefFile(path).meta.key;
            assignOne(root, key, agent, statusFlag);
            console.log(`${key} → ${agent}`);
        }
        console.log(`Assigned ${paths.length} issue(s) to ${agent}.`);
        return;
    }

    const [key, agent] = positional;
    if (!key || !agent) {
        throw new Error(
            `Usage:
  buggie assign <KEY> <agent> [--status=todo]
  buggie assign --all <agent> [--unassigned] [--status=todo]
  buggie assign --round-robin <a1> <a2> […] [--unassigned] [--status=todo]`,
        );
    }

    const path = assignOne(root, key, agent, statusFlag);
    console.log(`${key} → ${agent}`);
    console.log(path);
}
