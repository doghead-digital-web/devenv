# Agent harness facts

The shared agents in `~/.claude/agents` read this file for everything
specific to the doghead devenv module. Each `##` heading is a name the agents
point at; keep the headings stable.

## Product and client

- What the product is: the devenv module every Doghead Digital Laravel client
  app imports (`laravel/`): its dev environment, git hooks, CI scripts, and
  the Claude Code guards that keep agents off other worktrees' databases,
  processes and quality gates. Joey owns it; there is no client. `README.md`
  says how an app adopts it, `CONTEXT.md` why each piece is shaped as it is.
- The customer: Joey and the agents working in each app. A change here
  reaches every app on its next `devenv update doghead`, so a regression is
  every client's at once.
- Mockups and handoff: none.
- What it outputs: shell environments, scripts and Claude Code settings for
  the apps; no UI.

## Linear

- Team: none. Work on the module is tracked on the app ticket that needs it.
  Commits read `type(scope): summary`.
- Questions only Joey can answer: Joey.

## Default branch

`main`: the branch every change is cut from and merges into, and the
revision the apps pin in their `devenv.lock`.

## Guarded external systems

None: the module sends nothing anywhere. It defines the guards each app's
agents run under; their rules are in `CONTEXT.md`.

## Standing acceptance criteria

Every change keeps `devenv shell -- composer test` green, adds a failing
case first for any guard behaviour it changes, and leaves the generated
config of an app that does not opt into the change the same.

## Threat model

- Attackers: an agent in an app session whose Bash, Monitor or Edit call is
  the guard's input: a command shaped to slip past a guard (multi-line,
  quoted, abbreviated, piped, run through Monitor), or a write to a file a
  guard protects under another name.
- Tenants: none.
- Run-as mechanism: none.
- Credentials in scope: none.
- Long-form notes: `CONTEXT.md`.

## Research beat

None.

## Browser and seed users

None: the module has no app to browse.

## Framework skills

None beyond the Pest conventions in `tests/`.

## Dev stack and test database

- Stack: Nix (devenv modules), bash, and a Pest suite on Orchestra Testbench
  that evaluates each guard and script with `nix-instantiate` and runs it.
- Fixtures folder: `tests/fixtures/`.
- Test database: none. No test touches MySQL; the destructive-database guard
  is tested on command strings only.

## Probe tooling

- Probe command: evaluate a guard and feed it a payload, such as
  `nix-instantiate --eval --strict --json laravel/guards/process-kill.nix --argstr jq jq --argstr grep grep | jq -r .command`, then pipe `{"tool_input":{"command":"..."}}` into `bash -c` on that command and read the exit code.
- Skill to load first: none.
- Overlap check: none; the suite shares nothing between runs.

## Quality gates

| Command | Role |
| --- | --- |
| `devenv shell -- composer test` | the **layer gate**: the Pest suite, then `laravel/guards/ask-before-publishing.test.sh` |
| none | the **browser suite** |
| `devenv shell -- vendor/bin/pest --group=wiring` | the fixture app's generated hooks, through `devenv eval` |

## Launcher and worktrees

- Launcher: none; the module has no `feature` script of its own.
- Worktree and stack names: `<type>-<slug>` worktrees beside `main`.
- Seeding: none.

## Coordination

- Capacity: one change at a time.
- Status file: none.
- State file: none.
- Merge settings: GitHub defaults.
- Words that trip a hook: none in this repo; the guards it defines trip in the apps.
- Teardown: by hand.

## Anti-comment allow-list

None.
