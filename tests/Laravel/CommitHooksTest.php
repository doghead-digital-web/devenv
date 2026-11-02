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
function isolatedCommitGit(): array
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

function commitHooksDir(): string
{
    static $dir = null;

    if ($dir === null) {
        $dir = realpath(sys_get_temp_dir()).'/commit-hooks-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($dir);
        register_shutdown_function(fn (): bool => File::deleteDirectory($dir));
    }

    return $dir;
}

function commitHookScript(string $hook): string
{
    $script = commitHooksDir().'/'.$hook;

    if (! File::exists($script)) {
        $result = Process::path(module_path())->run([
            'nix-instantiate', '--eval', '--strict', '--json', 'laravel/scripts/commit-hooks.nix',
            '--argstr', 'grep', 'grep',
            '--argstr', 'git', 'git',
            '--argstr', 'sed', 'sed',
            '--argstr', 'commitExample', '[JSH-50] feat(jobs): what changed',
            '-A', $hook,
        ]);

        Assert::assertTrue($result->successful(), $result->errorOutput());

        File::put($script, "#!/usr/bin/env bash\n".json_decode($result->output()));
        File::chmod($script, 0755);
    }

    return $script;
}

function commitMessage(string $message): ProcessResult
{
    $file = commitHooksDir().'/COMMIT_EDITMSG-'.bin2hex(random_bytes(4));
    File::put($file, $message);

    return Process::run([commitHookScript('commitMsgFormat'), $file]);
}

/**
 * @param  array<string, string>  $committed
 * @param  array<string, string>  $staged
 */
function stagedLines(array $committed, array $staged): ProcessResult
{
    $repo = commitHooksDir().'/repo-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($repo);
    Process::path($repo)->env(isolatedCommitGit())->run(['git', 'init', '--quiet'])->throw();

    foreach ($committed as $path => $contents) {
        File::ensureDirectoryExists(dirname($repo.'/'.$path));
        File::put($repo.'/'.$path, $contents);
    }

    Process::path($repo)->env(isolatedCommitGit())->run(['git', 'add', '--all'])->throw();
    Process::path($repo)->env(isolatedCommitGit())->run(['git', 'commit', '--quiet', '--allow-empty', '--message=init'])->throw();

    foreach ($staged as $path => $contents) {
        File::ensureDirectoryExists(dirname($repo.'/'.$path));
        File::put($repo.'/'.$path, $contents);
    }

    Process::path($repo)->env(isolatedCommitGit())->run(['git', 'add', '--all'])->throw();

    return Process::path($repo)->env(isolatedCommitGit())->run([commitHookScript('scratchIds')]);
}

test('a commit message citing a team-scratch id anywhere is refused', function (string $message): void {
    $result = commitMessage($message);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('team-scratch id');
})->with([
    'a ruling in the body' => ["feat(jobs): list open jobs\n\nApplies (R15) to the list.\n"],
    'an AC in a list' => ["feat(jobs): list open jobs\n\nCovers (AC4, AC5).\n"],
    'a step' => ["feat(jobs): list open jobs\n\nThe last part of (S10).\n"],
    'a decision in the subject' => ["feat(jobs): list open jobs (D3, D4)\n"],
    'a verdict' => ["feat(jobs): list open jobs\n\nAs (V2) said.\n"],
    'a worked example' => ["feat(jobs): list open jobs\n\nAs in worked example 2.\n"],
]);

test('a commit message with no team-scratch id keeps the subject rule', function (string $message, int $exitCode): void {
    expect(commitMessage($message)->exitCode())->toBe($exitCode);
})->with([
    'a plain conventional subject' => ["fix(ui): what changed\n", 0],
    'a ticket-prefixed subject' => ["[HRVST-215] feat(x): y\n", 0],
    'a ticket id in the body' => ["feat(jobs): list open jobs\n\nFor HRVST-215.\n", 0],
    'a decision spelled out' => ["feat(jobs): list open jobs\n\nSee (decision 512).\n", 0],
    'an id in a comment line git strips' => ["feat(jobs): list open jobs\n# (R15) is in a comment\n", 0],
    'an id in the diff git commit -v adds' => ["feat(jobs): list open jobs\n# ------------------------ >8 ------------------------\n# Do not modify or remove the line above.\ndiff --git a/CONTEXT.md b/CONTEXT.md\n Metrc rounds down (R31).\n", 0],
    'a subject off the convention' => ["list open jobs\n", 1],
]);

test('a staged added line citing a team-scratch id in tests or a CONTEXT.md is refused', function (string $path, string $line): void {
    $result = stagedLines([], [$path => $line."\n"]);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('team-scratch id');
})->with([
    'a test name' => ['tests/Feature/JobsTest.php', "it('applies (R7)', function (): void {});"],
    'a nested CONTEXT.md' => ['app/Domains/Jobs/CONTEXT.md', 'The rule (AC4) holds.'],
    'the root CONTEXT.md' => ['CONTEXT.md', 'See worked example 2.'],
]);

test('staged lines with no new team-scratch id in tests or a CONTEXT.md are allowed', function (array $committed, array $staged): void {
    expect(stagedLines($committed, $staged)->exitCode())->toBe(0);
})->with([
    'an id outside tests and CONTEXT.md' => [[], ['app/Models/Job.php' => "// (R7)\n"]],
    'a decision spelled out and a ticket id in a test' => [[], ['tests/Feature/JobsTest.php' => "it('keeps (decision 512) for HRVST-215', function (): void {});\n"]],
    'an id already on main, unchanged' => [
        ['app/Domains/Metrc/CONTEXT.md' => "Metrc rounds down (R31).\n"],
        ['app/Domains/Metrc/CONTEXT.md' => "Metrc rounds down (R31).\n\nIt reports in grams.\n"],
    ],
    'an id removed' => [
        ['app/Domains/Metrc/CONTEXT.md' => "Metrc rounds down (R31).\n"],
        ['app/Domains/Metrc/CONTEXT.md' => "Metrc rounds down.\n"],
    ],
]);
