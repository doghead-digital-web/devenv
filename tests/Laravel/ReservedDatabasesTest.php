<?php

declare(strict_types=1);

namespace Tests\Laravel;

use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Assert;

use function Tests\workspace;
use function Tests\worktreeRoot;

test('nix reserves the lowercase databases database-names.nix can produce for the main, develop, test and e2e checkouts', function (string $appName, string $database, bool $reserved): void {
    $result = Process::path(module_path())->run([
        'nix-instantiate', '--eval', '--strict', '--json',
        '--argstr', 'appName', $appName,
        '--argstr', 'database', $database,
        '--expr', '{ appName, database }: builtins.match "${import ./laravel/reserved-databases.nix appName}.*" database != null',
    ]);

    Assert::assertTrue($result->successful(), $result->errorOutput());

    expect(json_decode($result->output()))->toBe($reserved);
})->with([
    ['harvst', 'harvst_main', true],
    ['harvst', 'harvst_main_test', true],
    ['harvst', 'harvst_develop', true],
    ['harvst', 'harvst_test_test_3', true],
    ['harvst', 'harvst_e2e', true],
    ['harvst', 'harvst', true],
    ['harvst', 'harvst_hrvst_198_worktree_reseed', false],
    ['harvst', 'harvst_maintenance', false],
    ['harvst', 'harvst2', false],
    ['harvst', 'harvstx', false],
    ['harvst', 'harvst_x', false],
    ['jshq', 'jshq_main', true],
    ['jshq', 'jshq_main_test', true],
    ['jshq', 'jshq_test', true],
    ['jshq', 'jshq_test_3', true],
    ['jshq', 'jshq_develop', true],
    ['jshq', 'jshq_e2e', true],
    ['jshq', 'jshq', true],
    ['jshq', 'jshq_maintenance', false],
    ['jshq', 'jshq_jsh_12_x', false],
    ['jshq', 'jshq_jsh_12_x_test', false],
]);

test("the main checkout's dev name, as workspace.nix computes it, is reserved by workspace.nix's own pattern", function (string $appName): void {
    $workspace = workspace($appName, worktreeRoot($appName, mainCheckout: true));

    expect($workspace['worktreeName'])->toBe('main')
        ->and($workspace['dbName'])->toMatch('/'.$workspace['reservedDatabasePattern'].'/');
})->with(['harvst', 'jshq']);
