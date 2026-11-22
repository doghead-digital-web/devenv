<?php

declare(strict_types=1);

namespace Tests\Laravel;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Assert;

/**
 * @return array<string, string|false>
 */
function isolatedFeatureGit(): array
{
    return [
        'GIT_CONFIG_GLOBAL' => '/dev/null',
        'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_AUTHOR_NAME' => 'Fixture',
        'GIT_AUTHOR_EMAIL' => 'fixture@example.com',
        'GIT_COMMITTER_NAME' => 'Fixture',
        'GIT_COMMITTER_EMAIL' => 'fixture@example.com',
    ];
}

/**
 * @param  list<string>  $arguments
 */
function fixtureFeatureGit(string $path, array $arguments): string
{
    return Process::path($path)->env(isolatedFeatureGit())->run(['git', ...$arguments])->throw()->output();
}

function writeFeatureStub(string $bin, string $name, string $body): void
{
    File::put($bin.'/'.$name, "#!/bin/sh\n".$body."\n");
    File::chmod($bin.'/'.$name, 0755);
}

function writeFeature(string $bin): void
{
    $result = Process::path(module_path())->run([
        'nix-instantiate', '--eval', '--read-write-mode', '--strict', '--json', 'laravel/scripts/feature.nix',
        '--argstr', 'jq', 'jq',
        '--argstr', 'grep', 'grep',
        '--argstr', 'gawk', 'gawk',
        '--argstr', 'appName', 'jshq',
    ]);

    Assert::assertTrue($result->successful(), $result->errorOutput());

    File::put($bin.'/feature', "#!/usr/bin/env bash\n".json_decode($result->output()));
    File::chmod($bin.'/feature', 0755);
}

function featureFixtureRoot(): string
{
    static $root = null;

    if (is_string($root)) {
        return $root;
    }

    $root = realpath(sys_get_temp_dir()).'/feature-'.bin2hex(random_bytes(8));
    register_shutdown_function(fn (): bool => File::deleteDirectory($root));

    File::ensureDirectoryExists($root.'/main');
    fixtureFeatureGit($root.'/main', ['init', '--quiet', '--initial-branch=main']);
    fixtureFeatureGit($root.'/main', ['commit', '--quiet', '--allow-empty', '--message=init']);
    fixtureFeatureGit($root.'/main', ['worktree', 'add', '--quiet', '-b', 'jsh-1-a', $root.'/jsh-1-a']);

    File::ensureDirectoryExists($root.'/bin');
    writeFeatureStub($root.'/bin', 'wt', 'echo "unexpected wt $*" >&2; exit 1');
    writeFeatureStub($root.'/bin', 'rex', 'echo "unexpected rex $*" >&2; exit 1');
    writeFeatureStub($root.'/bin', 'direnv', 'exit 0');
    writeFeature($root.'/bin');

    return $root;
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string|false>  $environment
 */
function runFeature(array $arguments, array $environment = []): ProcessResult
{
    $root = featureFixtureRoot();

    return Process::path($root.'/main')
        ->env([
            ...isolatedFeatureGit(),
            'PATH' => $root.'/bin:'.getenv('PATH'),
            'REX_SESSION' => 'session:s0',
            'REX_SERVER' => false,
            ...$environment,
        ])
        ->run([$root.'/bin/feature', ...$arguments]);
}

test('a worktree name that is not lowercase letters, digits and separators is refused', function (): void {
    $result = runFeature(['Jsh-2-B']);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('lowercase letters');
});

test('a worktree name ending in test is refused', function (string $name): void {
    $result = runFeature([$name]);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain("doesn't end in test");
})->with(['jsh-2-test', 'jsh-2-test-3']);

test('a brief with a leading character Claude would read as a command or an option is refused', function (string $char): void {
    $result = runFeature(['jsh-2-b', "{$char}something"]);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain("can't start with")
        ->and($result->errorOutput())->toContain('Claude')
        ->and($result->errorOutput())->not->toContain('her'.'dr');
})->with([
    '!' => ['!'],
    '/' => ['/'],
    '-' => ['-'],
    'two spaces then !' => ['  !'],
    'two spaces then /' => ['  /'],
    'two spaces then -' => ['  -'],
    'a tab then -' => ["\t-"],
]);

test('a brief that is a Claude option is refused whole', function (string $brief): void {
    $result = runFeature(['jsh-2-b', $brief]);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain("can't start with");
})->with([
    '--permission-mode=bypassPermissions',
    '--dangerously-skip-permissions',
    '--settings={"disableAllHooks":true}',
    '--add-dir=/',
]);

test('a brief with a control character is refused', function (string $label, string $char): void {
    $result = runFeature(['jsh-2-b', "line one{$char}line two"]);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('control character')
        ->and($result->errorOutput())->not->toContain('her'.'dr');
})->with([
    'a newline' => ['newline', "\n"],
    'a carriage return' => ['carriage return', "\r"],
    'an escape character' => ['escape', "\x1b"],
]);

test('a worktree name that would collide with an existing worktree once separators fold together is refused', function (): void {
    $result = runFeature(['jsh-1.a']);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('jsh-1-a')
        ->and($result->errorOutput())->toContain('same database');
});

test('a run outside a rex pane is refused', function (): void {
    $result = runFeature(['jsh-2-b'], ['REX_SESSION' => false]);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('run this from a rex pane');
});

test('no rex session labelled jshq is refused', function (): void {
    $bin = featureFixtureRoot().'/bin';
    writeFeatureStub($bin, 'rex', <<<'SH'
        if [ "$1 $2" = "ls --json" ]; then
            echo '{"sessions":[{"session_id":"session:s9","label":"harvst"}]}'
            exit 0
        fi
        echo "unexpected rex $*" >&2
        exit 1
        SH);

    $result = runFeature(['jsh-2-b']);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('no rex session is labelled jshq');
});

test('more than one rex session labelled jshq is refused', function (): void {
    $bin = featureFixtureRoot().'/bin';
    writeFeatureStub($bin, 'rex', <<<'SH'
        if [ "$1 $2" = "ls --json" ]; then
            echo '{"sessions":[{"session_id":"session:s1","label":"jshq"},{"session_id":"session:s2","label":"jshq"}]}'
            exit 0
        fi
        echo "unexpected rex $*" >&2
        exit 1
        SH);

    $result = runFeature(['jsh-2-b']);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('more than one rex session is labelled jshq');
});

/**
 * @return list<list<string>>
 */
function loggedCalls(string $log): array
{
    $calls = [];

    foreach (explode("\n", (string) file_get_contents($log)) as $line) {
        if ($line === '@@call') {
            $calls[] = [];
        } elseif ($calls !== [] && $line !== '') {
            $calls[array_key_last($calls)][] = $line;
        }
    }

    return $calls;
}

/**
 * @param  array<string, string|false>  $environment
 * @return array{result: ProcessResult, rex: list<list<string>>, direnv: list<list<string>>, path: string}
 */
function launchFeature(string $name, string $brief = '', array $environment = [], string $afterAdd = ':', array $replies = []): array
{
    $replies += [
        'window' => '{"window_id":"window:w1","block_ids":["block:lead"]}',
        'right' => '{"block_ids":["block:lazygit"]}',
    ];

    $root = featureFixtureRoot();
    $bin = $root.'/bin';
    $rexLog = $root.'/rex-'.$name.'.log';
    $direnvLog = $root.'/direnv-'.$name.'.log';
    File::put($rexLog, '');
    File::put($direnvLog, '');

    writeFeatureStub($bin, 'wt', str_replace('__AFTER__', $afterAdd, <<<'SH'
        if [ "$1 $2" = "switch --create" ]; then
            name="$3"
            git worktree add --quiet -b "$name" "../$name" >/dev/null
            __AFTER__
            exit 0
        fi
        echo "unexpected wt $*" >&2
        exit 1
        SH));

    writeFeatureStub($bin, 'direnv', str_replace('__LOG__', $direnvLog, <<<'SH'
        printf '@@call\n' >> '__LOG__'
        printf '%s\n' "$@" >> '__LOG__'
        exit 0
        SH));

    writeFeatureStub($bin, 'rex', str_replace(['__LOG__', '__WINDOW__', '__RIGHT__'], [$rexLog, $replies['window'], $replies['right']], <<<'SH'
        printf '@@call\n' >> '__LOG__'
        printf '%s\n' "$@" >> '__LOG__'
        case "$1 $2" in
            "ls --json")
                echo '{"sessions":[{"session_id":"session:s1","label":"jshq"},{"session_id":"session:s2","label":"harvst"}]}'
                exit 0 ;;
            "window new")
                echo '__WINDOW__'
                exit 0 ;;
            "block rename")
                exit 0 ;;
        esac
        case "$*" in
            *--split=right*)
                echo '__RIGHT__'
                exit 0 ;;
            *--split=below*)
                echo '{"block_ids":["block:shell"]}'
                exit 0 ;;
        esac
        echo "unexpected rex $*" >&2
        exit 1
        SH));

    $result = runFeature($brief === '' ? [$name] : [$name, $brief], $environment);

    return [
        'result' => $result,
        'rex' => loggedCalls($rexLog),
        'direnv' => loggedCalls($direnvLog),
        'path' => $root.'/'.$name,
    ];
}

test('a worktree opens as one rex window: the feature lead, lazygit to its right and a shell below lazygit', function (): void {
    $run = launchFeature('jsh-3-happy');
    $path = $run['path'];

    expect($run['result']->errorOutput())->toBe('')
        ->and($run['result']->exitCode())->toBe(0)
        ->and($run['rex'])->toHaveCount(5)
        ->and($run['rex'][0])->toBe(['ls', '--json'])
        ->and(array_slice($run['rex'][1], 0, 8))->toBe(['window', 'new', '-s', 'session:s1', 'jsh-3-happy', '-c', $path, '--json'])
        ->and(array_slice($run['rex'][1], 8, 3))->toBe(['--', 'direnv', 'exec'])
        ->and(array_slice($run['rex'][1], 11, -1))->toBe([$path, 'claude', '--remote-control', '--agent', 'feature-lead', '-n', 'jsh-3-happy'])
        ->and(array_last($run['rex'][1]))->toStartWith("You are the feature lead for jsh-3-happy, running in its own worktree at {$path}.")
        ->and($run['rex'][2])->toBe(['block', 'rename', '-s', 'session:s1', 'block:lead', 'lead-jsh-3-happy'])
        ->and($run['rex'][3])->toBe(['split', '-s', 'session:s1', '-b', 'block:lead', '--split=right', '-c', $path, '--focus=false', '--json', '--', 'direnv', 'exec', $path, 'lazygit'])
        ->and($run['rex'][4])->toBe(['split', '-s', 'session:s1', '-b', 'block:lazygit', '--split=below', '-c', $path, '--focus=false', '--json'])
        ->and($run['result']->output())->toContain('block:lead');
});

test('the direnv warm-up is the only direnv wait feature makes', function (): void {
    $run = launchFeature('jsh-3-warm');

    expect($run['direnv'])->toBe([
        ['allow', $run['path']],
        ['exec', $run['path'], 'true'],
    ]);
});

test('a brief reaches the claude argument list byte for byte, as the last word, after the worktree name', function (string $name, string $brief): void {
    $run = launchFeature($name, $brief);
    $lead = $run['rex'][1] ?? [];

    expect($run['result']->errorOutput())->toBe('')
        ->and($run['result']->exitCode())->toBe(0)
        ->and(array_last($lead))->toBe($brief)
        ->and($lead[count($lead) - 2])->toBe($name);
})->with([
    'quotes, a dollar sign and a backtick' => ['jsh-4-quotes', 'say "hi" it\'s $HOME and `date` is fine'],
    'a backslash, a glob, a semicolon and a pipe' => ['jsh-4-shell', 'a\\b *.php; echo x | cat && (true) > out'],
    'a semicolon and a command substitution' => ['jsh-4-subst', 'done; $(id) and ${HOME}'],
    'a trailing space and a hash' => ['jsh-4-space', 'fix #12 now '],
]);

test('a worktree wt left no usable directory for is refused before anything is opened', function (string $name, string $afterAdd, string $reason): void {
    $run = launchFeature($name, afterAdd: $afterAdd);

    expect($run['result']->exitCode())->toBe(1)
        ->and($run['result']->errorOutput())->toContain($reason)
        ->and(array_filter($run['rex'], fn (array $call): bool => $call[0] !== 'ls'))->toBeEmpty()
        ->and($run['direnv'])->toBeEmpty();
})->with([
    'no worktree for the branch' => ['jsh-5-none', 'git worktree remove --force "../$name"; git branch -D "$name" >/dev/null', 'no worktree'],
    'a worktree whose directory is gone' => ['jsh-5-gone', 'rm -rf "../$name"', 'is not a directory'],
]);

test('a REX_SERVER that is not the local unix socket is refused before anything is opened', function (string $server): void {
    $run = launchFeature('jsh-6-remote', environment: ['REX_SERVER' => $server]);

    expect($run['result']->exitCode())->toBe(1)
        ->and($run['result']->errorOutput())->toContain('REX_SERVER')
        ->and($run['rex'])->toBeEmpty();
})->with(['a tcp address' => 'tcp://127.0.0.1:9', 'a host label' => 'devbox', 'a host and port' => 'devbox:7000']);

test('the local unix socket REX_SERVER a rex pane sets is allowed', function (): void {
    $run = launchFeature('jsh-6-local', environment: ['REX_SERVER' => 'unix:///tmp/rex.sock']);

    expect($run['result']->exitCode())->toBe(0)
        ->and($run['rex'])->toHaveCount(5);
});

test('a rex reply with no block ID stops the launch before the next call uses it', function (string $name, array $replies, int $calls): void {
    $run = launchFeature($name, replies: $replies);

    expect($run['result']->exitCode())->toBe(1)
        ->and($run['result']->errorOutput())->toContain('block ID')
        ->and($run['rex'])->toHaveCount($calls);
})->with([
    'an empty block list from window new' => ['jsh-7-empty', ['window' => '{"window_id":"window:w1","block_ids":[]}'], 2],
    'no block_ids from window new' => ['jsh-7-none', ['window' => '{}'], 2],
    'an empty block list from the first split' => ['jsh-7-split', ['right' => '{"block_ids":[]}'], 4],
]);

test('the feature script no longer calls the old multiplexer', function (): void {
    $script = (string) file_get_contents(featureFixtureRoot().'/bin/feature');

    expect(mb_strtolower($script))->not->toContain('her'.'dr');
});
