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
        'nix-instantiate', '--eval', '--strict', '--json', 'laravel/scripts/feature.nix',
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
    writeFeatureStub($root.'/bin', 'herdr', 'echo "unexpected herdr $*" >&2; exit 1');
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
            'HERDR_ENV' => '1',
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

test('a brief with a leading character Claude or herdr would read as an option is refused', function (string $char): void {
    $result = runFeature(['jsh-2-b', "{$char}something"]);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain("can't start with");
})->with([
    '!' => ['!'],
    '/' => ['/'],
    '-' => ['-'],
    'two spaces then !' => ['  !'],
    'two spaces then /' => ['  /'],
    'two spaces then -' => ['  -'],
    'a tab then -' => ["\t-"],
]);

test('a brief with a control character is refused', function (string $label, string $char): void {
    $result = runFeature(['jsh-2-b', "line one{$char}line two"]);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('control character');
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

test('no herdr workspace labelled jshq is refused', function (): void {
    $bin = featureFixtureRoot().'/bin';
    writeFeatureStub($bin, 'herdr', <<<'SH'
        if [ "$1 $2" = "workspace list" ]; then
            echo '{"result":{"workspaces":[]}}'
            exit 0
        fi
        echo "unexpected herdr $*" >&2
        exit 1
        SH);

    $result = runFeature(['jsh-2-b']);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('no herdr workspace is labelled jshq');
});

test('more than one herdr workspace labelled jshq is refused', function (): void {
    $bin = featureFixtureRoot().'/bin';
    writeFeatureStub($bin, 'herdr', <<<'SH'
        if [ "$1 $2" = "workspace list" ]; then
            echo '{"result":{"workspaces":[{"label":"jshq","workspace_id":"w1"},{"label":"jshq","workspace_id":"w2"}]}}'
            exit 0
        fi
        echo "unexpected herdr $*" >&2
        exit 1
        SH);

    $result = runFeature(['jsh-2-b']);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('more than one herdr workspace is labelled jshq');
});

test('a worktree opens as a feature lead in herdr, with the full launch sequence', function (): void {
    $root = featureFixtureRoot();
    $bin = $root.'/bin';
    $log = $root.'/herdr.log';
    File::put($log, '');

    writeFeatureStub($bin, 'wt', <<<'SH'
        if [ "$1 $2" = "switch --create" ]; then
            name="$3"
            git worktree add --quiet -b "$name" "../$name" >/dev/null
            exit 0
        fi
        echo "unexpected wt $*" >&2
        exit 1
        SH);

    writeFeatureStub($bin, 'herdr', str_replace('__LOG__', $log, <<<'SH'
        printf '%s\n' "$*" >> '__LOG__'
        case "$1 $2" in
            "workspace list")
                echo '{"result":{"workspaces":[{"label":"jshq","workspace_id":"w1"}]}}'
                exit 0 ;;
            "tab create")
                echo '{"result":{"root_pane":{"pane_id":"pane-root"}}}'
                exit 0 ;;
            "pane split")
                echo '{"result":{"pane":{"pane_id":"pane-split"}}}'
                exit 0 ;;
            "pane wait-output" | "pane run" | "agent start" | "agent prompt")
                exit 0 ;;
        esac
        echo "unexpected herdr $*" >&2
        exit 1
        SH));

    $result = runFeature(['jsh-3-happy']);

    $lines = array_filter(explode("\n", (string) file_get_contents($log)), fn (string $line): bool => $line !== '');
    $find = fn (string $prefix): ?string => array_first(
        array_filter($lines, fn (string $line): bool => str_starts_with($line, $prefix))
    );

    $agentStart = $find('agent start');
    $agentPrompt = $find('agent prompt');
    $tabCreate = $find('tab create');

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toContain('open in herdr as agent lead-jsh-3-happy')
        ->and($agentStart)->not->toBeNull()
        ->and($agentStart)->toContain('lead-jsh-3-happy')
        ->and($agentStart)->toContain('--agent feature-lead')
        ->and($agentStart)->toContain('--remote-control')
        ->and($agentStart)->not->toContain('You are the feature lead')
        ->and($agentPrompt)->not->toBeNull()
        ->and($agentPrompt)->toContain('You are the feature lead')
        ->and($tabCreate)->not->toBeNull()
        ->and($tabCreate)->toContain('--workspace w1');
});
