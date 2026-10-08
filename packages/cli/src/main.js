import { assignCommand } from './commands/assign.js';
import { initCommand } from './commands/init.js';
import { listCommand } from './commands/list.js';
import { openCommand } from './commands/open.js';
import { pullCommand } from './commands/pull.js';
import { pushCommand } from './commands/push.js';
import { showCommand } from './commands/show.js';
import { statusCommand } from './commands/status.js';

const USAGE = `Usage: buggie <command> [args]

Local agent loop (pull → assign → work → status → push):

  init                              Write .buggie/config.json skeleton
  pull [--q=QUERY]                  Pull matching issues into .buggie/issues/ (bulk)
  list [--agent=NAME] [--dirty] [--unassigned]
                                    Show local queue
  assign <KEY> <agent>              Assign one issue to a local agent
  assign --all <agent>              Assign every pulled issue
  assign --round-robin a1 a2 […]    Spread unassigned/all across agents
  status <KEY> <local_status>       Set local status only (marks dirty)
  status <KEY> <status> --push      Set and push to Buggie immediately
  push [KEY…]                       Push dirty local statuses up to Buggie
  push --all                        Push every issue that has a local_status
  show <KEY>                        Refresh one issue brief from Buggie
  open <KEY>                        Print the issue URL

Local statuses (mapped via config status_map): todo, triage, in_progress,
ready_for_review, done

Auth (pull/push/show only): BUGGIE_TOKEN or .buggie/credentials
`;

/**
 * @param {string[]} argv
 */
export async function main(argv) {
    const [command, ...rest] = argv;

    switch (command) {
        case 'init':
            await initCommand(rest);
            return;
        case 'pull':
            await pullCommand(rest);
            return;
        case 'list':
            await listCommand(rest);
            return;
        case 'assign':
            await assignCommand(rest);
            return;
        case 'show':
            await showCommand(rest);
            return;
        case 'status':
            await statusCommand(rest);
            return;
        case 'push':
            await pushCommand(rest);
            return;
        case 'open':
            await openCommand(rest);
            return;
        case '-h':
        case '--help':
        case 'help':
        case undefined:
            console.log(USAGE);
            return;
        default:
            throw new Error(`Unknown command: ${command}\n\n${USAGE}`);
    }
}
