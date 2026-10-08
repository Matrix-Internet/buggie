# `@buggie/cli`

Local agent loop for Buggie issues:

1. **Pull** bugs into `.buggie/issues/*.md` (bulk)
2. **Assign** them to local agents (names you choose — no cloud API keys)
3. Agents work from the markdown briefs
4. Set **local status** (`ready_for_review`, etc.)
5. **Push** status changes back up to Buggie for triage

Requires **Node 22+**. Zero runtime dependencies.

## Install

```sh
node packages/cli/bin/buggie.js help
# or: npm link --prefix packages/cli
```

## Setup (once per project)

```sh
cd /path/to/your-theme-or-app
buggie init
```

`.buggie/config.json`:

```json
{
  "host": "http://acme.buggie.localhost:8088",
  "project": "CP",
  "query": "is:open project:customer-portal",
  "agents": ["agent-1", "agent-2"],
  "status_map": {
    "todo": "Todo",
    "triage": "New",
    "in_progress": "In Progress",
    "ready_for_review": "In Review",
    "done": "Done"
  }
}
```

- `host` — workspace subdomain origin
- `project` — issue key prefix (`CP`)
- `query` — uses project **slug** (`customer-portal`), same as the Buggie filter bar
- `status_map` — maps local statuses to Buggie status **names** on push

Token (only for pull / push / show):

```sh
export BUGGIE_TOKEN='…'   # Settings → Workspace → API tokens (read+write)
# or: echo '…' > .buggie/credentials
```

Assigning agents and setting local status do **not** call Buggie.

## Loop

```sh
buggie pull                                          # bulk download
buggie assign --round-robin agent-1 agent-2 --unassigned --status=todo
buggie list

# agent works on .buggie/issues/CP-3.md …

buggie status CP-3 in_progress                       # local only
buggie status CP-3 ready_for_review                  # local only, dirty
buggie push                                          # sync dirty → Buggie (e.g. In Review)
buggie list --dirty
```

Immediate remote update: `buggie status CP-3 done --push`.

## Why a token at all?

Pull and push talk to Buggie’s HTTP API. Local assignment is just editing files on disk. One staff token per machine/env is enough — agents never see it.
