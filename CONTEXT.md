# The Laravel module

Design notes for `laravel/`, shared by every app that imports it. An app's
own `nix/CONTEXT.md` covers only what that app adds. Ticket references
(`HRVST-*`, `JSH-*`) name the app where a rule was learned.

## The generated files are read-only, and a stale shell keeps the old ones

`.pre-commit-config.yaml`, `.claude/settings.json` and `.mcp.json` in an app
are read-only symlinks into `/nix/store/…`, generated from `git-hooks.nix`,
`agents.nix` and the app's own `nix/agents.nix`. Editing them is not
possible and would not be the fix if it were.

The generated files are only rewritten when the devenv shell is entered.
Anyone sitting in a shell from before a hook change keeps running the old
hook, which can call a script or a binary that no longer exists and fail
with a confusing error. Exit and re-enter the shell (or `direnv reload`)
after a module update or any change to the app's devenv files. This bit
HRVST-38, which renamed the `deptrac` hook to `arch` and dropped the
`deptrac/deptrac` package in the same change: a stale shell still shelled
out to `vendor/bin/deptrac`, which by then was gone.

## Claude Code reads hooks when a session starts

A session that started before a hook changed keeps the old one, much as a
stale shell keeps old git hooks. After a hook changes, every running agent
session in every worktree restarts, after the shell reloads, before it is
trusted with that hook. HRVST-180's builder found this when a piped dry run
went through a session that predated `block-teardown`.

## `claude-yolo` turns every hook off for one session, at launch

With `yolo.enable`, the shell gets `claude-yolo`: `claude
--dangerously-skip-permissions --settings '{"disableAllHooks":true}'`, for
proof-of-concept work where the guards cost more than they save. It drops
`git-hooks-run` and `builder-ci-gate` along with the guards, so nothing
formats an edit or holds a builder's task open.

It is a launch flag rather than an environment variable each guard reads,
so the bypass is a decision made outside the session and ends with it. An
agent cannot start its own session with it, and nothing it writes turns it
on. An agent could still write `disableAllHooks` or `env` into
`.claude/settings.local.json` for the next session; that is the
`qualityGate` list's job, not this script's. The flag is CLI-level settings,
which outrank the project's `.claude/settings.json`, checked with a
throwaway project whose `UserPromptSubmit` hook fired without the flag and
not with it.

## The hooks dir is shared across worktrees, so a hook must never name a worktree

Every worktree commits through the one hooks dir of the repository, and
each worktree's shell installs into it. devenv's own install task ran
`prek install -c "$DEVENV_ROOT/.pre-commit-config.yaml"`, so the hooks
named whichever worktree's shell loaded last, and a `/nix/store` prek with
it. That breaks three ways:

- When that worktree is removed, every commit in every worktree fails with
  `config file not found` until another shell reloads.
- Commits run the hook set from that worktree's branch, so a hook added,
  removed or renamed on one branch applies to every other branch's commits.
  HRVST-38's `deptrac` to `arch` rename failed this way.
- Once the named prek is garbage-collected, the hook silently falls back to
  whatever `prek` is on `PATH`.

It does not make a commit check another checkout's code: the hooks are
`language: system` with relative entries (`vendor/bin/pint`,
`composer lint:arch`), and prek runs them from the committing worktree's
root whichever config it was given.

`git-hooks.nix` therefore replaces `devenv:git-hooks:install` with
`mkForce`, and installs a shim that finds the committing worktree with
`git rev-parse --show-toplevel` and runs that worktree's
`.devenv/profile/bin/prek` against its `.pre-commit-config.yaml`. The shim
holds no worktree path and no store path, so every worktree installs the
same bytes and the install only rewrites a hook whose content differs. A
missing config still fails closed: the shim never passes
`--skip-on-missing-config`.

It overrides a devenv-internal task because `git-hooks.install.enable =
false` is ignored by devenv's task (cachix/devenv#2511 and #3204 track
worktree support). After a devenv update, check with `devenv eval tasks`
that `devenv:git-hooks:install` still runs the shim install and that no
other task or `enterShell` runs `prek install`.

The shim keeps prek's `# ID:` line because `prek install` uses it to
recognise its own hook. A worktree on an older config runs `prek install`
on shell load, and without that line it would move the shim to
`<hook>.legacy`, which puts prek in migration mode and runs the old hook as
well. The install also deletes any `<hook>.legacy` it finds, since one left
behind runs before the shim.

## The database names come from one builtins-only function

`database-names.nix` is a function of the app name and the worktree name
that returns `dbName` and `testDbName`. It uses builtins only, so plain
`nix-instantiate --eval` evaluates it without nixpkgs, and every caller
gets exactly what the worktree's shell got: `workspace.nix`, `feature`'s
collision check and `teardown`. A second implementation in bash would drift
the first time either changed, and the hash-shortened names are where it
would drift unnoticed.

Both refusals, the `[a-z0-9_]+` check and the dev database that ends in
`_test` or `_test_<N>`, live inside the function rather than in
`workspace.nix`, so every caller gets them. A caller that evaluated the
names without them could name a dev database that is another worktree's
test database. Lowercasing the basename and the CI fallback to
`<appName>_test` stay in `workspace.nix`, because they depend on where the
shell runs, not on the name.

`feature` and `teardown` evaluate the copy of the function in the module
their shell was built from. That is why `teardown` checks `main`'s
`devenv.lock` against its own (below).

## Each worktree has its own test database, except in CI

`testDbName` is the worktree's `dbName` plus `_test`, and `services.nix`
exports it as `DB_TEST_DATABASE`; `<appName>:databases` creates both
databases. One shared test database let one worktree's `migrate:fresh` wipe
another's tables mid-run, which failed tests at random and made mutation
sweeps read falsely high.

The name budget is 64 minus `_test_999`, so 55 characters. `pest
--parallel` appends `_test_<N>` per process, and MySQL caps a name at 64,
so a base name longer than 55 keeps its first 41 characters and ends in
`_<8 hex>_test`, the hex being the start of the worktree name's sha256. A
two-digit token then gives at most 63 characters; tokens up to 999 fit in
64, and beyond that MySQL refuses to create the database, which fails
closed.

`dbName` must not end in `_test` or `_test_<N>`. So a worktree named
`x-test` fails to evaluate rather than get the dev database `<app>_x_test`,
which is worktree `x`'s test database and which `x`'s suite would wipe, and
a worktree named `test` cannot get `<app>_test`. Rename such a worktree.
Every test database name, CI's and the shortened ones included, ends that
way, so no dev database can be another worktree's test database.

Worktrees named `a-b`, `a.b` and `a_b` fold to one name, and a crafted name
can equal another worktree's hashed name. `feature` refuses to create a
worktree whose names collide with an existing one's, and `teardown` refuses
to drop a database another worktree also has. Neither case can turn a dev
database into a test database.

CI runs the suite through `devenv shell` too, with `CI` set, so
`testDbName` falls back to `<appName>_test` there, the database CI's MySQL
service provides. devenv's eval cache re-reads `CI`: entering the shell with
and without `CI=1` gave `harvst_test` and the per-worktree name in turn.

## Ports and the Redis index come from one slot, shared by every app

`workspace.nix` hashes `<appName>/<worktree>` to a slot from 1 to 500.
php-fpm listens on `8500 + slot` and Vite on `5673 + slot`, and Redis gets
database `1 + hash mod 127`. A `.devenv-index` file at the worktree root
holding a number from 1 to 500 overrides the slot; anything else fails to
evaluate. It does not move the Redis index, which always comes from the
hash, so two worktrees can still share one; with 127 indexes that takes
about twice as many live worktrees as it did with 63.

The range needs a Redis with 128 databases, which Joey's nix-darwin Redis
runs since Oct 19 (`--databases 128`). Index 0 is left to anything outside
the apps. A Redis with fewer databases, such as a CI service on the default
16, refuses a high index with "DB index is out of range"; neither app's
suite or orb setup talks to Redis today.

Every app shares the one range. The apps used to hash the worktree name
alone onto per-app bases (8500 and 8600, 5673 and 5773) whose 500-wide
ranges overlapped, so a JSHQ worktree and a Harvst worktree could land on
one port. In one shared range the app has to be in the hash, or every
app's `main`, and any two worktrees with the same name, would get the same
ports.

Two worktrees can still hash to one slot. The `vite` process used to start
by `kill -9`-ing whatever held its port, which across a shared range could
be another app's Vite, and broke the rule that an agent never stops a stack
it did not start. It no longer kills anything: the app's `vite.config`
sets `strictPort`, so the second Vite fails loudly. Pin a different
`.devenv-index` in one of the two worktrees when it happens.

## A Caddy site is stale once git lists no worktree by its name

The Caddy task prunes an `<appName>-<name>.caddy` site when `git worktree
list` in the current checkout names no worktree `<name>` (a checkout with its
own `.git` directory counts as `main`), and never prunes `main`'s or its own.
It used to check for a folder at `<worktreesRoot>/<name>` instead, and the two
apps disagreed on `worktreesRoot` for a checkout with its own `.git`: Harvst
counted the checkout itself as the root, which in a plain clone with sibling
worktrees would have pruned every live worktree's site. Asking git holds for
any layout. If `git worktree list` fails, the task prunes nothing.

## The app's test bootstrap, not `phpunit.xml`, picks the test database

`services.nix` exports `DB_DATABASE` for the worktree's dev database, and
PHPUnit's `<env>` never overrides a variable that is already set. A plain
`<env name="DB_DATABASE" value=":memory:"/>` would point the suite at the
dev database, so each app's `phpunit.xml` sets `DB_CONNECTION` as `<server
… force="true"/>`. `phpunit.xml` cannot read the environment either, so the
app's `tests/bootstrap.php` sets `$_SERVER['DB_DATABASE']` from
`DB_TEST_DATABASE`, which Laravel's `env()` reads first. Only a process
outside devenv, with neither variable set, falls back to `<appName>_test`.
PHPUnit, ParaTest's workers, mutation children and `pest --agent` all load
it, and Laravel's parallel testing appends `_test_<N>` to whatever it set.

The bootstrap refuses to run when:

- the test database equals the shell's `getenv('DB_DATABASE')`, which still
  holds the dev database;
- the shell sets `DB_DATABASE` but not `DB_TEST_DATABASE`: that shell
  predates the per-worktree name, and the fix is `direnv exec . <cmd>` or
  `direnv reload`, never setting the variable by hand;
- the test database's name does not end in `_test`: that ending is what
  makes a name a test database, since `dbName` can never end that way, and
  a dev name set there by hand would otherwise pass every other check;
- the shell sets `DB_URL`: Laravel merges it over `DB_DATABASE`, and
  `phpunit.xml`'s empty `DB_URL` carries no `force`, so it never overrides a
  variable the shell already set.

`phpunit.xml` also forces `APP_CONFIG_CACHE` to
`bootstrap/cache/config.testing.php`, which nothing writes. With a
`bootstrap/cache/config.php` from `config:cache`, Laravel ignores the
environment, the bootstrap's `DB_DATABASE` is lost, and `RefreshDatabase`
aims at the cached dev database. The in-app guard (each app's
`app/Providers/CONTEXT.md`) prohibits that `migrate:fresh`, but a
prohibited command does not fail the test, so the suite would carry on
against the dev database. Runners that skip `config:clear` (`pest --agent`,
a bare `vendor/bin/pest`, `--parallel`, TIA) would all hit it.

## `ci-browser` does not migrate anything itself

It used to run `APP_ENV=testing php artisan migrate:fresh --force` first, a
leftover of Harvst's retired Playwright harness. `APP_ENV=testing` does not
change `DB_DATABASE`, so locally it wiped the worktree's dev database. A
browser test uses `RefreshDatabase`, which migrates the bootstrap's test
database itself.

## The reserved databases

`reserved-databases.nix` is `^<appName>(_(main|develop|test|e2e)(_|$)|$)`.
`teardown` refuses any worktree whose names match it, and
`block-destructive-database` gives a checkout whose dev database matches it
no reseed form. The `_test_<N>` copies are covered too, because they share
`testDbName`'s prefix. The `e2e` databases belong to no worktree, and the
bare app name is the production database's name, never a worktree's own,
since `database-names.nix` always appends `_<worktree>`; `<app>_x` stays
unreserved. A list built from today's databases would go stale; a pattern
does not.

Each app's in-app guard writes the same pattern out a second time, matching
case-insensitively, because dev MySQL runs `lower_case_table_names = 2`.
This copy stays lowercase, since `database-names.nix` only ever produces
lowercase names.

## `block-destructive-database` binds the database to the command

For the test database, the hook allows a destructive command in one form
only, on a single line, because `grep -x` matches each line on its own:
`DB_DATABASE=<test> APP_ENV=testing php artisan …` (or
`DB_DATABASE="${DB_TEST_DATABASE:?}" APP_ENV=testing …`), naming the
worktree's test database or a `_test_<N>` copy. A substring match let
through chained commands, `APP_ENV=testing` (which does not change the
database) and a test database name in a trailing comment.

A raw drop through `mysql` or `mysqladmin` is always blocked, even of the
test database. Joey removes a finished worktree's databases with `teardown
<worktree>` from the main checkout, and the block message tells the agent
to hand him that command. Allowing a raw one meant parsing client syntax
with a regex, and that lost: an earlier `-e` could carry its own statement,
spelled with `/**/` for spaces and ended with `\g`, and `mysqladmin` takes
several `drop` arguments. The trigger is deliberately wide for the same
reason, matching any `drop` followed by whitespace or `/*`, and any word
that Symfony's console could resolve to one of the five destructive
commands, in any case. Symfony abbreviates both halves of a name: the
namespace to any prefix, even an empty one, and the command to any prefix
that is unique, so `migrate:fre`, `mi:fr`, `:fr`, `d:wi` and `MI:ROL` all
run. The trigger takes any prefix of `migrate` or `db`, a colon, then a
prefix of a destructive command's name, as a whole word, so
`migrate:status`, `db:seed` and `db:show` get through. The module's test
suite asks Symfony's `Application::find()` for each abbreviation of the
`migrate` and `db` commands and feeds each one to the hook. A name split by
shell quoting, such as `mi:"fr"`, still gets past it, like a name built in a
variable (`n=mi:fr; php artisan $n`).

The artisan form requires `APP_ENV=testing` so that the in-app guard always
checks the database the command resolves. Without it, a
`bootstrap/cache/config.php` makes Laravel ignore `DB_DATABASE`, the command
wipes the cached dev database, and the guard stays out of it because a
plain shell is not a testing context. For the test database the in-app
guard is the control and the hook is defence in depth. It still blocks a
read-only command that merely mentions a destructive keyword.

The hook's text comes from `guards/destructive-database.nix`, a
builtins-only function of the checkout's names, so the tests can evaluate it
for a feature worktree and for `main` in one run. A test that read
`.claude/settings.json` would only ever see the checkout it ran in, and
CI's checkout is `main`. Its matcher is `^(Bash|Monitor)$`: the Monitor tool
runs a shell command too, and with `^Bash$` it went past the hook. Every
shell-command guard here matches both for the same reason.

## A feature worktree may rebuild its own dev database, in two exact strings

`DB_DATABASE=<dbName> php <worktreeRoot>/artisan migrate:fresh --seed`, or
the same line with `--seeder=DemoSeeder` in place of `--seed`, with both
names baked in when the shell is built. Where an app's `DemoSeeder` calls
`DatabaseSeeder` itself, a second seed on top of `--seed` would insert the
users twice, so a ticket that changes the demo seed needs the second string.

- The match is string equality, not a regex: the root has a `.` in it
  (`github.com`), which a regex reads as any character. Nix refuses to
  evaluate a root that is not only letters, digits, `.`, `_`, `-` and `/`.
- Artisan is named by its absolute path because Laravel takes its base
  path, `.env` and `bootstrap/cache` from where artisan is, never from the
  cwd. A `cd` in an earlier Bash call persists, so the hook cannot trust the
  input's `.cwd` or a relative `artisan`.
- The database is the literal name, never `"$DB_DATABASE"`: the inline
  value beats anything `.env` says, because Laravel's dotenv repository is
  immutable.
- The hook refuses the strings while `bootstrap/cache/config.php` exists,
  while `.env` has any line setting `DB_URL` or `APP_CONFIG_CACHE`, or while
  the session sets `DB_URL`, `APP_CONFIG_CACHE` or `APP_ENV`. Each makes
  Laravel resolve a database other than the inline one. It checks that a
  line is there, never what it says, so parsing `.env` cannot go wrong; a
  quoted key counts, because phpdotenv strips the quotes from a name. The
  in-app rule, not this grep, is what closes a redirect to another database
  name, and a `DB_HOST` or `DB_PORT` pointing the worktree's own name at
  another server is an earlier write, below.
- A checkout whose dev database matches `reserved-databases.nix` gets no
  reseed form at all. That covers `main` and a linked worktree named
  `main`, which both get `<app>_main`.

For the dev database the hook is the gate and the in-app rule is the
control: outside a testing context Laravel refuses a wipe of a reserved
database, and of any database other than the shell's own `DB_DATABASE`
(each app's `app/Providers/CONTEXT.md`). Those checks are where an
`APP_CONFIG_CACHE` set some other way, or a `DB_URL` the hook cannot see, is
caught.

Known gaps, which the hook does not close:

- Two worktree names can share one database (`foo-bar` and `foo.bar` both
  get `<app>_foo_bar`), so one worktree's reseed wipes the other's.
  `feature` refuses to create such a pair; a worktree made some other way
  can still be one.
- The allowed test-database form runs a relative `php artisan`, unlike the
  dev form's absolute path, so an earlier `cd` can point it at another
  checkout, which then wipes this worktree's test database with that
  checkout's code.
- `php` is resolved through `PATH`; a relative entry in it, after an
  earlier `cd`, can run another `php`.
- An earlier write can do anything a reseed could: a seeder, a migration, a
  provider, `bootstrap/cache/packages.php` or `services.php`, a settings
  file or a PHP file run with PDO. Every worktree connects to MySQL as
  `root`, so only per-worktree grants would close it.

## `block-teardown` is what keeps agents from tearing a worktree down

`guards/teardown.nix` blocks by default: any command that names `teardown`
as a word, in any case, with anything but a letter, digit, `_` or `-` on
either side, or attached to a short flag (`-Oteardown`), unless it is the
dry-run exemption or the search form below. The one exemption is a whole
single-line `teardown --dry-run <worktree>`, which is how agents verify the
command, optionally preceded by the literal `direnv exec . `. Agent Bash
tools do not have the devenv profile on `PATH`, so a bare `teardown` is not
found there. The prefix is fixed, with no other directory, flags or
variables, because `direnv exec <dir>` runs the command in another
directory's shell, and anything wider would let a real run through. The
message tells the agent to give Joey `teardown <worktree>`, run from the
main checkout, at hand-off.

The one allowed mention is a read-only search, `guards/search.nix`, safe by
construction: the whole command is `rg` followed by single-space-separated
tokens, each either plain (`A-Z`, `a-z`, `0-9` and `_ . / : = , * @ + -`)
or a single-quoted string. Nothing in it may be a newline, `"`, `$`, a
backslash, a backtick, `;`, `&`, `|`, `<`, `>`, `(`, `)`, `{` or `}`, quoted
or not, so nothing in it expands or chains a second command. No token,
quoted or not, may start with `--pre`, `--search-zip` or `--hostname-bin`,
or be a short flag cluster containing `z`, since those make `rg` run
another program. `grep` is not allowed: in agent shells it is Claude Code's
ugrep wrapper, whose `--filter`, `--pager`, `--view` and `--config` run
programs, and only tooling outside the app stops them. `block-teardown` and
`block-process-kill` are both built by `guards/word-guard.nix` and share
this search form.

A commit message or a PR body that mentions the word goes through a file:
`git commit -F <file>` or `gh pr create|edit --body-file <file>`. Those
command lines do not name it, so the trigger never fires. Write the file
with an editor tool, not a shell heredoc, since the heredoc's command line
does name it.

Everything else that names the word is blocked, multi-line or not: an
inline `-m`, `--body` or `--title` value, a heredoc, an `echo`, `cat`,
`grep`, `git log` or `git grep`, a name passed inside a quoted argument to
another program (`herdr pane send-text`, `python3 -c`, `perl -e`, a git
alias), and a name fed to a shell through a pipe, `<<<` or `<(…)`. Two
earlier versions allowed more. The first blocked `teardown` only in command
position, and security's review ran plain teardowns past it: a redirect
first, a `case` arm, `{teardown,x}`, `bash <<<`, `echo … | sh`, `$(which
teardown)` and a string of wrappers no list keeps up with. The second
allowed inline message values, wider read-only commands and a
quoted-delimiter heredoc, and its adversarial review could not be completed,
so those forms were left out until HRVST-192 proves them.

The cost is the accepted over-blocks: harmless commands that name the word
but are not the search form, such as `echo 'a;teardown x'`, `ls >teardown`,
`teardown.sh x` or `git commit -m 'fix teardown'`. Quote-aware matching in a
hook is an arms race; search with `rg` instead, or put the message in a
file.

The known gaps are names assembled at run time, which no text match can
see: `te''ardown`, `t\eardown`, `$'\x74eardown'`, `t=tear; ${t}down`, a
base64 string decoded and piped to `sh`, a name split inside `python3 -c`,
or one spelled with `printf` escapes. So are settings an earlier command
that does not name the word can plant for the search form: an
`RIPGREP_CONFIG_PATH` file, a `.ugrep` config file, or a file named like
`--pre=…` that an unquoted `*` glob expands into a flag. Each needs an
earlier write, the same class as a script written to disk and run later. A
disguised name still meets `teardown`'s own refusal of the agent variables
unless the agent clears them too, which is the same trust a disguised
`mysql` command already has under `block-destructive-database`. The module's
test suite runs every probe from the code and security reviews against the
hook, from a probe corpus that records each one as blocked, an accepted
over-block, allowed, or a known gap, so any change to the trigger turns the
suite red and prompts an update here.

The hook is the control. `teardown` itself also refuses a real run when
`CLAUDECODE`, `CLAUDE_CODE_ENTRYPOINT`, `CLAUDE_PID` or `AI_AGENT` is set at
all, even empty, and when stdin is not a terminal or `/dev/tty` cannot be
opened. Those checks are defence in depth only: a disguised name run under
`script` with the variables unset gets past them, which is why they were not
made any cleverer.

## `block-process-kill` blocks six trigger words by default, with an allow-list

HRVST-208's builder once ran `pkill -f "composer ci:check"` machine-wide to
stop its own gate, which could just as easily have killed another
worktree's run. `guards/process-kill.nix` blocks `pkill`, `killall`,
`kill-port`, `fkill`, `fuser` and `kill`, matched case-insensitively and
word-bounded the same way `teardown` is. A name list for the processes an
agent might target (vite, php, mysql, …) is an arms race with no end, so the
hook blocks the signal-sending word itself and allows only two forms, rather
than trying to keep a list of process names current.

A command that trips a trigger word runs only in one of two forms, each on
a single line: a literal-pid `kill`, matching exactly `kill(
-[A-Za-z0-9]+| -s [A-Za-z0-9]+)?( [1-9][0-9]*)+` and nothing else on the
line, or the `rg` search form `block-teardown` allows. Pids are positive
only: `kill 0`, `kill -9 -1` and `kill -- -4242` are refused, since a pid
of `0` or a negative pid targets a process group, not one process. `pkill`,
`killall`, `kill-port`, `fkill` and `fuser` get only the search form: none
of them takes a literal pid. Neither form accepts a `direnv exec` wrapper; a
real run through direnv is refused the same as a bare one.

The hook makes no check that a literal pid's process is actually in the
agent's own worktree (Joey: a follow-up). It trusts the agent to run `lsof
-p <pid>` and read its cwd first, the way the refusal message and the
prompts already ask. Closing that gap would need the hook to shell out to
`lsof` itself.

Known gaps, which the hook does not close: a word built the way
`teardown`'s obfuscations are (`p''kill`, `$'\x70kill'`, a word split
across a shell variable such as `${k}ll`, a name decoded from base64 and
piped to `sh`, a name split inside a language's own syntax such as Python's
`"ki" + "ll"`); and a signal API whose name extends or avoids a trigger
word, such as Python's `os.killpg` (contains `kill` but targets a process
group, which the hook does not distinguish from a single pid) or
`posix_kill` (never spells the bare word `kill`). The module's test suite
runs the teardown obfuscation forms once per trigger word, plus every
blocked or over-blocked probe row with `teardown` swapped for `kill`, so
dropping a word from the trigger turns that word's rows red.

Out of scope, not this hook's job: stopping MySQL or an app some other way
(`mysqladmin shutdown`, `brew services stop`, `launchctl kill` or
`bootout`, `osascript … quit`); `devenv processes down` or
`process-compose down` run against another worktree's root; and `herdr`
pane, tab or agent control of another worktree.

## Agents publish branches and draft PRs, and Joey merges

Joey reviews a feature as draft PRs, so a verified feature's branches and
draft PRs publish without a prompt: `git push [-u] origin <branch>`, `gh pr
create --draft`, `gh stack push`, `submit`, `sync` and `link`, a PR's title
and body, and `gh pr ready` (see "A PR's title, body and ready flag pass").
Merging, retargeting a PR, pushing to `main`, force-pushing outside `gh
stack` and any `gh` command the guard does not recognise wait for Joey. This is
JSHQ's policy, now every app's; Harvst's earlier rule, that any push or
pull request asks, is retired.

Branch protection and rulesets were unavailable on JSHQ's GitHub plan (the
API answered 403), and the agents' `gh` token has admin rights, so
`ask-before-publishing` is the guard on `main` until JSH-51 adds a pre-push
hook. It is a speed bump on the command line: it reads the command text, so
it fails closed. A payload it cannot parse, or a working directory git
cannot read, asks. The rules read the command flattened to one line and
lowercased, because macOS resolves command names case-insensitively, so
`GIT push` and `Gh pr merge` run git and gh. Each check runs on two
readings of that line. In the first, quotes and backslashes are stripped,
so `gh pr "merge"`, a `gh pr \` line continued into `merge` and `bash -c
"git push origin main"` are still caught. In the second, a quoted string,
even an empty one, counts as exactly one word, because stripping quotes
alone moves word boundaries: `git -c "a=b c" push` would otherwise put `c`
in the subcommand slot, and `--jq ''` would swallow the `-XPUT` after it.
The subcommand slot and gh's words come from the second reading, and a
check that matches either reading asks.

A push is `push` in git's subcommand slot, after any global options such as
`-C <dir>`, `-c <k=v>` or `--git-dir <dir>`, so `git stash push` and a
commit message that says push are not pushes. Any line that runs `git push`
asks unless the whole line is exactly `git push [-u|--set-upstream] origin
<branch>`, the branch is not `main`, a `refs/` or `heads/` path or a
`HEAD`, the repo has no `remote.origin.push` and a `push.default` of
`simple`, `current` or unset, and `<branch>` either has no local ref yet or
resolves to exactly `refs/heads/<branch>` and is not a symbolic ref. Git
also asks for any `GIT_CONFIG` in the command, alias set-up (`git -c
alias.`, `git config alias.`), `git send-pack`, a word whose basename is
`git-push`, `git-send-pack` or `git-receive-pack`, and a subcommand built
from `$`, a backtick or `{` (`git {push,} origin main`). That last check
sits only in subcommand position, so `git commit -m "$(cat <<EOF …)"` still
passes.

gh is an allowlist, because a denylist of bad gh forms leaked a new form in
each of three verify rounds. Any command with `gh` as a word asks unless it
is a line made only of reads (below) or a single gh command, with no `;`, `&`, `||`, `$(`, `<(`, `>(`, backtick,
`{` or newline, even inside quotes, of one of these shapes, with the group
and subcommand straight after `gh`:

- a read: `gh pr view|list|status|checks|diff`, `gh issue view|list`, `gh
  repo view`, `gh run list|view|watch`, `gh auth status` (not
  `--show-token` or `-t`, which put the token in the agent's context) or
  `gh release view|list`
- `gh api <endpoint>` whose only flags are `--paginate`, `--slurp`,
  `-i`/`--include`, `--silent`, `--jq`/`-q <expr>`, `-H`/`--header <value>`
  (not a method override), `--cache <dur>` and `-X`/`--method GET`, each a
  separate token; `graphql` asks
- `gh pr create` starting exactly `--draft` or `-d`, with that flag outside
  quoted text, no `--draft=` or `-d=` anywhere (quoted or not, so unbalanced
  quotes cannot hide one), and no pipe. Draft PR bodies therefore go through
  `--body-file`, because a `$(…)` body asks
- `gh stack
  view|push|sync|link|submit|rebase|add|init|top|bottom|up|down|checkout`,
  without `--open`, `--base` or a bare `main`

Any of these but `gh pr create` may end in one pipe into `jq`, `head`,
`tail`, `rg` (not `--pre` or `--hostname-bin`, both of which run a program,
even spelled `--p''re`), `grep` or `wc`. Everything else asks: `gh pr
merge|new|close`, `gh pr edit` and `gh pr ready` outside the shapes in "A
PR's title, body and ready flag pass" below, `gh stack merge|unstack`, the rest of `gh
repo`, `gh alias`, `gh secret`, `gh workflow`, `gh release create`, any
`-R`/`--repo` before the subcommand, and a `gh` behind `env`, `xargs`, `bash
-c` or a path. Because `gh` as any word triggers the check, a commit message
that names `gh` prompts, so write it with `git commit --file` or reword it.
A command word that is itself a variable or substitution (`$g push origin
main`), brace or glob characters in a command's first word (`{git,} push
…`) and GitHub API calls made without gh, such as curl with a token from
`git credential fill`, are JSH-51's, with the other commands built at run
time.

### A line made only of reads passes

Agents check state with one line of several reads, such as `gh repo view
--json visibility; git branch -r | rg feat-x`, and asking about each one
stalled panes with nobody at the laptop. So before the rules above, the guard
splits the line on `;`, `&&`, `||` and `|` and passes it when every piece is
one of these reads:

- a gh read from the list above, `gh stack view`, or a `gh api` GET of the
  shape above
- `git status`, `rev-parse`, `merge-tree`, and `log`, `show` or `diff`
  without `--output`, each with `git` straight before the subcommand
- `git branch` whose only flags are `-r`/`--remotes`, `-a`/`--all`,
  `-v`/`-vv`/`--verbose`, `-l`/`--list`, `--show-current` and `--no-color`,
  and which names a pattern only after `-l` or `--list`, so it cannot create
  a branch
- `git fetch` whose only flags are `-p`/`--prune`, `--all`, `-q`/`--quiet`,
  `-v`/`--verbose`, `-t`/`--tags`, `--no-tags` and `--dry-run`. This is an
  allow-list because git takes any unambiguous abbreviation of a long
  option, and `--upl=<cmd>` is `--upload-pack`, which runs `<cmd>`
- `rg` (without `--pre` or `--hostname-bin`), `grep`, `jq`, `head`, `tail`,
  `wc`, `cat`, `ls` and `date`

A piece whose first word is anything else, an environment assignment
included, makes the whole line fall through to the rules above, so a read
chained with `gh pr merge`, `git push`, `gh pr create --draft` or `echo`
asks as it did before. The split falls through the same way on anything it
can't read cleanly: `$`, a backtick, a brace, `<`, `>`, a backslash or a
newline anywhere, quoted or not; outside quotes, any character but letters,
digits, `_./:=,@%+-`, spaces and the separators, which rules out globs,
`#`, `(`, a lone `&` and `|&`; an empty piece; or an unclosed quote. A quoted
string is one word, so `rg 'a|b'` is not split.

The other pieces are reads an agent could already run on their own line,
because a line with no `gh` word only meets the push checks. What the split
adds is that a gh read can share the line, so it has to see the same
pieces the shell does.

### A PR's title, body and ready flag pass

Publishing a verified stack means a title, a body and `gh pr ready` on every
PR, and asking about each one cost Joey about six prompts a stack. None of
them merges anything, pushes to `main` or moves a PR's base, so a single gh
command of one of these shapes passes:

- `gh pr ready <number>`
- `gh pr edit <number>` with at least one of `--title <text>` and
  `--body-file <path>`

Each may add `--repo <owner>/<name>`. Every flag is spelled in full, as
`--flag value` or `--flag=value`, and nothing else may appear. The rule is
an allow-list, because every other `gh pr edit` flag changes something Joey
should see: `--base` retargets a PR, `--add-*`/`--remove-*` change reviewers,
labels and projects, and `--milestone` files it. gh's flag parser takes no
abbreviations, so `--tit` is an unknown flag to gh and asks here. Short flags
(`-t`, `-F`, `-R`), `--undo`, `--body` (an inline body), a second PR
number, a branch name or URL in place of the number, `--` and a value that
starts with `-` all ask.

`--body-file` must name a file ending in `.md`. The body is published in the
PR, so `--body-file .env`, `-` (stdin) or `id_rsa` asks. A `.md` file
anywhere on disk still passes, the same as `gh pr create --draft
--body-file` already does. That gap is accepted rather than closed with a
path allow-list, because PR bodies are written to scratch and team folders
whose absolute paths vary by session.

The line also falls through to the rules above, and so asks, when it has a
pipe, `$`, a backtick, a brace, `<`, `>` (a redirect), a backslash or any
control character (a tab, a carriage return, a newline) anywhere, quoted or
not, or a glob character or `~` outside quotes. Chained after anything else,
it asks as before, because the read-only split above doesn't include it. An
environment assignment in front (`GH_HOST=… gh pr ready 12`) asks too,
because `gh` must be the first word.

The hook runs from a `/nix/store` copy of
`guards/ask-before-publishing.sh`, with every tool it calls pinned, so a
copy of the script in an agent's reach changes nothing. An edit to the
module reaches an app only through a new `devenv.lock` and a rebuilt shell.
`guards/ask-before-publishing.test.sh` runs every case against the script,
and, given an app's generated `.claude/settings.json`, checks that the app
runs a current store copy of it that still asks with an empty `PATH`.

The hook answers `ask` rather than blocking, because a hook's `ask` still
forces a prompt in auto mode, and a hard block would leave Joey no way to
approve from inside the session.

## `teardown` is not a `pre-remove` hook, and gives `wt remove` a path only

`teardown <worktree>` removes what `feature` set up: the herdr tab, what
still runs in the worktree, the worktree, its merged branches and its
databases. It is a devenv script that calls `wt remove`, not a worktrunk
`pre-remove` hook, because a hook would run on every `wt remove`, an
agent's included, and drop databases with no prompt and no PreToolUse hook
in the way.

`wt remove` reads each argument as a branch or a path, and removes whatever
worktree has a named branch checked out, whatever its directory is called.
Layer names come from `gh stack view`, whose file an agent can write, so a
branch argument could remove another worktree. `teardown` therefore passes
the absolute path alone, always with `--foreground --no-hooks
--no-delete-branch --reap`, never `-D`, and deletes merged branches itself
with `git update-ref -d refs/heads/<branch> <sha>` against the SHA it
planned.

Merged has three routes, checked in order against a freshly fetched
`origin`, all from the planned SHA: the SHA is an ancestor of
`origin/main`; or `git cherry origin/main <sha>` prints no `+` line, so
every commit is in `origin/main` by patch; or `origin/<branch>` exists, has
the same tree as the SHA, and is an ancestor of `origin/main`. Ancestry
alone failed after the first real stack merged: `gh stack` rebases each
layer before it merges it, so the local layers keep SHAs that never reach
`origin/main`, and `teardown` refused a stack that had merged. The plan
names the route that matched.

`git cherry` skips merge commits, so a branch whose only new work is a
merge commit carrying its own changes would print no `+` line. The patch
route therefore also needs `git rev-list --merges origin/main..<sha>` to be
empty. The tree route only vouches for what was pushed: when
`origin/<branch>` is a strict ancestor of the planned SHA, the local
commits past it were never pushed, so that route does not match and they go
through the patch route or are refused. Worktrunk's own verdict counts an
empty net diff as merged, which would delete a commit and its revert. Here
the patch route sees both commits as missing from `origin/main`, the tree
route refuses local commits past the remote, and tests pin both.

Two limits of the patch route are accepted. `git cherry` compares patch
ids, which ignore whitespace, so a local commit that differs from an
upstream one only in whitespace counts as merged. A local empty commit
matches any empty commit upstream, so it counts as merged too; only its
message is lost.

A real run needs the main checkout with `main` checked out, and `git show
main:devenv.lock` byte-identical to the `devenv.lock` the shell was built
from. The database names come from the module that lock pins, so a branch
that moved the lock cannot steer `teardown` at other databases. `--dry-run` skips both and runs
anywhere.

`--reap` leaves alone any process with a controlling terminal, such as
`devenv up` in a pane, an editor or a Claude session. `teardown` closes the
worktree's herdr tab first, only when it is the one tab in the herdr
workspace labelled `<appName>` with that label, is not the tab `teardown`
runs in, and every pane in it sits in the worktree. It then checks once a
second, for up to ten seconds, for a process with a terminal whose cwd is in
the worktree, since the tab's shells take a moment to exit. If one is still
there, it stops and names it rather than kill it.

## A feature's stack grows from the worktree's branch

`feature` creates one branch, named after the worktree, and the builder
adopts it as the stack's bottom layer, naming each layer above it
`<worktree-branch>-<layer>`. A `<worktree-branch>/<layer>` name would be
tidier, but git cannot hold a branch and another branch nested under its
name, and adopting the branch keeps the launcher unchanged. The stack's
trunk stays `main`, so every layer's PR is based on `main` or the layer
below it.

## `feature` waits for each pane's shell before typing into it

`feature` opens three herdr panes in a new worktree, runs `lg` in one,
starts the feature lead in another, and leaves the third as a shell. A
pane's shell spends its first seconds loading the devenv environment
through direnv, and keystrokes sent in that window are lost: the first
version of the script left three idle shells. The script therefore waits
for `direnv: export` in each pane first, and builds the shell once with
`direnv exec` beforehand so that wait stays short. It looks up the herdr
workspace labelled `<appName>`, and refuses rather than guess when no
workspace or more than one carries that label.

## `feature` doesn't start the app, and there is no devenv MCP

Several worktrees run side by side, and each `devenv up` (Vite, php-fpm,
the queue, the scheduler and pail) costs real memory on Joey's laptop. Four
parallel stacks made it unusable, while most tickets are backend work that
only needs MySQL, which runs outside `devenv up`. So `feature` leaves the
third pane as a shell, and only work that needs the browser starts the app
there, then stops it. For the same reason the MCP servers include no
`devenv mcp`: every agent session starts its own copy of each server, and
nothing used that one.

## `feature` hands the brief to Claude as input, not as an argument

`herdr agent start` refuses agent arguments it cannot quote safely for the
pane's shell (`invalid_agent_argument`), so a brief holding quotes, `$` or
backticks failed the launch. The script starts Claude with no prompt and
then sends the brief with `herdr agent prompt`, which types it into Claude
rather than through a shell. Claude reads a message starting with `!` as a
shell command and one starting with `/` as a slash command, and `herdr
agent prompt` reads a leading `-` as an option and takes no `--`, so
`feature` refuses a brief that starts with any of the three, after leading
whitespace. It also refuses, before anything is created, a worktree name
outside `^[a-z0-9][a-z0-9._-]*$` and one ending in `test` or `test-<N>`,
which `database-names.nix` would refuse only after `wt switch --create` had
made the worktree and branch.

## `feature`'s control-character check on the brief needs `grep -z`

`grep`'s `[[:cntrl:]]` class never sees a bare newline inside its input,
because grep treats a newline as the boundary between two lines to search,
never as a character one of those lines could contain. A brief holding an
embedded newline passed the plain check and reached `herdr agent prompt`
unrefused; `grep -z` instead treats the whole input as one NUL-terminated
record, so an embedded `\n` shows up as ordinary content within it. `\r`
and `\x1b` were caught either way, since neither one is grep's line
separator.

## `feature` refuses a worktree whose databases would collide

A worktree named `x-1.a` and one named `x-1-a` fold to the same database
once `database-names.nix` replaces `.`, `-` and `/` with `_`. Before `wt
switch --create`, `feature` computes the candidate's names with that
function, then every other worktree's names the same way, and refuses on a
match. `teardown` runs the same comparison before it drops anything, so a
pair made outside `feature` still cannot lose a database the other needs.

## A new worktree seeds itself once

`<appName>:seed` runs `db:seed` only while the worktree's `users` table is
empty, because an app's `DatabaseSeeder` creates its users unconditionally
and a second run would duplicate them. It exists so an agent in a fresh
worktree can sign in as `test@example.com` without anyone seeding by hand.

## The builder's CI gate keys on the teammate's name

`builder-ci-gate` in `agents.nix` runs each `builderGate` script (`composer
ci:check` by default) when a task completes, but only for the teammate
named `product-builder`. TaskCompleted hooks take no matcher and fire for
every teammate, and the owner's or designer's tasks change no code, so
gating them would only cost time. The feature lead names each teammate
after its agent type; a builder spawned under any other name skips the
gate.

The gate checks the branch that is checked out, so on a stack it first
holds the task open until the builder is on the top layer, where the gate
covers the whole feature. Checking each layer from the hook would mean
checking branches out under the builder mid-task, so each layer's own green
is the builder's completion criterion rather than the hook's.

## `ci-lint` and the app's composer and pnpm scripts change together

`ci.nix`'s scripts are what each app's `.github/workflows/ci.yml` runs as
`devenv shell ci-lint` and the rest, and they call the app's composer and
pnpm scripts by name rather than inlining the commands. A script renamed in
an app's `composer.json` or `package.json` without the matching edit here
fails in CI only, because nothing local runs `ci-lint`. `formatter` picks
the pnpm scripts: `pnpm run check` for `vite-plus`, `pnpm lint:check` and
`pnpm fmt:check` for `prettier`.

`ci-test` runs `vendor/bin/pest --parallel` rather than `artisan test
--parallel`, because Laravel's parallel command rejects `--ci`. The job
never runs `refactor:check`, so `--ci` is what keeps a stray `->only()`
from narrowing it to a green subset.

## The formatting hooks take filenames; `arch` does not

`pint`, `rector` and the formatter's hook (`vp-check` or `prettier`) run on
the staged files only, and the `git-hooks-run` Claude Code hook runs them
on the one file an agent just edited. That override replaces devenv's
default, a bare `prek run`, which checks the staged set rather than the
edited file. Its matcher is the edit tools only: with no matcher it ran
after every Read too, so an agent reading a file in `vendor/` had pint
rewrite it.

Each hook's `files` pattern mirrors the scope of the app script it stands
in for: `rector`'s is the `withPaths` list in the app's `rector.php`,
`pint` leaves out Blade templates, `vendor/` and `node_modules/` the way a
bare `pint` run does, and the formatter's hook covers `resources/` and the
root `*.config.ts` files, what `pnpm fmt` or `vite.config.ts`'s `lint` and
`fmt` sections reach. An
app that widens a script's scope has to widen the pattern here too, or the
hook either misses files or rewrites ones the script never touched.

`arch` is a test suite over the whole codebase, so it keeps
`pass_filenames = false` and the per-edit hook skips it.

## `ci-hooks` skips what `ci-lint` already gates

`ci-hooks` runs every hook on every tracked file except `pint`, `rector`,
the formatter's hook and `arch`, which `ci-lint` already checks through
`composer fmt:check`, `composer refactor:check`, the pnpm checks and
`composer lint:arch`, and `no-commit-to-branch`, which only means something
at commit time. A new hook that no composer or pnpm script covers belongs in
`ci-hooks`, not in the skip list.

## `composer-audit` and the one abandoned package

The `composer-audit` pre-push hook fails on abandoned packages as well as
on advisories, because Composer's `audit.abandoned` defaults to `fail`.
`symplify/rule-doc-generator-contracts` is abandoned with no replacement,
and both `driftingly/rector-laravel` and `pestphp/pest-plugin-rector`
require it, even at their latest releases. It is a dev-only package of
interfaces for documenting Rector rules, so each app's `composer.json`
lists it under `audit.ignore-abandoned` instead of loosening
`audit.abandoned` for every package. Drop the entry once neither tool
requires it (`composer why symplify/rule-doc-generator-contracts` prints
nothing).

## `php.ini` sets ini values; it cannot add an extension

`languages.php.ini` in `languages.nix` reads `php.ini`, then the app's
optional `php.local.ini`, verbatim, so neither file can do more than set
directives for extensions that are already built. The extension list itself
is `languages.php.extensions`, a few lines above.

`ext-pcov` therefore took both edits: `"pcov"` in the extension list to get
a `pcov.so` at all, and `pcov.enabled = 0` in `php.ini` to keep it inert
until a script asks for it with `php -d pcov.enabled=1`. An
`extension=pcov` line in `php.ini` alone would only have produced a startup
warning about a missing shared object. pcov is off by default because the
same ini feeds php-fpm, and HRVST-40 measured an always-on driver at about
8% on every uninstrumented run.

## `qualityGate` is what keeps the gate from editing itself

With `qualityGate.enable`, two hooks, both built by
`guards/quality-gate.nix` (builtins-only, so the tests evaluate it with
`nix-instantiate` and no nixpkgs): `block-quality-gate-edits` on
`^(Edit|Write|MultiEdit|NotebookEdit)$`, and
`block-quality-gate-shell-writes` on `^(Bash|Monitor)$`. Both read the same
lists, the `qualityGate.*` options: the protected files (by default
`phpstan.neon`, `pint.json`, `rector.php`, `phpunit.xml`,
`eslint.config.js`, `.prettierrc`, `.prettierignore`, `tsconfig.json`,
`vitest.config.ts`, `devenv.nix`, `devenv.yaml`, `devenv.lock`,
`devenv.local.nix`, `devenv.local.yaml`, `.envrc`, `.claude/settings.json`,
`.claude/settings.local.json`), the protected folders (`tests/Unit/Nix/`,
`.github/workflows/` and all of `nix/`), and `composer.json` and
`package.json`, each under its own narrower rule below. Joey edits these by
hand; an agent's Edit, Write, MultiEdit or NotebookEdit is refused
outright, and so is a Bash or Monitor command that writes one.

`devenv.lock` is on the list because it pins this module, and so every
guard the app runs: an agent that could move the lock could point the app
at guards of its own. The app's `nix/` holds only its own `agents.nix` and
`CONTEXT.md`, so with the gate on, no agent edits the app's own guards
either; a harness ticket needs Joey's hand for those files, and a rebase
conflict in a listed file is his to resolve too.

The edit-family hook reads `tool_input.file_path`, or
`tool_input.notebook_path` for NotebookEdit, refuses an empty or relative
path rather than trust it (the tools send absolute paths, and, the same
lesson as the dev-database reseed form, a hook cannot trust `.cwd` either,
since an earlier Bash `cd` persists where the hook cannot see it), expands a
leading `~` to `$HOME`, and resolves the result with GNU `realpath -m`
before matching case-insensitively on `/` boundaries. A folder entry
matches itself and everything below it, except a `CONTEXT.md` directly
inside it: `<root>/.github/workflows/CONTEXT.md` stays open,
`<root>/.github/workflows/ci.yml` does not. `realpath -m` closes a
symlinked alias (`ln -s nix g; Edit g/agents.nix` resolves to the real
`nix/agents.nix` before matching), but a hard link has no canonical path to
resolve to, so the hook separately refuses any Edit or Write to a regular
file whose link count is above 1, matched or not. That is an accepted
over-block, the same shape as `block-destructive-database`'s wide trigger,
with its own message naming the link count, so an agent refused on a file
that was never on the list knows why.

`.claude/settings.json` is itself a symlink into `/nix/store` (see the top
of this file), so matching only its resolved path would miss the one thing
that matters: the agent asked to write `.claude/settings.json` by that
name. The hook therefore resolves every path twice, once following
symlinks (`realpath -m`, used for the hard-link check and for reading a
`composer.json` or `package.json` branch's before-state) and once not
(`realpath -m -s`, which still collapses `..`, `.` and repeated slashes),
and gives each resolution its own class (`context-exempt`, `composer`,
`package`, `protected` or `none`, checked in that order). It refuses
outright when the two classes disagree, rather than refusing whenever either
one matches: a round 5 finding showed that an OR across both paths widens
what a symlink can reach, since a symlink whose given name classifies
leniently and whose resolved target classifies as `protected` took the
lenient path. The two paths disagreeing is itself the signal. For every
other listed file, which is never a symlink, the two resolutions are
identical, so they always classify the same. The two worked symlink shapes:
a parent directory aliased (`<root>/a/nix/CONTEXT.md` through a real
`<root>/a` whose `nix` is a symlink to the real `nix/`) classifies the given
path by its literal text (`context-exempt`) and the resolved path by what
it points at (`protected`), so it is refused; and the file itself being the
symlink (`<root>/.claude/settings.json` linked into the store) classifies
the given path as `protected` and the resolved path as `none`, also
refused. A `composer.json` reached through an unrelated symlinked parent
(`/tmp/x/composer.json` under macOS's `/tmp` → `/private/tmp` link) goes
through normally: both resolutions end in `/composer.json`, so both
classify `composer` and agree.

Classifying both resolutions is not enough on its own, a round 6 finding
showed: `realpath -m` resolves a symlink before it collapses a `..` in the
same path, so `<root>/l/../composer.json` with `l` a symlink to
`<root>/a/b` resolves to `<root>/a/composer.json`, while `realpath -m -s`
collapses `..` lexically and lands on the same suffix by a different route.
Neither names the file a write lands on if the tool itself normalises `..`
lexically first. The edit-family hook therefore refuses outright, with the
fallback message, the moment the raw, tilde-expanded path contains any
whole `.` or `..` segment (`/./`, `/../`, a trailing `/.` or `/..`, or a
path that is exactly `/.` or `/..`), before it resolves or classifies
anything. A segment that merely starts with a dot (`.envrc-notes.md`,
`.github`) is untouched.

`composer.json` gets a narrower rule than the rest of the list, because
adding a script is legitimate. The hook builds the after-state (`Write`'s
`content` verbatim; `Edit`'s `old_string` → `new_string` applied once, or
on every occurrence with `replace_all`, using jq's literal `/`
split-and-`join`, never a regex `sub`, so a string that is itself a regex
metacharacter cannot escape the match; `MultiEdit`'s `edits` applied in
order, each on the previous result). It refuses when `old_string` is absent
or, without `replace_all`, found more than once, and when either state,
each checked against itself, has a duplicate key anywhere. A leaf-path
comparison between the two states misses a duplicate key whose two values
have different shapes. The check instead counts the same text twice: once
as `jq --stream`'s leaf events (one per occurrence, duplicates included)
and once as `[paths(type != "object" and type != "array" or length == 0)] |
length` on the parsed value (jq's `fromjson`, like PHP's decoder, keeps
only the last occurrence of a duplicate key). The counts agree exactly when
nothing was shadowed: `{"s":{"a":["x"]},"s":{"a":["y"]}}` counts 2 against
1, while `{"s":{"a":["x"],"e":[],"o":{}}}` counts 3 against 3. A text match
for the floor would have read the first occurrence of a duplicate key where
jq and PHP read the last, which is the reason this check exists at all.

The hook refuses when a `composerFlooredScripts` script (`test:types`,
`test:coverage`, `test:mutation` by default) is removed, renamed, or
differs from its current text in anything but its one `--min=N` raised to a
bare integer `M > N`. That is checked by literal substitution, not by
comparing the two numbers alone: `pest --type-coverage --min=100` to `pest
--type-coverage --min=100 || true` keeps the number and still refuses,
because substituting the new floor back into the old text no longer
reproduces the new text.

A second, shared check (`lockedPathsUnchangedJq`, reused for
`package.json`) locks `composerLockedScripts` flat, with no floor
exception, and `composerLockedTopLevelPaths`, which are absent in both apps
today and so locked absent. It compares each named path with jq's own
`getpath` and `==`, a structural comparison, so reformatting the same value
does not trip it. A locked script cannot be removed or renamed either:
renaming it makes its own name's value disappear, which `getpath` reads as
`null` where the before-state had one. Every other `composer.json` edit,
including a script on neither list, goes through unexamined.

A third check on the after-state closes a route around every locked name: a
top-level `scripts-aliases` entry whose value lists a locked name
(`composer run a-shadow` would then run `ci:check` under a name neither
rule inspects), and a `scripts` key that matches a locked name
case-insensitively without matching it exactly (`"Test:Types"` beside or
in place of `"test:types"`). Both are refused outright; `"scripts-aliases":
{"fmt": ["format"]}` is fine because `format` is not a locked name.

`package.json` gets the same flat lock: `packageLockedScripts`, and
`packageLockedTopLevelPaths` (`prettier`, absent today and locked absent,
because Prettier reads a `prettier` key in `package.json` before
`.prettierrc`, so adding one would redefine what counts as formatted
without touching the file the guard already protects). `build:ssr`, which
`test:browser` runs, is locked too (Joey, HRVST-219). pnpm and npm run a
`pre<name>` script before and a `post<name>` script after `<name>`, a plain
string concatenation, and `ignore-scripts=true` does not stop that
(security's probe showed it still runs), so an added `pretest:unit` or
`postbuild` would run arbitrary code around a locked body. The lock keeps
`pre<name>` and `post<name>` absent for every locked package script.
Composer's event scripts (`pre-install-cmd`, `post-update-cmd` and the rest)
are out of scope: they already exist in `composer.json` and are edited in
ordinary dependency work. `npm`, `pnpm` and `yarn` can rewrite
`package.json` without naming the file (`npm pkg set scripts.build=true`,
`pnpm pkg delete scripts.test:unit`, `npm set-script build true`), so the
shell-family hook also fires on `pnpm`, `npm` or `yarn` followed, after any
options, by `pkg` or (for npm) `set-script`. Over-triggering on `pnpm pkg
get name` is accepted. `package.json` has no alias mechanism like
`scripts-aliases`, so the case-shadow check does not extend to it.

The shell-family hook triggers, case-insensitively and word-bounded, on the
basename of a protected file or folder (so a bare `nix` and any `*.nix`
path, since `.` is a boundary and `-` is not, so `nix-instantiate` does
not), `composer.json` or `package.json`; on `composerConfigPattern`,
security's own probe against Composer 2.10.3: `composer`, optionally
`.phar`-suffixed or run through `vendor/bin/composer` or a `COMPOSER=`
prefix, followed by any global options and then a subcommand that is a
prefix of `config` from `c`, of `repository` from `rep`, or of `policy`
from `po`, quoted or not, in any case; or when the command contains `git`
and, anywhere else on the line, `apply` or `am`, each as its own word. That
last trigger used to look for the contiguous phrase `git apply` and then
search the line for `--cached` or `--check` before giving up on blocking;
`git -C . apply x.diff` and `git apply --check --apply x.diff` both got
past that shape, so nothing exempts a line at the trigger stage now, and
the only way through is matching the allow-list below exactly. `require`,
`remove` and `bump` are not prefixes of `config`, `repository` or `policy`,
so they stay on the allow side.

A fifth trigger covers git's own dashed binaries, `git-apply` and `git-am`,
which run the same code as `git apply` and `git am` without spelling either
word separately. There is no allowed form for them. A copy renamed to an
unrelated name (`cp $(git --exec-path)/git-apply /tmp/ga`, then `/tmp/ga
x.diff`) is not caught and belongs in the known gaps below.

Both hook commands start with `export LC_ALL=C`, because APFS folds some
non-ASCII letters to the ASCII letter they look like (the Kelvin sign
U+212A to `k`, the long s U+017F to `s`), and neither `realpath -m` nor
`grep -i` do that under any locale. The edit-family hook refuses outright
when the resolved path contains any byte outside printable ASCII (`[^ -~]`,
a stable byte range only once the locale is pinned); the shell-family hook
treats the same byte class as a trigger on the raw command, and since
`plainToken` already excludes anything non-ASCII, nothing in the allow-list
can match such a line. Both checks run before the path or word patterns, so
no pattern needs to know about the fold.

Every `grep` in both hook commands reads its input as a here-string (`grep
… <<<"$cmd"`), never through a pipe, and the next editor must keep it that
way. Under `set -o pipefail`, `printf '%s' "$cmd" | grep -q …` can lose its
own match: `grep -q` exits on its first match while `printf` is still
writing a long second line, `printf` dies of `SIGPIPE`, and `pipefail`
reports that exit code rather than `grep`'s success. A `sed` on a listed
file followed by a long enough comment line went through clean this way,
reproduced end to end. A here-string has no separate writer for `grep` to
kill. The shell-family hook also refuses outright, before any other check,
a command over 64 KiB.

Once triggered, everything is blocked except a short list of
metacharacter-free single-line forms, each argument a `plainToken` (a bare
path token, or a single-quoted one whose first character is not a dash,
since a quoted `'--output=phpstan.neon'` writes as well as an unquoted
one): `cat`, `jq` and `git add` with one or more `plainToken`s; `git diff`
and `git log`, optionally with a bare `--` and otherwise no options; the
whole line `git apply --cached` or `--check` followed by one or more
`plainToken`s; `rg`, exactly as `guards/search.nix` defines it; and
`vendor/bin/pest( --compact)? tests/Unit/Nix(/[A-Za-z]+Test\.php)?(
--filter=…)?`, so a builder can run the app's own guard tests. `head`,
`tail`, `wc`, `git show`, `git blame` and any git read with a flag beyond a
bare `--` are left for a follow-up with its own adversarial round.

Accepted over-blocks: `composer config --list`; `echo workflows`; a commit
message or `ls` that merely mentions a protected name; a standalone `con`,
`rep` or `po` token later on a line that already ran `composer`; any line
that names `git` and the word `apply` or `am` without being the allowed
form (`git commit -m 'apply the fix'`); a `nix` command line; any
write-shaped command naming a `*.nix` file (a read through the allow-list,
`cat devenv.nix`, still goes through); any shell line carrying a byte
outside printable ASCII (a commit `-m` with an em dash); any shell line
naming `settings.json` or `settings.local.json`, `.vscode/settings.json`
included, or `devenv.lock`, outside the allow-list; a read-only `pnpm pkg`
subcommand; and an edit whose path carries a `.` or `..` segment that nets
out to an unprotected path, or to the same file the plain form would
already refuse (`<root>/nix/../nix/CONTEXT.md`). A commit message or PR
body that would trip any of these goes through a file written by the Write
tool (`git commit -F <file>`), the same workaround `block-teardown` relies
on. That reaches every commit whose subject names `nix` by convention
(`[HRVST-219] feat(nix): …`) and every commit carrying a non-ASCII
character.

Known gaps, as a named class: programs that write a protected file without
naming it. A shell glob that would expand to a protected name (`sed -i ''
s/x/y/ *.neon`) is invisible to a hook that only sees the unexpanded text;
so is a name assembled at run time (`composer co""nf …`, or `a=n; b=ix; sed
-i '' s/x/y/ agents.$a$b`), or `git-apply` copied to an unrelated name
before it runs. git's own worktree writes after an index-only `git apply
--cached` (`checkout-index`, `stash apply`, `reset --hard`, `merge`) never
name the file either; neither does `cp -r` or `rsync` from a folder named
only by its parent; a PHP script calling Composer's `JsonManipulator`
directly never runs the `composer` binary; a rewritten gitignored
`vendor/bin/phpstan`, `pint`, `rector` or `pest` passes the locked gate
bodies without touching a listed file; and an MCP tool outside the edit
matcher that can write files (`mcp__playwright__browser_run_code_unsafe`,
unverified) is invisible to a hook that watches only `Edit`, `Write`,
`MultiEdit`, `NotebookEdit`, `Bash` and `Monitor`. The listed follow-up, a
state check that refuses when a protected file differs from `main`, at the
builder's `TaskCompleted` and in `ci`, is what closes this class; a
name-based hook, however wide its trigger, cannot. Separately: an inline
suppression (`@phpstan-ignore`, `eslint-disable`, Pest's `->skip()`) or a
nested agent (`claude --bare -p`, `herdr pane run`) bypasses the hook
entirely. `.npmrc` and `pnpm-workspace.yaml` are not on the list, since
dependency work edits them; a `script-shell` or `scriptShell` setting in
either can still make the locked `pnpm` scripts exit 0, and HRVST-222 locks
those two settings by key (Joey, HRVST-219).

The guard protects an app's files, not this repository. An agent working in
this checkout meets no quality gate here, so a change to a guard reaches an
app only through a `devenv.lock` that Joey commits, or through an app shell
built against this checkout with `--override-input` or a `path:` input,
which the lock no longer pins.

The module's test suite evaluates `guards/quality-gate.nix` directly with
`nix-instantiate --eval --strict --json`, the way it tests every guard,
rather than by reading a generated `.claude/settings.json`, which is only
rewritten on shell entry.
