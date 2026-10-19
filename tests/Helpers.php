<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Assert;

const WORKSPACE_EXPR = <<<'NIX'
    { appName, root }:
    let
      lib = import <nixpkgs/lib>;
      stub = {
        options.doghead.laravel.appName = lib.mkOption { type = lib.types.str; };
        options.devenv.root = lib.mkOption { type = lib.types.str; };
        options.assertions = lib.mkOption {
          type = lib.types.listOf lib.types.unspecified;
          default = [ ];
        };
        config.doghead.laravel.appName = appName;
        config.devenv.root = root;
      };
      evaled = lib.evalModules { modules = [ ./laravel/workspace.nix stub ]; };
    in
    {
      inherit (evaled.config.doghead.laravel.workspace)
        appName
        worktreeName
        dbName
        testDbName
        reservedDatabasePattern
        redisDbIndex
        ;
    }
    NIX;

/**
 * @param  array<string, string|false>  $env
 * @return array{appName: string, worktreeName: string, dbName: string, testDbName: string, reservedDatabasePattern: string, redisDbIndex: int}
 */
function workspace(string $appName, string $root, array $env = []): array
{
    $result = Process::path(module_path())->env(['CI' => false, ...$env])->run([
        'nix-instantiate', '--eval', '--strict', '--json',
        '--argstr', 'appName', $appName,
        '--argstr', 'root', $root,
        '--expr', WORKSPACE_EXPR,
    ]);

    Assert::assertTrue($result->successful(), $result->errorOutput());

    /** @var array{appName: string, worktreeName: string, dbName: string, testDbName: string, reservedDatabasePattern: string, redisDbIndex: int} */
    return json_decode($result->output(), true);
}

function worktreeRoot(string $name, bool $mainCheckout = false): string
{
    $root = realpath(sys_get_temp_dir()).'/workspace-'.bin2hex(random_bytes(8)).'/'.$name;
    File::ensureDirectoryExists($mainCheckout ? $root.'/.git' : $root);
    register_shutdown_function(fn (): bool => File::deleteDirectory(dirname($root)));

    return $root;
}
