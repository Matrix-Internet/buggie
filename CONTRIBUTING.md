# Contributing

## Getting set up

There is no local PHP: everything runs in Docker.

```sh
docker compose up -d
npm install && npm run dev
./bin/art migrate --seed
./bin/test
```

`./bin/art`, `./bin/composer` and `./bin/test` wrap the containerised toolchain.

## Before opening a pull request

```sh
./bin/test        # must pass
npm run types     # must be clean
npm run build     # must succeed
```

## What gets a quick yes

- A bug fix with a test that fails without it.
- Widget compatibility fixes — it runs in browsers we cannot all test in, and reports
  from the field are genuinely useful.
- Documentation that would have saved you an hour.

## What to discuss first

- New dependencies, especially in the widget. It is ~6KB gzipped and every visitor to
  every site running Buggie downloads it.
- Anything that changes the tenancy scope, the client visibility rules, or the widget's
  redaction. These have tests written adversarially on purpose; read them first.
- New configuration. If it can be inferred, infer it.

## Things worth knowing

`AGENTS.md` is the working guide to the codebase — the invariants and the traps. A few
that catch people:

- `workspace_id` is never mass-assignable. The `BelongsToWorkspace` trait stamps it.
- Statuses are renameable per project but map to a fixed `StatusCategory`. Never test a
  status by its name.
- Comments and activity events default to **internal**. Something a client can see is a
  decision, never an accident.
- The widget's redaction happens in the DOM before rasterising, not by painting over the
  canvas afterwards. The earlier approach put the masks on the wrong elements.

`docs/DESIGN.md` records why things are the way they are, including the mistakes.

## Licence and contributor terms

Buggie is AGPL-3.0, and everything published here stays that way.

There is also a commercial hosted service at buggie.eu. It runs this exact code, with
nothing added and nothing held back; `BUGGIE_HOSTED=true` is the only difference.

The terms below still ask for more than the AGPL alone would, and the reason is to
keep one option open. The hosted service may one day want a private add-on, shipped as
a separate Composer package rather than a fork. Under AGPL §13, anyone who runs a
modified version as a network service must offer its users the source of that
version, add-on included, unless every line of the code it builds on can be licensed
under other terms. One accepted contribution without the licence below would make
that impossible for good: its author's permission would be needed, and could not be
assumed.

Being asked to permit that is a reasonable thing to decline. If you would rather not,
open an issue describing the problem instead — a good bug report is worth more than
most patches, and there is no agreement attached to one.

### The terms

By opening a pull request you confirm that:

1. **You wrote it, or you have the right to submit it.** It is your own work, or you
   have permission from whoever owns it — an employer, a client, or another project
   whose licence allows it. If you are contributing code you did not write, say where
   it came from.

2. **You keep your copyright.** Nothing here transfers ownership. You may use your own
   contribution anywhere else, for anything, without asking.

3. **You grant a licence to use it, including commercially.** You grant Brian Power a
   perpetual, worldwide, non-exclusive, royalty-free and irrevocable licence to
   reproduce, modify, distribute and sublicense your contribution, under the AGPL-3.0
   and under other terms, including proprietary ones. In plain words: it can be used
   in the hosted service, which is paid for.

4. **You grant the same patent licence** for any patent claim you own that your
   contribution would otherwise infringe, on the same perpetual and irrevocable basis.

5. **You offer it as-is.** No warranty, and no obligation on you to maintain it.

What this does not do: it does not let anyone take Buggie proprietary. The published
project is AGPL-3.0 and your contribution ships under that licence like everything
else. Anyone self-hosting keeps the full source and every AGPL freedom, including the
right to the source of any modified version they are served.

### Small changes

Typo fixes, comment corrections and documentation edits are taken at face value. Do
not read the above as ceremony for a one-line spelling fix.

Taking part means following the [code of conduct](CODE_OF_CONDUCT.md).
