# Local agent loop

Pull Buggie issues into a project checkout, assign them to local agents, work on
fixes, then push status updates back for triage — without zip files or cloud
agent API keys.

## Flow

```
buggie pull
    → .buggie/issues/*.md

buggie assign --round-robin agent-1 agent-2
    → each brief gets agent: … (local only)

# agents edit code; humans/agents set status locally
buggie status CP-3 ready_for_review
    → local_status + dirty (not yet on Buggie)

buggie push
    → PATCH status on Buggie (mapped via status_map, e.g. In Review)
```

## Commands

| Command | Network? | Purpose |
| --- | --- | --- |
| `pull` | yes | Bulk download matching issues |
| `list` | no | Local queue (agent, local status, dirty) |
| `assign` | no | Assign one / all / round-robin to agent names |
| `status KEY …` | no | Set local status, mark dirty |
| `status KEY … --push` | yes | Set and write through immediately |
| `push` | yes | Sync all dirty local statuses to Buggie |
| `show` / `open` | show: yes | Refresh one brief / print URL |

## Setup

```sh
node /path/to/buggie/packages/cli/bin/buggie.js init
# edit .buggie/config.json
export BUGGIE_TOKEN='…'   # staff PAT, read+write — for pull/push only
```

Against local Docker: `host` like `http://acme.buggie.localhost:8088`.
`query` uses the project **slug** (`project:customer-portal`), not the key.

## Status mapping

Local values are not Buggie status names. `status_map` in config translates on push:

| Local | Default Buggie status |
| --- | --- |
| `todo` | Todo |
| `triage` | New |
| `in_progress` | In Progress |
| `ready_for_review` | In Review |
| `done` | Done |

Adjust names to match each project's boards.

## Live Matrix host

Until [Matrix-Internet/buggie](https://github.com/Matrix-Internet/buggie) is
deployed to production, prefer local Docker for pull/push. Production may lag
(API catalogs / ids).

## Not in this loop yet

- QC plugin filing snags into Buggie
- Cloud agent dispatch / API keys in Buggie
- Comments over the token API
