<?php

declare(strict_types=1);

namespace Tests\Laravel;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Assert;

use function Tests\workspace;
use function Tests\worktreeRoot;

function databaseNamesFor(string $appName, string $worktreeName): ProcessResult
{
    return Process::path(module_path())->run([
        'nix-instantiate', '--eval', '--strict', '--json',
        '--argstr', 'appName', $appName,
        '--argstr', 'worktreeName', $worktreeName,
        '--expr', '{ appName, worktreeName }: import ./laravel/database-names.nix appName worktreeName',
    ]);
}

/**
 * @return array{dbName: string, testDbName: string}
 */
function moduleDatabaseNames(string $appName, string $worktreeName): array
{
    $result = databaseNamesFor($appName, $worktreeName);

    Assert::assertTrue($result->successful(), $result->errorOutput());

    /** @var array{dbName: string, testDbName: string} */
    return json_decode($result->output(), true);
}

test('nix-instantiate is on the path', function (): void {
    expect(Process::run(['nix-instantiate', '--version'])->successful())->toBeTrue();
});

test('a worktree gets its dev and test database names', function (string $appName, string $worktreeName, string $dbName, string $testDbName): void {
    $result = databaseNamesFor($appName, $worktreeName);

    expect($result->successful())->toBeTrue()
        ->and(json_decode($result->output(), true))->toBe([
            'dbName' => $dbName,
            'testDbName' => $testDbName,
        ]);
})->with([
    'main' => ['harvst', 'main', 'harvst_main', 'harvst_main_test'],
    'develop' => ['harvst', 'develop', 'harvst_develop', 'harvst_develop_test'],
    'mcp' => ['harvst', 'mcp', 'harvst_mcp', 'harvst_mcp_test'],
    'a ticket worktree' => ['harvst', 'hrvst-151-feedback-ownership', 'harvst_hrvst_151_feedback_ownership', 'harvst_hrvst_151_feedback_ownership_test'],
    'another app\'s ticket worktree' => ['jshq', 'jsh-12-caltrans-fetch', 'jshq_jsh_12_caltrans_fetch', 'jshq_jsh_12_caltrans_fetch_test'],
    'dots and dashes' => ['harvst', 'a.b-c', 'harvst_a_b_c', 'harvst_a_b_c_test'],
    'a name past the budget' => [
        'harvst',
        'hrvst-999-a-really-long-worktree-name-that-must-be-shortened',
        'harvst_hrvst_999_a_really_long_worktree_name_that_must_be_shortened',
        'harvst_hrvst_999_a_really_long_worktree_n_b65e5a1e_test',
    ],
    'a name past the budget for a shorter app name' => [
        'jshq',
        'jsh-999-a-really-long-worktree-name-that-must-be-shortened',
        'jshq_jsh_999_a_really_long_worktree_name_that_must_be_shortened',
        'jshq_jsh_999_a_really_long_worktree_name__3c36c72a_test',
    ],
]);

test('a worktree named like a test database does not evaluate', function (string $worktreeName): void {
    $result = databaseNamesFor('harvst', $worktreeName);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('Rename the worktree');
})->with([
    'ends in test' => ['x-test'],
    'ends in a parallel copy' => ['x-test-3'],
    'is test' => ['test'],
]);

test('a worktree whose name is not lowercase letters, digits and separators does not evaluate', function (string $worktreeName): void {
    $result = databaseNamesFor('harvst', $worktreeName);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('Rename the worktree');
})->with([
    'a quote' => ['a"; builtins.abort "x'],
    'a space' => ['a b'],
    'upper case' => ['Main'],
]);

test('workspace.nix wires dbName and testDbName from database-names.nix for the worktree', function (string $directory, bool $mainCheckout, string $worktreeName): void {
    $workspace = workspace('jshq', worktreeRoot($directory, $mainCheckout));
    $module = moduleDatabaseNames('jshq', $worktreeName);

    expect($workspace['appName'])->toBe('jshq')
        ->and($workspace['worktreeName'])->toBe($worktreeName)
        ->and($workspace['dbName'])->toBe($module['dbName'])
        ->and($workspace['testDbName'])->toBe($module['testDbName']);
})->with([
    'a linked worktree' => ['JSH-12-caltrans-fetch', false, 'jsh-12-caltrans-fetch'],
    'the main checkout, whatever its folder is called' => ['jshq', true, 'main'],
]);

test('workspace.nix falls back to the shared test database when CI is set', function (): void {
    $workspace = workspace('jshq', worktreeRoot('jsh-12-caltrans-fetch'), ['CI' => '1']);

    expect($workspace['testDbName'])->toBe('jshq_test');
});

test('workspace.nix ignores a CI already set in the test process itself', function (): void {
    putenv('CI=true');
    $_SERVER['CI'] = 'true';

    try {
        $workspace = workspace('jshq', worktreeRoot('jsh-12-caltrans-fetch'));
    } finally {
        putenv('CI');
        unset($_SERVER['CI']);
    }

    expect($workspace['testDbName'])->toBe(moduleDatabaseNames('jshq', 'jsh-12-caltrans-fetch')['testDbName']);
});
