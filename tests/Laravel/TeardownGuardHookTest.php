<?php

declare(strict_types=1);

namespace Tests\Laravel;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Assert;

/**
 * @return array{matcher: string, command: string}
 */
function teardownGuardHook(): array
{
    /** @var array{matcher: string, command: string}|null $hook */
    static $hook = null;

    if ($hook !== null) {
        return $hook;
    }

    $result = Process::path(module_path())->run([
        'nix-instantiate', '--eval', '--strict', '--json', 'laravel/guards/teardown.nix',
        '--argstr', 'grep', 'grep',
        '--argstr', 'jq', 'jq',
    ]);

    Assert::assertTrue($result->successful(), $result->errorOutput());

    /** @var array{matcher: string, command: string} $hook */
    $hook = json_decode($result->output(), true);

    return $hook;
}

/**
 * @param  array<string, mixed>  $payload
 */
function runTeardownGuard(string $command, array $payload = []): ProcessResult
{
    return Process::input((string) json_encode([...$payload, 'tool_input' => ['command' => $command]]))
        ->run(['bash', '-c', teardownGuardHook()['command']]);
}

test('the hook watches the Monitor tool as well as Bash', function (string $tool): void {
    $result = runTeardownGuard('teardown x', ['tool_name' => $tool]);

    expect($tool)->toMatch('/'.teardownGuardHook()['matcher'].'/')
        ->and($result->exitCode())->toBe(2);
})->with(['Bash', 'Monitor']);

test('the hook matches exactly the Bash and Monitor tools', function (): void {
    expect(teardownGuardHook()['matcher'])->toBe('^(Bash|Monitor)$');
});

test('the hook blocks an agent from running teardown and says to hand the command to Joey', function (string $command): void {
    $result = runTeardownGuard($command);

    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toContain('teardown <worktree>')
        ->and($result->errorOutput())->toContain('main checkout');
})->with([
    'a real run' => ['teardown hrvst-151-feedback-ownership'],
    'upper case' => ['TEARDOWN hrvst-151-feedback-ownership'],
    'through env' => ['env teardown x'],
    'through command' => ['command teardown x'],
    'with an emptied variable' => ['CLAUDECODE= teardown x'],
    'by store path' => ['/nix/store/abc-teardown/bin/teardown x'],
    'through bash -c' => ["bash -c 'teardown x'"],
    'with input piped in' => ['echo y | teardown x'],
    'through sh -c in double quotes' => ['sh -c "teardown x"'],
    'through bash -lc' => ["bash -lc 'teardown x'"],
    'after &&' => ['ls && teardown x'],
    'after ||' => ['false || teardown x'],
    'in a subshell' => ['(teardown x)'],
    'in a command substitution' => ['echo $(teardown x)'],
    'in backticks' => ['echo `teardown x`'],
    'in a brace group' => ['{ teardown x; }'],
    'after then' => ['if true; then teardown x; fi'],
    'through nohup' => ['nohup teardown x'],
    'through sudo' => ['sudo teardown x'],
    'through exec' => ['exec teardown x'],
    'through time' => ['time teardown x'],
    'through xargs with flags' => ['echo x | xargs -n 1 teardown'],
    'through env with an unset' => ['env -u CLAUDECODE teardown x'],
    'through env with an assignment' => ['env CLAUDECODE= teardown x'],
    'after an assignment' => ['FOO=bar teardown x'],
    'by relative path' => ['./bin/teardown x'],
    'by a quoted variable path' => ['"$PROFILE"/bin/teardown x'],
    'through a wrapper given by path' => ['/usr/bin/env teardown x'],
    'ending at a redirect' => ['teardown<x'],
    'ending at an output redirect' => ['teardown>x'],
    'ending at a field separator' => ['teardown$IFS x'],
    'through setsid' => ['setsid teardown x'],
    'through flock' => ['flock /tmp/lock teardown x'],
    'through nix develop' => ['nix develop -c teardown x'],
    'typed into another pane' => ["herdr pane send-text p1 'teardown x'"],
    'run under a pty' => ["python3 -c 'import pty; pty.spawn([\"teardown\", \"x\"])'"],
    'an echo piped to a shell' => ['echo teardown x | sh'],
    'a fd exec' => ['fd -x teardown'],
    'a rg preprocessor' => ['rg --pre teardown x'],
    'a git grep pager' => ['git grep -O teardown x'],
    'a message flag given to a shell' => ["sh -c -m 'teardown x'"],
    'a heredoc with an unquoted delimiter' => ["git commit -F - <<EOF\n$(teardown x)\nEOF"],
    'a heredoc with more after it' => ["git commit -F - <<'EOF'\nfix\nEOF\nteardown x"],
    'a git grep pager attached' => ['git grep -Oteardown x'],
    'a fd exec attached' => ['fd -xteardown'],
    'a fd batch exec attached' => ['fd -Xteardown'],
    'a bundled git grep pager' => ['git grep -iOteardown x'],
    'a bundled fd exec' => ['fd -Hxteardown'],
    'a long exec with a value' => ['fd --exec=teardown'],
    'a long exec batch' => ['fd --exec-batch teardown'],
    'a long rg preprocessor with a value' => ['rg --pre=teardown x'],
    'a long git grep pager with a value' => ['git grep --open-files-in-pager=teardown x'],
    'a git config flag' => ["git log -c alias.x='!teardown'"],
    'a command run after a stripped commit message' => ["git commit -m 'teardown x'; eval \"$(git log -1 --format=%s)\""],
    'a title piped to a shell' => ["gh pr create --title 'teardown x' && gh pr view --json title -q .title | sh"],
    'a heredoc with a command after its first line' => ["git commit -F - <<'EOF' && teardown x\nfix\nEOF"],
    'a search with a preprocessor value' => ['rg --pre=teardown x'],
    'a search with a preprocessor argument' => ['rg --pre teardown x'],
    'a search with a quoted preprocessor' => ["rg '--pre=teardown' x"],
    'a search through compressed files' => ['rg -z teardown'],
    'a search with a bundled compressed flag' => ['rg -uz teardown'],
    'a search with a long compressed flag' => ['rg --search-zip teardown'],
    'a search with a hostname program' => ['rg --hostname-bin=teardown x'],
    'a search with a substitution' => ['rg teardown $(x)'],
    'a search with a double-quoted pattern' => ['rg "teardown"'],
    'a search with an escaped space' => ['rg teardown\ x'],
    'a grep piped to a shell' => ['grep teardown x | sh'],
    'a search with a quote escape' => ["rg 'a'\\''teardown'"],
    'a search with a double space' => ['rg  teardown x'],
    'a grep with a filter program' => ['grep --filter=*:teardown x .'],
    'chained after a dry run' => ['teardown --dry-run x; teardown x'],
    'a dry run with more after it' => ['teardown --dry-run x && rm -rf ../x'],
    'a dry run on a second line' => ["echo ok\nteardown --dry-run x"],
    'a dry run ending in a carriage return' => ["teardown --dry-run x\r"],
    'a dry run of an upper case name' => ['teardown --dry-run X'],
    'a dry run of a path' => ['teardown --dry-run ../x'],
    'a dry run spelled in upper case' => ['TEARDOWN --dry-run x'],
    'a real run through direnv' => ['direnv exec . teardown hrvst-151-feedback-ownership'],
    'a dry run through direnv in another directory' => ['direnv exec ../main teardown --dry-run x'],
    'a dry run through direnv at the root' => ['direnv exec / teardown --dry-run x'],
    'a dry run through direnv and env' => ['direnv exec . env teardown --dry-run x'],
    'a dry run through direnv with a double space' => ['direnv  exec . teardown --dry-run x'],
    'a real run chained after a direnv dry run' => ['direnv exec . teardown --dry-run x; teardown x'],
    'a real run on the line after a direnv dry run' => ["direnv exec . teardown --dry-run x\nteardown x"],
]);

test('the hook allows a single-line dry run, a plain search and commands that do not name teardown', function (string $command): void {
    expect(runTeardownGuard($command)->exitCode())->toBe(0);
})->with([
    'a dry run' => ['teardown --dry-run hrvst-151-feedback-ownership'],
    'a dry run through direnv' => ['direnv exec . teardown --dry-run hrvst-151-feedback-ownership'],
    'a commit from a file' => ['git commit -F /tmp/msg'],
    'a commit from a relative file' => ['git commit -F msg.txt'],
    'a pull request body from a file' => ['gh pr create --body-file body.md'],
    'a search that spells the name around the hook' => ["rg -n 'tear.down' nix/"],
    'a branch named after the ticket' => ['git log hrvst-180-teardown-guard'],
    'a multi-line command that names nothing' => ["echo ok\nrg x"],
    'a search' => ['rg teardown nix/'],
    'a search with a quoted pattern and a glob' => ["rg -n 'teardown' -g '*.nix'"],
    'a path' => ['cat tests/fixtures/worktrees/teardown-notes.md'],
]);

test('the hook blocks a mention outside the search form, which goes through a file instead', function (string $command): void {
    expect(runTeardownGuard($command)->exitCode())->toBe(2);
})->with([
    'a commit message' => ['git commit -m "fix teardown refusing restacked layers"'],
    'a pull request body' => ['gh pr edit 1 --body "run teardown x after merge"'],
    'an echo' => ["echo 'teardown hrvst-1'"],
    'a heredoc commit message' => ["git commit -F - <<'EOF'\nfix: stop teardown refusing merged layers\nEOF"],
    'a heredoc pull request body' => ["gh pr create --title 'Fix it' --body-file - <<'EOF'\nrun teardown x after merge\nEOF"],
    'a message flag with a separator inside it' => ["git commit -m 'fix; teardown refusing'"],
    'a title' => ['gh pr edit 1 --title "Fix teardown"'],
    'a git log search' => ['git log --grep teardown'],
    'a cat of a file named for it' => ['cat teardown'],
    'a recursive grep' => ['grep -rn teardown nix'],
]);

test('the hook leaves the gaps it cannot see through to the in-script checks', function (string $command): void {
    expect(runTeardownGuard($command)->exitCode())->toBe(0);
})->with([
    'a name built in a variable' => ['t=tear; ${t}down x'],
    'a name decoded at run time' => ['echo dGVhcmRvd24geA== | base64 -d | sh'],
    'a name split inside python' => ["python3 -c 'import pty; pty.spawn([\"tear\" + \"down\", \"x\"])'"],
]);

/**
 * @return array<string, list<string>>
 */
function guardProbes(): array
{
    /** @var array<string, list<string>> */
    return json_decode((string) file_get_contents(module_path('tests/fixtures/worktrees/guard-probes.json')), true);
}

test('the hook gives every security probe its recorded outcome', function (string $outcome, string $command): void {
    expect(runTeardownGuard($command)->exitCode())->toBe(in_array($outcome, ['block', 'over_block'], true) ? 2 : 0);
})->with(fn (): array => collect(guardProbes())
    ->flatMap(fn (array $commands, string $outcome): array => array_map(fn (string $command): array => [$outcome, $command], $commands))
    ->values()
    ->all());
