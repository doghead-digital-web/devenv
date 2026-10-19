<?php

declare(strict_types=1);

namespace Tests\Laravel;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

const FIXTURE_DEVENV_LOCK = '{"nodes":{"root":{}},"root":"root","version":7}';

/**
 * @return array<string, string|false>
 */
function isolatedGit(): array
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
function fixtureGit(string $path, array $arguments): string
{
    return Process::path($path)->env(isolatedGit())->run(['git', ...$arguments])->throw()->output();
}

function writeStub(string $bin, string $name, string $body): void
{
    File::put($bin.'/'.$name, "#!/bin/sh\n".$body."\n");
    File::chmod($bin.'/'.$name, 0755);
}

function fixtureCommit(string $path, string $file, string $message): void
{
    File::put($path.'/'.$file, $message);
    fixtureGit($path, ['add', $file]);
    fixtureGit($path, ['commit', '--quiet', '--message='.$message]);
}

function writeTeardown(string $bin, string $lockAtBuild): void
{
    $result = Process::path(module_path())->run([
        'nix-instantiate', '--eval', '--strict', '--json', 'laravel/scripts/teardown.nix',
        '--argstr', 'jq', 'jq',
        '--argstr', 'grep', 'grep',
        '--argstr', 'gawk', 'gawk',
        '--argstr', 'cmp', 'cmp',
        '--argstr', 'dbUser', 'root',
        '--argstr', 'lockAtBuild', $lockAtBuild,
        '--argstr', 'appName', 'harvst',
    ]);

    Assert::assertTrue($result->successful(), $result->errorOutput());

    File::put($bin.'/teardown', "#!/usr/bin/env bash\n".json_decode($result->output()));
    File::chmod($bin.'/teardown', 0755);
}

function mergeUpstream(string $root): void
{
    fixtureGit($root.'/main', ['worktree', 'add', '--quiet', '-b', 'upstream-main', $root.'/upstream', 'main']);
    fixtureCommit($root.'/upstream', 'other.txt', 'unrelated work on main');
    fixtureGit($root.'/upstream', ['cherry-pick', 'hrvst-8-stack', 'hrvst-8-stack-http', 'hrvst-11-merge-commit']);
    fixtureGit($root.'/main', ['worktree', 'remove', $root.'/upstream']);

    $squashed = mb_trim(fixtureGit($root.'/main', ['commit-tree', 'hrvst-10-squashed^{tree}', '-p', 'main', '-m', 'squash hrvst-10-squashed']));
    $merge = mb_trim(fixtureGit($root.'/main', ['commit-tree', 'upstream-main^{tree}', '-p', 'upstream-main', '-p', $squashed, '-m', 'Merge hrvst-10-squashed']));
    fixtureGit($root.'/main', ['update-ref', 'refs/heads/upstream-main', $merge]);
    fixtureGit($root.'/main', ['update-ref', 'refs/heads/hrvst-10-squashed-remote', $squashed]);
    fixtureGit($root.'/origin.git', ['fetch', '--quiet', $root.'/main', 'upstream-main:main', 'hrvst-10-squashed-remote:hrvst-10-squashed', 'main:hrvst-12-ahead']);
    fixtureGit($root.'/main', ['branch', '--quiet', '-D', 'upstream-main', 'hrvst-10-squashed-remote']);
}

function worktreeFixture(): string
{
    static $root = null;

    if (is_string($root)) {
        return $root;
    }

    $root = realpath(sys_get_temp_dir()).'/worktrees-'.Str::lower(Str::random(8));
    register_shutdown_function(fn (): bool => File::deleteDirectory($root));

    File::ensureDirectoryExists($root.'/main');
    File::put($root.'/main/devenv.lock', FIXTURE_DEVENV_LOCK);
    File::put($root.'/devenv.lock.build', FIXTURE_DEVENV_LOCK);
    fixtureGit($root.'/main', ['init', '--quiet', '--initial-branch=main']);
    fixtureGit($root.'/main', ['add', '--all']);
    fixtureGit($root.'/main', ['commit', '--quiet', '--message=init']);
    fixtureGit($root, ['clone', '--quiet', '--bare', $root.'/main', $root.'/origin.git']);
    fixtureGit($root.'/main', ['remote', 'add', 'origin', $root.'/origin.git']);
    fixtureGit($root.'/main', ['fetch', '--quiet', 'origin']);
    fixtureGit($root.'/main', ['branch', 'hrvst-1-merged']);
    fixtureGit($root.'/main', ['branch', 'hrvst-1-merged-shared']);

    foreach ([
        'develop' => 'develop',
        'hrvst-1-merged-http' => 'hrvst-1-merged',
        'hrvst-2-unmerged' => 'hrvst-2-unmerged',
        'hrvst-3-dirty' => 'hrvst-3-dirty',
        'a-b' => 'a-b',
        'a.b' => 'a.b',
        'e2e' => 'e2e',
        'hrvst-5-locked' => 'hrvst-5-locked',
        'hrvst-6-twice' => 'hrvst-6-twice',
        'hrvst-6-twice-nested' => 'nested/hrvst-6-twice',
        'hrvst-7-gh-broken' => 'hrvst-7-gh-broken',
        'hrvst-8-stack' => 'hrvst-8-stack',
        'hrvst-9-reverted' => 'hrvst-9-reverted',
        'hrvst-10-squashed' => 'hrvst-10-squashed',
        'hrvst-11-merge-commit' => 'hrvst-11-merge-commit',
        'hrvst-12-ahead' => 'hrvst-12-ahead',
    ] as $branch => $directory) {
        fixtureGit($root.'/main', ['worktree', 'add', '--quiet', '-b', $branch, $root.'/'.$directory]);
    }

    fixtureGit($root.'/hrvst-2-unmerged', ['commit', '--quiet', '--allow-empty', '--message=unmerged work']);
    File::put($root.'/hrvst-3-dirty/notes.txt', 'uncommitted');
    fixtureCommit($root.'/hrvst-8-stack', 'stack-a.txt', 'stack layer a');
    fixtureGit($root.'/hrvst-8-stack', ['switch', '--quiet', '--create', 'hrvst-8-stack-http']);
    fixtureCommit($root.'/hrvst-8-stack', 'stack-b.txt', 'stack layer b');
    fixtureCommit($root.'/hrvst-9-reverted', 'reverted.txt', 'work that is reverted');
    fixtureGit($root.'/hrvst-9-reverted', ['revert', '--no-edit', 'HEAD']);
    fixtureCommit($root.'/hrvst-10-squashed', 'one.txt', 'first half');
    fixtureCommit($root.'/hrvst-10-squashed', 'two.txt', 'second half');
    fixtureCommit($root.'/hrvst-11-merge-commit', 'picked.txt', 'work main picked up');
    fixtureCommit($root.'/hrvst-12-ahead', 'ahead.txt', 'work past the pushed copy');
    fixtureGit($root.'/hrvst-12-ahead', ['revert', '--no-edit', 'HEAD']);
    mergeUpstream($root);
    $unrelated = mb_trim(fixtureGit($root.'/origin.git', ['log', '--format=%H', '--grep=unrelated work on main', 'main']));
    fixtureGit($root.'/hrvst-11-merge-commit', ['merge', '--quiet', '--no-ff', '--no-commit', $unrelated]);
    fixtureCommit($root.'/hrvst-11-merge-commit', 'smuggled.txt', 'merge main and add work main never saw');
    fixtureGit($root.'/main', ['worktree', 'lock', $root.'/hrvst-5-locked']);
    File::copy(module_path('tests/fixtures/worktrees/gh-stack-file.json'), $root.'/main/.git/worktrees/develop/gh-stack');
    File::copy(module_path('tests/fixtures/worktrees/gh-stack-file-target.json'), $root.'/main/.git/worktrees/hrvst-1-merged/gh-stack');

    $fixtures = module_path('tests/fixtures/worktrees');
    $bin = $root.'/bin';
    File::ensureDirectoryExists($bin);

    writeStub($bin, 'mysql', sprintf('cat %s/schemata.txt', $fixtures));
    writeStub($bin, 'lsof', sprintf('sed "s#@ROOT@#%s#g" %s/lsof.txt', $root, $fixtures));
    writeStub($bin, 'ps', sprintf('for pid; do :; done; awk -v pid="$pid" \'$1 == pid { print $2, $3 }\' %s/ps.txt', $fixtures));
    writeStub($bin, 'gh', sprintf(<<<'SH'
        case "$(basename "$PWD")" in
          hrvst-1-merged) cat %1$s/gh-stack-view.json ;;
          hrvst-8-stack) cat %1$s/gh-stack-view-restacked.json ;;
          hrvst-7-gh-broken) echo "HTTP 502: Bad Gateway" >&2; exit 1 ;;
          *) echo "current branch is not part of a stack" >&2; exit 2 ;;
        esac
        SH, $fixtures));
    writeStub($bin, 'herdr', sprintf(<<<'SH'
        case "$1 $2" in
          "workspace list") cat %1$s/herdr-workspaces.json ;;
          "tab list") if [ "$4" = wD ]; then cat %1$s/herdr-tabs.json; else echo '{"result":{"tabs":[]}}'; fi ;;
          "pane list") sed "s#@ROOT@#%2$s#g" %1$s/herdr-panes.json ;;
          *) echo "unexpected herdr $*" >&2; exit 1 ;;
        esac
        SH, $fixtures, $root));
    writeStub($bin, 'wt', 'echo "unexpected wt $*" >&2; exit 1');
    writeTeardown($bin, $root.'/devenv.lock.build');

    return $root;
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string|false>  $environment
 */
function runTeardown(array $arguments, string $from = 'main', array $environment = []): ProcessResult
{
    $root = worktreeFixture();

    return Process::path($root.'/'.$from)
        ->env([
            ...isolatedGit(),
            'PATH' => $root.'/bin:'.getenv('PATH'),
            'HERDR_TAB_ID' => 'wD:t1',
            ...$environment,
        ])
        ->run([$root.'/bin/teardown', ...$arguments]);
}

test('a dry run plans exactly the worktree, its merged branches and its own databases', function (): void {
    $root = worktreeFixture();
    $sha = mb_trim(fixtureGit($root.'/main', ['rev-parse', 'main']));

    $result = runTeardown(['--dry-run', 'hrvst-1-merged']);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toBe(implode("\n", [
            'Plan for hrvst-1-merged:',
            '  close tab        wD:t5',
            '  stop process     101 php',
            '  stop process     102 node',
            "  remove worktree  {$root}/hrvst-1-merged",
            "                   wt remove --foreground --no-hooks --no-delete-branch --reap -y {$root}/hrvst-1-merged",
            "  delete branch    hrvst-1-merged {$sha} (merged into origin/main)",
            "  delete branch    hrvst-1-merged-http {$sha} (merged into origin/main)",
            '  remove database  harvst_hrvst_1_merged',
            '  remove database  harvst_hrvst_1_merged_test',
            '  remove database  harvst_hrvst_1_merged_test_test_1',
            '  remove database  harvst_hrvst_1_merged_test_test_12',
            'Dry run: nothing was removed.',
            '',
        ]));
});

test('a dry run counts a restacked stack whose rebased copies merged as merged by patch', function (): void {
    $root = worktreeFixture();
    $bottom = mb_trim(fixtureGit($root.'/main', ['rev-parse', 'hrvst-8-stack']));
    $top = mb_trim(fixtureGit($root.'/main', ['rev-parse', 'hrvst-8-stack-http']));

    $result = runTeardown(['--dry-run', 'hrvst-8-stack']);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toBe(implode("\n", [
            'Plan for hrvst-8-stack:',
            '  herdr tab        none',
            "  remove worktree  {$root}/hrvst-8-stack",
            "                   wt remove --foreground --no-hooks --no-delete-branch --reap -y {$root}/hrvst-8-stack",
            "  delete branch    hrvst-8-stack {$bottom} (merged into origin/main by patch)",
            "  delete branch    hrvst-8-stack-http {$top} (merged into origin/main by patch)",
            '  databases        none found',
            'Dry run: nothing was removed.',
            '',
        ]));
});

test('a dry run counts a branch whose pushed copy has the same tree and merged as merged by tree', function (): void {
    $root = worktreeFixture();
    $sha = mb_trim(fixtureGit($root.'/main', ['rev-parse', 'hrvst-10-squashed']));

    $result = runTeardown(['--dry-run', 'hrvst-10-squashed']);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toContain("  delete branch    hrvst-10-squashed {$sha} (merged into origin/main by tree via origin/hrvst-10-squashed)");
});

test('a dry run still refuses a commit and its revert that never reached main', function (): void {
    $root = worktreeFixture();
    $sha = mb_trim(fixtureGit($root.'/main', ['rev-parse', 'hrvst-9-reverted']));

    $result = runTeardown(['--dry-run', 'hrvst-9-reverted']);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain("  keep branch      hrvst-9-reverted {$sha} (not merged into origin/main)")
        ->and($result->errorOutput())->toContain('hrvst-9-reverted is not merged into origin/main');
});

test('a dry run refuses a merge commit that adds work main never saw, and --force keeps it', function (): void {
    $root = worktreeFixture();
    $sha = mb_trim(fixtureGit($root.'/main', ['rev-parse', 'hrvst-11-merge-commit']));

    $refused = runTeardown(['--dry-run', 'hrvst-11-merge-commit']);
    $forced = runTeardown(['--dry-run', '--force', 'hrvst-11-merge-commit']);

    expect($refused->exitCode())->toBe(1)
        ->and($refused->errorOutput())->toContain('hrvst-11-merge-commit is not merged into origin/main')
        ->and($forced->exitCode())->toBe(0)
        ->and($forced->output())->toContain("  keep branch      hrvst-11-merge-commit {$sha} (not merged into origin/main)");
});

test('a dry run refuses local commits past a pushed copy that merged, even when they cancel out', function (): void {
    $root = worktreeFixture();
    $sha = mb_trim(fixtureGit($root.'/main', ['rev-parse', 'hrvst-12-ahead']));

    $result = runTeardown(['--dry-run', 'hrvst-12-ahead']);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain("  keep branch      hrvst-12-ahead {$sha} (not merged into origin/main)")
        ->and($result->errorOutput())->toContain('hrvst-12-ahead is not merged into origin/main');
});

test('a dry run refuses what teardown must never remove', function (string $target, string $from, string $reason): void {
    $result = runTeardown(['--dry-run', $target], $from);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain($reason);
})->with([
    'main' => ['main', 'develop', 'teardown never removes main'],
    'develop' => ['develop', 'main', 'teardown never removes develop'],
    'a worktree that does not exist' => ['hrvst-9-missing', 'main', 'no worktree named hrvst-9-missing'],
    'a name that is not a worktree name' => ['../main', 'main', 'is not a worktree name'],
    'two worktrees with one name' => ['hrvst-6-twice', 'main', 'more than one worktree is named hrvst-6-twice'],
    'a locked worktree' => ['hrvst-5-locked', 'main', 'hrvst-5-locked is marked locked'],
    'the worktree it runs from' => ['hrvst-1-merged', 'hrvst-1-merged', 'teardown runs from inside hrvst-1-merged'],
    'a database name another worktree shares' => ['a-b', 'main', "harvst_a_b is also a.b's"],
    'a reserved database name' => ['e2e', 'main', 'harvst_e2e is reserved'],
    'an unmerged branch' => ['hrvst-2-unmerged', 'main', 'hrvst-2-unmerged is not merged into origin/main'],
    'uncommitted changes' => ['hrvst-3-dirty', 'main', 'hrvst-3-dirty has uncommitted changes'],
    'a gh stack failure' => ['hrvst-7-gh-broken', 'main', 'gh stack view failed in hrvst-7-gh-broken'],
]);

test('a forced dry run keeps unmerged branches and skips a tab with a pane outside the worktree', function (): void {
    $root = worktreeFixture();
    $sha = mb_trim(fixtureGit($root.'/main', ['rev-parse', 'hrvst-2-unmerged']));

    $result = runTeardown(['--dry-run', '--force', 'hrvst-2-unmerged']);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())
        ->toContain("  herdr tab        skipped: pane wD:p9 is outside the worktree ({$root}/hrvst-2-unmerged-copy)")
        ->toContain('  process          105 nvim on ttys002 has a terminal: it must exit when the tab closes, or teardown stops there')
        ->toContain("wt remove --foreground --no-hooks --no-delete-branch --reap -y -f {$root}/hrvst-2-unmerged")
        ->toContain("  keep branch      hrvst-2-unmerged {$sha} (not merged into origin/main)")
        ->toContain('  remove database  harvst_hrvst_2_unmerged_test')
        ->not->toContain('delete branch');
});

test('a forced dry run says uncommitted changes are lost and skips a tab whose label is not unique', function (): void {
    $result = runTeardown(['--dry-run', '--force', 'hrvst-3-dirty']);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())
        ->toContain('  herdr tab        skipped: more than one tab in harvst is labelled hrvst-3-dirty')
        ->toContain('  uncommitted changes in it are lost')
        ->toContain('  databases        none found');
});

test('a dry run never closes the tab it runs in', function (): void {
    $result = runTeardown(['--dry-run', 'hrvst-1-merged'], environment: ['HERDR_TAB_ID' => 'wD:t5']);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toContain('  herdr tab        skipped: wD:t5 is the tab teardown runs in');
});

test('a real run refuses when an agent may be running it', function (string $variable): void {
    $result = runTeardown(['hrvst-1-merged'], environment: [
        'CLAUDECODE' => false,
        'CLAUDE_CODE_ENTRYPOINT' => false,
        'CLAUDE_PID' => false,
        'AI_AGENT' => false,
        $variable => '',
    ]);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain("{$variable} is set");
})->with(['CLAUDECODE', 'CLAUDE_CODE_ENTRYPOINT', 'CLAUDE_PID', 'AI_AGENT']);

test('a real run refuses outside the main checkout and without a terminal', function (string $from, string $reason): void {
    $result = runTeardown(['hrvst-1-merged'], $from, [
        'CLAUDECODE' => false,
        'CLAUDE_CODE_ENTRYPOINT' => false,
        'CLAUDE_PID' => false,
        'AI_AGENT' => false,
    ]);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain($reason)
        ->and($result->output())->toBeEmpty();
})->with([
    'from another worktree' => ['develop', 'a real run goes from the main checkout'],
    'with no terminal' => ['main', 'there is no terminal to confirm on'],
]);

test('a real run refuses when the devenv.lock on main is not the one this build was made with', function (?string $lock): void {
    $root = worktreeFixture();
    $other = $root.'/relocked-'.Str::lower(Str::random(8));
    File::ensureDirectoryExists($other);
    File::put($other.'/README.md', 'relocked');

    if ($lock !== null) {
        File::put($other.'/devenv.lock', $lock);
    }

    fixtureGit($other, ['init', '--quiet', '--initial-branch=main']);
    fixtureGit($other, ['add', '--all']);
    fixtureGit($other, ['commit', '--quiet', '--message=relocked']);

    $result = runTeardown(['hrvst-1-merged'], basename($other), [
        'CLAUDECODE' => false,
        'CLAUDE_CODE_ENTRYPOINT' => false,
        'CLAUDE_PID' => false,
        'AI_AGENT' => false,
    ]);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('devenv.lock on main differs from the one this teardown was built with');
})->with([
    'a different lock' => ['{"nodes":{"root":{}},"root":"root","version":8}'],
    'no lock at all' => [null],
]);
