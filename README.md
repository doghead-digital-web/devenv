# doghead devenv

The development environment and agent guards shared by Doghead's Laravel and
Inertia React client apps (JSHQ and Harvst today). An app imports the
`laravel` folder as a [devenv](https://devenv.sh) module and sets a handful
of options; everything else comes from here:

- PHP 8.5 with its extensions and a php-fpm pool, Node 24 with pnpm
- a Caddy site, a MySQL dev and test database, a Redis index and a RustFS
  bucket per git worktree, and the shell tasks that create them
- the `devenv up` processes: Vite, the queue, the scheduler and pail
- the git hooks, run through prek, and the `ci-*` scripts CI runs
- Claude Code's MCP servers and the guard hooks every app shares
- `feature` and `teardown`, which open and remove a worktree

It holds no application code and nothing in it is deployed. CI uses it only
through `devenv shell ci-*`. A guard for one client's external systems (the
Caltrans portal, METRC) stays in that app's `nix/agents.nix`.

[CONTEXT.md](CONTEXT.md) explains why the guards and scripts are shaped the
way they are. Read it before changing any of them.

## Layout

| Path | What it does |
| --- | --- |
| `laravel/devenv.nix` | the `doghead.laravel` options; imports the rest |
| `laravel/workspace.nix` | names, ports, hosts and paths for the current worktree, at `config.doghead.laravel.workspace` |
| `laravel/database-names.nix`, `laravel/reserved-databases.nix` | the database naming rules, builtins only |
| `laravel/languages.nix`, `laravel/php.ini` | PHP, its extensions and ini, Node and pnpm |
| `laravel/services.nix` | environment variables, processes, shell tasks, the shell banner |
| `laravel/git-hooks.nix` | the git hooks and the shim that runs them from every worktree |
| `laravel/ci.nix` | the `ci-*` scripts and `ci` |
| `laravel/agents.nix` | Claude Code's MCP servers and shared hooks |
| `laravel/guards/` | each hook's command, as a pure file the tests evaluate directly |
| `laravel/worktrees.nix`, `laravel/scripts/` | `feature` and `teardown` |
| `tests/` | the module's own Pest suite |

## Adopting it in an app

`devenv.yaml`:

```yaml
inputs:
  git-hooks:
    url: github:cachix/git-hooks.nix
    inputs:
      nixpkgs:
        follows: nixpkgs
  nixpkgs:
    url: github:cachix/devenv-nixpkgs/rolling
  doghead:
    url: github:doghead-digital-web/devenv
    flake: false
imports:
  - doghead/laravel
```

devenv ignores the inputs an imported folder declares, so the app lists
`nixpkgs` and `git-hooks` itself.

`devenv.nix`:

```nix
{ ... }:

{
  imports = [ ./nix/agents.nix ];

  doghead.laravel = {
    appName = "acme";
    commitExample = "[ACME-12] feat(scope): what changed";
  };
}
```

`nix/agents.nix` holds the app's own hooks and permissions, under
`claude.code`; leave the import out until the app has one. `.envrc` is
`use devenv`.

The module calls these by name, so the app must define them:

- composer scripts: `fmt:check`, `refactor:check`, `lint`, `lint:arch`,
  `test:types`, `test:browser`, `test:coverage`, `test:mutation`, and each
  script in `builderGate` (`ci:check` by default)
- pnpm scripts: `build` and `types:check`, plus `check` with
  `formatter = "vite-plus"`, or `lint:check` and `fmt:check` with
  `formatter = "prettier"`
- `vendor/bin/pint`, `vendor/bin/rector` and `vendor/bin/pest`
- a `.env.example`, which the shell copies to `.env` once, and a `users`
  table, which decides whether a new worktree gets seeded

## Options

All under `doghead.laravel`.

| Option | Default | What it sets |
| --- | --- | --- |
| `appName` | required | lowercase letters and digits; names the databases (`<appName>_<worktree>`), hosts (`<worktree>.<appName>.test`), Caddy sites, RustFS bucket, shell tasks (`<appName>:deps` …) and the herdr workspace `feature` and `teardown` use |
| `commitExample` | required | the ticketed subject the commit-msg hook prints when it refuses one |
| `formatter` | `"vite-plus"` | `vite-plus` or `prettier`: the JS git hook and what `ci-lint` runs |
| `ciPrepare` | `""` | shell lines every `ci-*` job runs first, such as `composer types:generate` |
| `ciJobs` | `ci-lint`, `ci-hooks`, `ci-test`, `ci-browser`, `ci-coverage` | what `ci` runs, in order; an app script such as `ci-vitest` can go in the list |
| `builderGate` | `[ "ci:check" ]` | composer scripts the `builder-ci-gate` hook runs before a `product-builder` task may close |
| `mcpServers` | `{}` | MCP servers added to the module's own (shadcn, magicuidesign, laravel-boost, playwright, chrome, linear), as `{ command; args; }` |
| `yolo.enable` | `false` | adds `claude-yolo`, which starts Claude Code with every hook and permission prompt off for that session |
| `qualityGate.enable` | `false` | turns on the two hooks that keep agents from editing a quality gate's configuration |
| `qualityGate.files` | `phpstan.neon`, `pint.json`, `rector.php`, `phpunit.xml`, `eslint.config.js`, `.prettierrc`, `.prettierignore`, `tsconfig.json`, `vitest.config.ts`, `devenv.nix`, `devenv.yaml`, `devenv.lock`, `devenv.local.nix`, `devenv.local.yaml`, `.envrc`, `.claude/settings.json`, `.claude/settings.local.json` | files the guard protects |
| `qualityGate.dirs` | `tests/unit/nix`, `.github/workflows`, `nix` | folders the guard protects, each but its own `CONTEXT.md` (matched case-insensitively) |
| `qualityGate.composerFlooredScripts` | `test:types`, `test:coverage`, `test:mutation` | composer scripts whose `--min=N` may only rise |
| `qualityGate.composerLockedScripts` | `ci:check`, `test`, `lint`, `lint:arch`, `fmt:check`, `refactor:check`, `test:browser`, `types:generate` | composer scripts locked as they are |
| `qualityGate.composerLockedTopLevelPaths` | `config.bin-dir`, `repositories`, `extra` | `composer.json` paths locked as they are |
| `qualityGate.packageLockedScripts` | `build`, `build:ssr`, `fmt:check`, `lint:check`, `test:unit`, `types:check` | `package.json` scripts locked, with their `pre` and `post` scripts |
| `qualityGate.packageLockedTopLevelPaths` | `prettier` | `package.json` paths locked as they are |

Setting a list option replaces its default. To add to one, start from it:
`qualityGate.files = options.doghead.laravel.qualityGate.files.default ++ [ "x" ];`
with `options` taken from the module arguments.

`config.doghead.laravel.workspace` is read-only: `worktreeName`,
`worktreeRoot`, `phpPort`, `vitePort`, `redisDbIndex`, `dbName`,
`testDbName`, `backendHost`, `viteHost`, `rustfsBucket` and the rest. An app
task orders itself against the module's by name, as Harvst's `harvst:types`
does with `after = [ "harvst:env" ]`. A script with a `description` shows in
the shell banner.

`php.local.ini` at the app's root, when present, is appended to the module's
`laravel/php.ini`.

## A new client app

1. Pick `appName`, and create a herdr workspace labelled with it.
2. Copy the `devenv.yaml` and `devenv.nix` above, add `.envrc`, and
   gitignore `.devenv*`, `.direnv`, `devenv.local.nix`, `devenv.local.yaml`,
   `.pre-commit-config.yaml`, `.mcp.json` and `.claude/settings.json`,
   which the shell generates.
3. Define the composer and pnpm scripts listed above.
4. Set Vite's dev server from `VITE_PORT` and `VITE_DEV_HOST`, with
   `strictPort: true`.
5. Point the suite at `DB_TEST_DATABASE`: copy `tests/bootstrap.php` and the
   forced `DB_CONNECTION` and `APP_CONFIG_CACHE` in `phpunit.xml` from JSHQ.
6. Copy the destructive-command guard from JSHQ's `app/Providers`, with the
   app's own reserved-database pattern written out.
7. Write the app's facts in `.ai/rules/agent-harness.md`, and its own guards
   in `nix/agents.nix`.
8. In CI, run each job as `devenv shell <job>` with `CI` set and a MySQL
   service providing `<appName>_test`.
9. Enter the shell once (`direnv allow`), then commit `devenv.lock`.

## Updating the module in an app

```sh
devenv update doghead
direnv reload
```

`devenv update doghead` moves only this input in `devenv.lock`; a bare
`devenv update` moves nixpkgs and git-hooks too. Commit the lock. Claude
Code reads hooks when a session starts, so restart every running agent
session once the shell has reloaded. In an app with `qualityGate.enable`,
`devenv.lock` is protected, so Joey makes the bump by hand.

`teardown` refuses a real run while `main`'s `devenv.lock` differs from the
one its shell was built from, so reload the main checkout's shell after a
bump merges.

## Developing the module against an app

From the app's checkout:

```sh
devenv shell --override-input doghead path:/Users/jmckenzie/github.com/doghead-digital-web/devenv/main
```

That shell builds from the working tree here, edits included. direnv's shell
keeps the locked input; to have it follow the checkout instead, point the
input's `url` at the same `path:` for a while, and put it back to
`github:doghead-digital-web/devenv` before committing. A hook change reaches
an agent only in a session started after the shell rebuilt.

## Tests

Every guard and script is a pure Nix file, so the suite evaluates or builds
each one directly with `nix-instantiate`, without an app's shell. From this
folder:

```sh
direnv allow
composer install
composer test
```

`composer test` runs the Pest suite and
`laravel/guards/ask-before-publishing.test.sh`. Given an app's generated
`.claude/settings.json` as its argument, that script also checks the app's
shell runs a current `/nix/store` copy of the guard. The suite needs Nix on
`PATH`; this folder's own `devenv.nix` provides PHP and the tools the guards
call.
