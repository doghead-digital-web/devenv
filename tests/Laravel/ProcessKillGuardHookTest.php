<?php

declare(strict_types=1);

namespace Tests\Laravel;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Assert;

/**
 * @return array{matcher: string, command: string}
 */
function killGuardHook(): array
{
    /** @var array{matcher: string, command: string}|null $hook */
    static $hook = null;

    if ($hook !== null) {
        return $hook;
    }

    $result = Process::path(module_path())->run([
        'nix-instantiate', '--eval', '--strict', '--json', 'laravel/guards/process-kill.nix',
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
function runKillGuard(string $command, array $payload = []): ProcessResult
{
    return Process::input((string) json_encode([...$payload, 'tool_input' => ['command' => $command]]))
        ->run(['bash', '-c', killGuardHook()['command']]);
}

test('the hook watches the Monitor tool as well as Bash', function (string $tool): void {
    $result = runKillGuard('pkill vite', ['tool_name' => $tool]);

    expect($tool)->toMatch('/'.killGuardHook()['matcher'].'/')
        ->and($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toContain('kill <pid>')
        ->and($result->errorOutput())->toContain('lsof')
        ->and($result->errorOutput())->toContain('TaskStop');
})->with(['Bash', 'Monitor']);

test('the hook matches exactly the Bash and Monitor tools', function (): void {
    expect(killGuardHook()['matcher'])->toBe('^(Bash|Monitor)$');
});

/**
 * @return array<string, string>
 */
function killWordForms(): array
{
    return [
        'a named target rather than a pid' => '%w hrvst-151-feedback-ownership',
        'through env' => 'env %w x',
        'through command' => 'command %w x',
        'with an emptied variable' => 'CLAUDECODE= %w x',
        'by store path' => '/nix/store/abc-%w/bin/%w x',
        'through bash -c' => "bash -c '%w x'",
        'with input piped in' => 'echo y | %w x',
        'through sh -c in double quotes' => 'sh -c "%w x"',
        'through bash -lc' => "bash -lc '%w x'",
        'after &&' => 'ls && %w x',
        'after ||' => 'false || %w x',
        'in a subshell' => '(%w x)',
        'in a command substitution' => 'echo $(%w x)',
        'in backticks' => 'echo `%w x`',
        'in a brace group' => '{ %w x; }',
        'after then' => 'if true; then %w x; fi',
        'through nohup' => 'nohup %w x',
        'through sudo' => 'sudo %w x',
        'through exec' => 'exec %w x',
        'through time' => 'time %w x',
        'through xargs with flags' => 'echo x | xargs -n 1 %w',
        'through env with an unset' => 'env -u CLAUDECODE %w x',
        'through env with an assignment' => 'env CLAUDECODE= %w x',
        'after an assignment' => 'FOO=bar %w x',
        'by relative path' => './bin/%w x',
        'by a quoted variable path' => '"$PROFILE"/bin/%w x',
        'through a wrapper given by path' => '/usr/bin/env %w x',
        'ending at a redirect' => '%w<x',
        'ending at an output redirect' => '%w>x',
        'ending at a field separator' => '%w$IFS x',
        'through setsid' => 'setsid %w x',
        'through flock' => 'flock /tmp/lock %w x',
        'through nix develop' => 'nix develop -c %w x',
        'typed into another pane' => "herdr pane send-text p1 '%w x'",
        'run under a pty' => "python3 -c 'import pty; pty.spawn([\"%w\", \"x\"])'",
        'an echo piped to a shell' => 'echo %w x | sh',
        'a fd exec' => 'fd -x %w',
        'a rg preprocessor' => 'rg --pre %w x',
        'a git grep pager' => 'git grep -O %w x',
        'a message flag given to a shell' => "sh -c -m '%w x'",
        'a heredoc with an unquoted delimiter' => "git commit -F - <<EOF\n$(%w x)\nEOF",
        'a heredoc with more after it' => "git commit -F - <<'EOF'\nfix\nEOF\n%w x",
        'a git grep pager attached' => 'git grep -O%w x',
        'a fd exec attached' => 'fd -x%w',
        'a fd batch exec attached' => 'fd -X%w',
        'a bundled git grep pager' => 'git grep -iO%w x',
        'a bundled fd exec' => 'fd -Hx%w',
        'a long exec with a value' => 'fd --exec=%w',
        'a long exec batch' => 'fd --exec-batch %w',
        'a long rg preprocessor with a value' => 'rg --pre=%w x',
        'a long git grep pager with a value' => 'git grep --open-files-in-pager=%w x',
        'a git config flag' => "git log -c alias.x='!%w'",
        'a command run after a stripped commit message' => "git commit -m '%w x'; eval \"$(git log -1 --format=%s)\"",
        'a title piped to a shell' => "gh pr create --title '%w x' && gh pr view --json title -q .title | sh",
        'a heredoc with a command after its first line' => "git commit -F - <<'EOF' && %w x\nfix\nEOF",
        'a search with a preprocessor value' => 'rg --pre=%w x',
        'a search with a quoted preprocessor' => "rg '--pre=%w' x",
        'a search through compressed files' => 'rg -z %w',
        'a search with a bundled compressed flag' => 'rg -uz %w',
        'a search with a long compressed flag' => 'rg --search-zip %w',
        'a search with a hostname program' => 'rg --hostname-bin=%w x',
        'a search with a substitution' => 'rg %w $(x)',
        'a search with a double-quoted pattern' => 'rg "%w"',
        'a search with an escaped space' => 'rg %w\ x',
        'a grep piped to a shell' => 'grep %w x | sh',
        'a search with a quote escape' => "rg 'a'\\''%w'",
        'a search with a double space' => 'rg  %w x',
        'a grep with a filter program' => 'grep --filter=*:%w x .',
    ];
}

test('every trigger word is blocked across the teardown-style obfuscation forms, upper and mixed case', function (string $command): void {
    expect(runKillGuard($command)->exitCode())->toBe(2);
})->with(function (): array {
    $words = ['kill', 'pkill', 'killall', 'fkill', 'fuser', 'kill-port'];
    $cases = [];

    foreach ($words as $word) {
        foreach (killWordForms() as $label => $template) {
            $cases["{$word}: {$label}"] = [str_replace('%w', $word, $template)];
        }

        $cases["{$word}: upper case"] = [mb_strtoupper($word).' x'];
        $cases["{$word}: mixed case"] = [ucfirst($word).' x'];
    }

    return $cases;
});

test('the exempt literal-pid form only ever belongs to kill, single line, with no direnv wrapper', function (string $command): void {
    expect(runKillGuard($command)->exitCode())->toBe(2);
})->with([
    'chained after a pid kill' => ['kill 12345; kill 12346'],
    'a pid kill with more after it' => ['kill 12345 && rm -rf ../x'],
    'a pid kill on a second line' => ["echo ok\nkill 12345"],
    'a pid kill ending in a carriage return' => ["kill 12345\r"],
    'a path as the target' => ['kill ../x'],
    'the exempt form spelled in upper case' => ['KILL 12345'],
    'a real run through direnv' => ['direnv exec . kill 12345'],
]);

test('the hook blocks every refused worked example from R1 and R2', function (string $command): void {
    expect(runKillGuard($command)->exitCode())->toBe(2);
})->with([
    'a name lookup fed to kill' => ['kill $(pgrep -f vite)'],
    'a name lookup piped to xargs' => ['pgrep -f php | xargs kill'],
    'a pid list substitution' => ['kill -9 $(lsof -ti :5173)'],
    'npx kill-port' => ['npx kill-port 5173'],
    'fuser by port' => ['fuser -k 3306/tcp'],
    'pkill in upper case' => ['PKILL vite'],
    'a negative signal target' => ['kill -9 -1'],
    'pid zero' => ['kill 0'],
    'a double-dash separator' => ['kill -- -4242'],
    'the last background pid' => ['kill $!'],
    'a job spec' => ['kill %1'],
    'timeout sending KILL by name' => ['timeout -s KILL 5 x'],
    'two kills on separate lines' => ["kill 123\nkill \$(pgrep php)"],
    'a search chained to a real kill' => ['rg pkill; pkill x'],
]);

test('the hook allows a literal pid kill, a plain search, and commands that name no trigger word', function (string $command): void {
    expect(runKillGuard($command)->exitCode())->toBe(0);
})->with([
    'a single pid' => ['kill 12345'],
    'a signal flag and a pid' => ['kill -9 12345'],
    'two pids' => ['kill -9 12345 12346'],
    'a named signal' => ['kill -s TERM 12345'],
    'a commit from a file' => ['git commit -F /tmp/msg'],
    'a search for pkill' => ['rg pkill'],
    'a search for killall' => ['rg killall'],
    'a search with a flag' => ['rg -n kill'],
    'a branch named after the ticket' => ['git log hrvst-212-pkill-guard'],
    'a name lookup command on its own' => ['pgrep -fl "devenv up"'],
    'a read of open files by pid' => ['lsof -p 12345'],
    'a path' => ['ls ~/.claude/skills'],
    'a multi-line command that names nothing' => ["echo ok\nrg x"],
    "the facts' overlap check" => ['pgrep -f \'pest|artisan test\' | xargs -I{} lsof -a -d cwd -p {} -Fn 2>/dev/null | rg -x "n$PWD"'],
]);

test('the hook blocks a mention outside the search form, which goes through a file instead', function (string $command): void {
    expect(runKillGuard($command)->exitCode())->toBe(2);
})->with([
    'a commit message' => ['git commit -m "fix kill refusing restacked layers"'],
    'a pull request body' => ['gh pr edit 1 --body "run kill x after merge"'],
    'an echo' => ["echo 'kill hrvst-1'"],
    'a heredoc commit message' => ["git commit -F - <<'EOF'\nfix: stop kill refusing merged layers\nEOF"],
    'a heredoc pull request body' => ["gh pr create --title 'Fix it' --body-file - <<'EOF'\nrun kill x after merge\nEOF"],
    'a message flag with a separator inside it' => ["git commit -m 'fix; kill refusing'"],
    'a title' => ['gh pr edit 1 --title "Fix kill"'],
    'a git log search' => ['git log --grep kill'],
    'a cat of a file named for it' => ['cat kill'],
    'a recursive grep' => ['grep -rn kill nix'],
]);

test('the hook leaves the gaps it cannot see through to the in-script checks', function (string $command): void {
    expect(runKillGuard($command)->exitCode())->toBe(0);
})->with([
    'a name built in a variable' => ['k=ki; ${k}ll 1'],
    'a name decoded at run time' => ['echo a2lsbCAx | base64 -d | sh'],
    'a name split inside python' => ["python3 -c 'import pty; pty.spawn([\"ki\" + \"ll\", \"1\"])'"],
]);

/**
 * @param  list<string>  $buckets
 * @return list<string>
 */
function killProbesFrom(array $buckets): array
{
    /** @var array<string, list<string>> $probes */
    $probes = json_decode((string) file_get_contents(module_path('tests/fixtures/worktrees/guard-probes.json')), true);

    return array_values(collect($buckets)
        ->flatMap(fn (string $bucket): array => $probes[$bucket] ?? [])
        ->map(fn (string $command): string => str_replace(['TEARDOWN', 'Teardown', 'teardown'], ['KILL', 'Kill', 'kill'], $command))
        ->all());
}

test('every teardown guard-probe form that blocks or over-blocks still blocks once kill replaces the word', function (string $command): void {
    expect(runKillGuard($command)->exitCode())->toBe(2);
})->with(fn (): array => killProbesFrom(['block', 'over_block']));
