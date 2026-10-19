<?php

declare(strict_types=1);

namespace Tests\Laravel;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Assert;

const QUALITY_GATE_GUARD_EXPR = <<<'NIX'
    { extraFiles }:
    let
      lib = import <nixpkgs/lib>;
      search = import ./laravel/guards/search.nix;
      defaults = lib.mapAttrs (_: option: option.default or null) (import ./laravel/devenv.nix { inherit lib; }).options.doghead.laravel.qualityGate;
    in
    import ./laravel/guards/quality-gate.nix {
      jq = "jq";
      grep = "grep";
      realpath = "realpath";
      stat = "stat";
      teardownSearch = search.search;
      teardownSearchRefusalsJson = builtins.toJSON search.refusals;
      fileEntries = defaults.files ++ builtins.fromJSON extraFiles;
      dirEntries = defaults.dirs;
      inherit (defaults)
        composerFlooredScripts
        composerLockedScripts
        composerLockedTopLevelPaths
        packageLockedScripts
        packageLockedTopLevelPaths
        ;
    }
    NIX;

/**
 * @return list<string>
 */
function harvstProbeCorpora(): array
{
    return [
        'tests/fixtures/worktrees/guard-probes.json',
        'tests/fixtures/nix/quality-gate-probes.json',
    ];
}

/**
 * @param  list<string>  $extraFiles
 * @return array{editMatcher: string, editCommand: string, shellMatcher: string, shellCommand: string}
 */
function qualityGateGuard(array $extraFiles = []): array
{
    /** @var array<string, array{editMatcher: string, editCommand: string, shellMatcher: string, shellCommand: string}> $guards */
    static $guards = [];

    $key = (string) json_encode($extraFiles);

    if (isset($guards[$key])) {
        return $guards[$key];
    }

    $result = Process::path(module_path())->run([
        'nix-instantiate', '--eval', '--strict', '--json',
        '--argstr', 'extraFiles', $key,
        '--expr', QUALITY_GATE_GUARD_EXPR,
    ]);

    Assert::assertTrue($result->successful(), $result->errorOutput());

    /** @var array{editMatcher: string, editCommand: string, shellMatcher: string, shellCommand: string} $guard */
    $guard = json_decode($result->output(), true);

    return $guards[$key] = $guard;
}

/**
 * @param  array<string, mixed>  $payload
 * @param  list<string>  $extraFiles
 */
function runQualityGateEditGuard(array $payload, ?string $home = null, array $extraFiles = []): ProcessResult
{
    return Process::input((string) json_encode($payload))
        ->env(['HOME' => $home ?? (string) getenv('HOME')])
        ->run(['bash', '-c', qualityGateGuard($extraFiles)['editCommand']]);
}

/**
 * @param  list<string>  $extraFiles
 */
function runQualityGateShellGuard(string $command, array $extraFiles = []): ProcessResult
{
    return Process::input((string) json_encode(['tool_input' => ['command' => $command]]))
        ->run(['bash', '-c', qualityGateGuard($extraFiles)['shellCommand']]);
}

/**
 * @param  list<string>|null  $reset
 * @return list<string>
 */
function qualityGateCreatedRoots(?array $reset = null): array
{
    /** @var list<string> $roots */
    static $roots = [];
    if ($reset !== null) {
        $roots = $reset;
    }

    return $roots;
}

function qualityGateWorktreeRoot(): string
{
    $root = sys_get_temp_dir().'/harvst.quality-gate-guard-'.bin2hex(random_bytes(8));

    File::ensureDirectoryExists($root.'/tests/Unit/Nix');
    File::ensureDirectoryExists($root.'/.github/workflows');
    File::ensureDirectoryExists($root.'/nix');
    File::ensureDirectoryExists($root.'/.claude');
    File::ensureDirectoryExists($root.'/tests/fixtures/worktrees');
    File::ensureDirectoryExists($root.'/tests/fixtures/nix');
    File::put($root.'/phpstan.neon', 'includes: []');
    File::copy(module_path('tests/fixtures/quality-gate/composer.json'), $root.'/composer.json');
    File::copy(module_path('tests/fixtures/quality-gate/package.json'), $root.'/package.json');

    qualityGateCreatedRoots([...qualityGateCreatedRoots(), $root]);

    return $root;
}

/**
 * @return array<string, mixed>
 */
function qualityGateDecodedPackage(string $root): array
{
    /** @var array<string, mixed> $package */
    $package = json_decode((string) File::get("{$root}/package.json"), true);

    return $package;
}

function qualityGateJsonRoot(string $composerJson): string
{
    $root = sys_get_temp_dir().'/harvst.quality-gate-guard-'.bin2hex(random_bytes(8));

    File::ensureDirectoryExists($root);
    File::put($root.'/composer.json', $composerJson);

    qualityGateCreatedRoots([...qualityGateCreatedRoots(), $root]);

    return $root;
}

beforeEach(function (): void {
    qualityGateCreatedRoots([]);
});

afterEach(function (): void {
    foreach (qualityGateCreatedRoots() as $root) {
        File::deleteDirectory($root);
    }

    qualityGateCreatedRoots([]);
});

test('the edit guard watches Edit, Write, MultiEdit and NotebookEdit', function (string $tool): void {
    expect($tool)->toMatch('/'.qualityGateGuard()['editMatcher'].'/');
})->with(['Edit', 'Write', 'MultiEdit', 'NotebookEdit']);

test('the shell guard watches Bash and Monitor', function (string $tool): void {
    expect($tool)->toMatch('/'.qualityGateGuard()['shellMatcher'].'/');
})->with(['Bash', 'Monitor']);

test('a listed file is refused and told to bring the change to Joey', function (string $relative): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/{$relative}", 'content' => 'x'],
    ]);

    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toContain('Joey');
})->with([
    'phpstan.neon',
    'pint.json',
    'rector.php',
    'phpunit.xml',
    'eslint.config.js',
    '.prettierrc',
    '.prettierignore',
    'tsconfig.json',
    'vitest.config.ts',
    'nix/quality.nix',
    'nix/agents.nix',
    'tests/Unit/Nix/TeardownTest.php',
    '.github/workflows/ci.yml',
]);

test('an unrelated file is not refused', function (): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/README.md", 'content' => 'x'],
    ]);

    expect($result->exitCode())->toBe(0);
});

test('a CONTEXT.md directly inside a protected folder stays open', function (string $relative): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/{$relative}", 'content' => 'x'],
    ]);

    expect($result->exitCode())->toBe(0);
})->with([
    'tests/Unit/Nix/CONTEXT.md',
    '.github/workflows/CONTEXT.md',
]);

test('a CONTEXT.md nested deeper inside a protected folder is still refused', function (): void {
    $root = qualityGateWorktreeRoot();
    File::ensureDirectoryExists($root.'/tests/Unit/Nix/Sub');

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/tests/Unit/Nix/Sub/CONTEXT.md", 'content' => 'x'],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('a listed file matches regardless of case', function (): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/PHPStan.Neon", 'content' => 'x'],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('a name that merely contains a listed basename is not refused', function (): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/xphpstan.neon", 'content' => 'x'],
    ]);

    expect($result->exitCode())->toBe(0);
});

test('a tool call with no path to check refuses by default', function (): void {
    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['content' => 'x'],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('a relative path refuses, since the tools send absolute paths', function (): void {
    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => 'phpstan.neon', 'content' => 'x'],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('a leading ~ expands to $HOME before matching', function (): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => '~/phpstan.neon', 'content' => 'x'],
    ], $root);

    expect($result->exitCode())->toBe(2);
});

test('NotebookEdit is read through notebook_path', function (): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'NotebookEdit',
        'tool_input' => ['notebook_path' => "{$root}/nix/agents.nix", 'new_source' => 'x'],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('an existing file with more than one hard link refuses, even off the protected list', function (): void {
    $root = qualityGateWorktreeRoot();
    File::put($root.'/plain.txt', 'x');
    link($root.'/plain.txt', $root.'/alias.txt');

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/alias.txt", 'content' => 'y'],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('a symlinked alias that resolves into a protected folder refuses', function (): void {
    $root = qualityGateWorktreeRoot();
    symlink($root.'/nix', $root.'/n');

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/n/agents.nix", 'content' => 'x'],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('a resolved path with a non-ASCII byte that APFS folds to a listed name refuses', function (string $suffix): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/{$suffix}", 'content' => 'x'],
    ]);

    expect($result->exitCode())->toBe(2);
})->with([
    'Kelvin sign folds to k in .github/workflows' => ".github/wor\u{212A}flows/ci.yml",
    'long s folds to s in phpstan.neon' => "phpstan\u{017F}.neon",
]);

test('a malformed JSON payload refuses by default', function (): void {
    $result = Process::input('not json {{{')
        ->run(['bash', '-c', qualityGateGuard()['editCommand']]);

    expect($result->exitCode())->toBe(2);
});

test('composer.json Edit, Write and MultiEdit through a raised floor go through', function (): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => [
            'file_path' => "{$root}/composer.json",
            'old_string' => '--min=90',
            'new_string' => '--min=92',
        ],
    ]);

    expect($result->exitCode())->toBe(0);
});

test('composer.json through a lowered floor refuses', function (): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => [
            'file_path' => "{$root}/composer.json",
            'old_string' => '--min=90',
            'new_string' => '--min=85',
        ],
    ]);

    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toContain('Joey');
});

test('composer.json with a second --min= appended is refused', function (): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => [
            'file_path' => "{$root}/composer.json",
            'old_string' => '--min=90',
            'new_string' => '--min=90 --min=1',
        ],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('composer.json defanged with || true refuses even though the floor text survives', function (): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => [
            'file_path' => "{$root}/composer.json",
            'old_string' => 'pest --type-coverage --min=100',
            'new_string' => 'pest --type-coverage --min=100 || true',
        ],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('composer.json with the floor spelled with a space instead of = refuses', function (): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => [
            'file_path' => "{$root}/composer.json",
            'old_string' => 'pest --type-coverage --min=100',
            'new_string' => 'pest --type-coverage --min 100',
        ],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('composer.json with a floored script removed entirely refuses', function (string $old): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => [
            'file_path' => "{$root}/composer.json",
            'old_string' => $old,
            'new_string' => '',
        ],
    ]);

    expect($result->exitCode())->toBe(2);
})->with([
    '"test:types": [
            "pest --type-coverage --min=100"
        ],',
]);

test('composer.json with an unrelated script added goes through', function (): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => [
            'file_path' => "{$root}/composer.json",
            'old_string' => '"$schema"',
            'new_string' => '"foo": ["bar"],
    "$schema"',
        ],
    ]);

    expect($result->exitCode())->toBe(0);
});

test('composer.json MultiEdit raising two floors in order goes through', function (): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'MultiEdit',
        'tool_input' => [
            'file_path' => "{$root}/composer.json",
            'edits' => [
                ['old_string' => '--min=90', 'new_string' => '--min=92'],
                ['old_string' => '--min=65', 'new_string' => '--min=70'],
            ],
        ],
    ]);

    expect($result->exitCode())->toBe(0);
});

test('composer.json Edit with an old_string that is not found refuses', function (): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => [
            'file_path' => "{$root}/composer.json",
            'old_string' => 'this text does not exist anywhere',
            'new_string' => 'x',
        ],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('composer.json Edit with an old_string that is not unique refuses unless replace_all', function (bool $replaceAll, int $expectExit): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => [
            'file_path' => "{$root}/composer.json",
            'old_string' => '@php -r \\"file_exists(\'.env\') || copy(\'.env.example\', \'.env\');\\"',
            'new_string' => 'X',
            'replace_all' => $replaceAll,
        ],
    ]);

    expect($result->exitCode())->toBe($expectExit);
})->with([
    'not unique, no replace_all' => [false, 2],
    'not unique, with replace_all' => [true, 0],
]);

test('composer.json with a duplicate top-level key refuses, by comparing the state with itself', function (string $content, int $expectExit): void {
    $root = qualityGateJsonRoot($content);

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/composer.json", 'content' => $content],
    ]);

    expect($result->exitCode())->toBe($expectExit);
})->with([
    'duplicate key, different shapes' => ['{"s":{"a":["x"]},"s":{"b":{"c":1}}}', 2],
    'duplicate key, same shape' => ['{"s":{"a":["x"]},"s":{"a":["y"]}}', 2],
    'duplicate key, scalar then object' => ['{"a":1,"a":{}}', 2],
    'no duplicate, with empty containers' => ['{"s":{"a":["x"],"e":[],"o":{}}}', 0],
]);

/**
 * @return array<string, mixed>
 */
function qualityGateDecodedComposer(string $root): array
{
    /** @var array<string, mixed> $composer */
    $composer = json_decode((string) File::get("{$root}/composer.json"), true);

    return $composer;
}

test('composer.json with a scripts-aliases entry naming a floored script refuses', function (): void {
    $root = qualityGateWorktreeRoot();
    $composer = qualityGateDecodedComposer($root);
    /** @var array<string, mixed> $scripts */
    $scripts = $composer['scripts'];
    $scripts['a-shadow'] = 'true';
    $composer['scripts'] = $scripts;
    $composer['scripts-aliases'] = ['a-shadow' => ['test:coverage']];

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/composer.json", 'content' => json_encode($composer)],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('composer.json with an unrelated scripts-aliases entry goes through', function (): void {
    $root = qualityGateWorktreeRoot();
    $composer = qualityGateDecodedComposer($root);
    /** @var array<string, mixed> $scripts */
    $scripts = $composer['scripts'];
    $scripts['fmt'] = ['format'];
    $composer['scripts'] = $scripts;
    $composer['scripts-aliases'] = ['fmt' => ['format']];

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/composer.json", 'content' => json_encode($composer)],
    ]);

    expect($result->exitCode())->toBe(0);
});

test('composer.json with a case-variant floored script key refuses', function (): void {
    $root = qualityGateWorktreeRoot();
    $composer = qualityGateDecodedComposer($root);
    /** @var array<string, mixed> $scripts */
    $scripts = $composer['scripts'];
    $scripts['Test:Types'] = $scripts['test:types'];
    $composer['scripts'] = $scripts;

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/composer.json", 'content' => json_encode($composer)],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('composer.json with a locked script body changed refuses', function (string $oldFragment, string $newFragment): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => ['file_path' => "{$root}/composer.json", 'old_string' => $oldFragment, 'new_string' => $newFragment],
    ]);

    expect($result->exitCode())->toBe(2);
})->with([
    'ci:check emptied' => [
        '"Composer\\\\Config::disableProcessTimeout",
            "pnpm run lint:check",
            "pnpm run fmt:check",
            "pnpm run types:check",
            "@test"',
        '"Composer\\\\Config::disableProcessTimeout"',
    ],
    'lint given a level' => ['phpstan analyse', 'phpstan analyse --level=1'],
]);

test('composer.json with a locked top-level path added or changed refuses', function (string $oldFragment, string $newFragment): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => ['file_path' => "{$root}/composer.json", 'old_string' => $oldFragment, 'new_string' => $newFragment],
    ]);

    expect($result->exitCode())->toBe(2);
})->with([
    'bin-dir added under config' => [
        '"config": {
        "allow-plugins"',
        '"config": {
        "bin-dir": "tools/bin",
        "allow-plugins"',
    ],
    'repositories added' => [
        '"minimum-stability"',
        '"repositories": [{"type": "path", "url": "../fake"}],
    "minimum-stability"',
    ],
    'extra.laravel changed' => ['"dont-discover": []', '"dont-discover": ["x/y"]'],
]);

test('composer.json with a scripts-aliases entry naming a newly-locked script refuses', function (): void {
    $root = qualityGateWorktreeRoot();
    $composer = qualityGateDecodedComposer($root);
    /** @var array<string, mixed> $scripts */
    $scripts = $composer['scripts'];
    $scripts['a-shadow'] = 'true';
    $composer['scripts'] = $scripts;
    $composer['scripts-aliases'] = ['a-shadow' => ['ci:check']];

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/composer.json", 'content' => json_encode($composer)],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('package.json with a locked script body changed refuses', function (): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => [
            'file_path' => "{$root}/package.json",
            'old_string' => '"build": "tsc && vite build"',
            'new_string' => '"build": "true"',
        ],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('package.json with build:ssr changed refuses', function (): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => [
            'file_path' => "{$root}/package.json",
            'old_string' => '"build:ssr": "tsc && vite build && vite build --ssr"',
            'new_string' => '"build:ssr": "true"',
        ],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('package.json with a prettier key added refuses', function (): void {
    $root = qualityGateWorktreeRoot();
    $package = qualityGateDecodedPackage($root);
    $package['prettier'] = [];

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/package.json", 'content' => json_encode($package)],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('package.json pre and post hooks around a locked script are locked absent too', function (string $name, string $value, int $expected): void {
    $root = qualityGateWorktreeRoot();
    $package = qualityGateDecodedPackage($root);
    /** @var array<string, mixed> $scripts */
    $scripts = $package['scripts'];
    $scripts[$name] = $value;
    $package['scripts'] = $scripts;

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/package.json", 'content' => json_encode($package)],
    ]);

    expect($result->exitCode())->toBe($expected);
})->with([
    'pretest:unit added' => ['pretest:unit', 'true', 2],
    'postbuild added' => ['postbuild', 'true', 2],
    'prelint:check added' => ['prelint:check', 'sed -i s/x/y/ eslint.config.js', 2],
    'predev added, dev is not locked' => ['predev', 'true', 0],
]);

test('package.json with an unlocked script added or an unlocked script edited goes through', function (string $oldFragment, string $newFragment): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => ['file_path' => "{$root}/package.json", 'old_string' => $oldFragment, 'new_string' => $newFragment],
    ]);

    expect($result->exitCode())->toBe(0);
})->with([
    'add lint:fix' => ['"dev": "vite",', '"dev": "vite",
        "lint:fix": "eslint . --fix",'],
    'edit dev' => ['"dev": "vite"', '"dev": "vite --host"'],
]);

test('package.json with a duplicate top-level key refuses', function (): void {
    $root = qualityGateJsonRoot('{"a":1,"a":{}}');

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/package.json", 'content' => '{"a":1,"a":{}}'],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('a file under nix/ other than CONTEXT.md refuses', function (string $relative): void {
    $root = qualityGateWorktreeRoot();
    File::put("{$root}/{$relative}", 'x');

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/{$relative}", 'content' => 'y'],
    ]);

    expect($result->exitCode())->toBe(2);
})->with([
    'nix/agents.nix',
    'nix/quality-gate-guard.nix',
    'nix/destructive-database-guard.nix',
]);

test('nix/CONTEXT.md stays open', function (): void {
    $root = qualityGateWorktreeRoot();
    File::put("{$root}/nix/CONTEXT.md", 'x');

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/nix/CONTEXT.md", 'content' => 'y'],
    ]);

    expect($result->exitCode())->toBe(0);
});

test('devenv, direnv and claude settings files refuse, named and resolved alike', function (string $relative): void {
    $root = qualityGateWorktreeRoot();
    File::put("{$root}/{$relative}", 'x');

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/{$relative}", 'content' => 'y'],
    ]);

    expect($result->exitCode())->toBe(2);
})->with([
    'devenv.nix',
    'devenv.yaml',
    'devenv.lock',
    'devenv.local.nix',
    'devenv.local.yaml',
    '.envrc',
    '.claude/settings.json',
    '.claude/settings.local.json',
]);

test('a symlinked .claude/settings.json refuses whether matched as given or as resolved', function (): void {
    $root = qualityGateWorktreeRoot();
    File::ensureDirectoryExists(sys_get_temp_dir().'/harvst.quality-gate-guard-store');
    $store = sys_get_temp_dir().'/harvst.quality-gate-guard-store/settings.json';
    File::put($store, '{}');
    symlink($store, "{$root}/.claude/settings.json");

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/.claude/settings.json", 'content' => '{"x":1}'],
    ]);

    expect($result->exitCode())->toBe(2);

    File::delete($store);
});

test('a CONTEXT.md symlinked into a protected folder, with the link itself inside a folder named nix, refuses on the class mismatch', function (): void {
    $root = qualityGateWorktreeRoot();
    File::ensureDirectoryExists("{$root}/a/nix");
    symlink("{$root}/nix/agents.nix", "{$root}/a/nix/CONTEXT.md");

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/a/nix/CONTEXT.md", 'content' => 'y'],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('nix/CONTEXT.md itself made a symlink into a protected file refuses on the class mismatch', function (): void {
    $root = qualityGateWorktreeRoot();
    symlink("{$root}/nix/quality-gate-guard.nix", "{$root}/nix/CONTEXT.md");

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/nix/CONTEXT.md", 'content' => 'y'],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('a composer.json symlinked to package.json refuses on the class mismatch', function (): void {
    $root = qualityGateWorktreeRoot();
    File::ensureDirectoryExists("{$root}/a");
    symlink("{$root}/package.json", "{$root}/a/composer.json");

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => [
            'file_path' => "{$root}/a/composer.json",
            'old_string' => '"build": "tsc && vite build"',
            'new_string' => '"build": "true"',
        ],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('a composer.json symlinked to a protected corpus file refuses on the class mismatch', function (): void {
    $root = qualityGateWorktreeRoot();
    File::ensureDirectoryExists("{$root}/a");
    File::put("{$root}/tests/fixtures/nix/quality-gate-probes.json", '{}');
    symlink("{$root}/tests/fixtures/nix/quality-gate-probes.json", "{$root}/a/composer.json");

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => [
            'file_path' => "{$root}/a/composer.json",
            'old_string' => '{}',
            'new_string' => '{"x":1}',
        ],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('a package.json symlinked to a protected corpus file refuses on the class mismatch', function (): void {
    $root = qualityGateWorktreeRoot();
    File::ensureDirectoryExists("{$root}/a");
    File::put("{$root}/tests/fixtures/nix/quality-gate-probes.json", '{}');
    symlink("{$root}/tests/fixtures/nix/quality-gate-probes.json", "{$root}/a/package.json");

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/a/package.json", 'content' => '{"x":1}'],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('a CONTEXT.md reached through a symlinked folder that is not itself a protected folder refuses as an accepted over-block', function (): void {
    $root = qualityGateWorktreeRoot();
    symlink("{$root}/nix", "{$root}/g");

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => ['file_path' => "{$root}/g/CONTEXT.md", 'old_string' => 'x', 'new_string' => 'y'],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('composer.json reached through a symlinked parent folder still classifies by suffix and goes through', function (): void {
    $root = qualityGateWorktreeRoot();
    File::ensureDirectoryExists("{$root}/real");
    File::copy("{$root}/composer.json", "{$root}/real/composer.json");
    symlink("{$root}/real", "{$root}/alias");

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => [
            'file_path' => "{$root}/alias/composer.json",
            'old_string' => '"post-root-package-install": [',
            'new_string' => '"post-root-package-install-renamed": [',
        ],
    ]);

    expect($result->exitCode())->toBe(0);
});

test('a path with a .. segment through a symlink refuses before classifying, so it never reads the decoy', function (): void {
    $root = qualityGateWorktreeRoot();
    File::ensureDirectoryExists("{$root}/a/b");
    File::put("{$root}/a/composer.json", str_replace('--min=100', '--min=1', (string) File::get("{$root}/composer.json")));
    symlink("{$root}/a/b", "{$root}/l");

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/l/../composer.json", 'content' => '{}'],
    ]);

    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toContain('could not be safely evaluated');
});

test('a package.json path with a .. segment through a symlink refuses before classifying', function (): void {
    $root = qualityGateWorktreeRoot();
    File::ensureDirectoryExists("{$root}/a/b");
    File::copy("{$root}/package.json", "{$root}/a/package.json");
    symlink("{$root}/a/b", "{$root}/l");

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/l/../package.json", 'content' => '{}'],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('a leading . segment refuses before classifying', function (): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => ['file_path' => "{$root}/./phpstan.neon", 'old_string' => 'x', 'new_string' => 'y'],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('a .. segment that nets to the same protected file still refuses', function (): void {
    $root = qualityGateWorktreeRoot();
    File::put("{$root}/nix/CONTEXT.md", 'x');

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => ['file_path' => "{$root}/nix/../nix/CONTEXT.md", 'old_string' => 'x', 'new_string' => 'y'],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('a path with no dot segment at all still goes through unaffected', function (): void {
    $root = qualityGateWorktreeRoot();
    File::put("{$root}/nix/CONTEXT.md", 'x');

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => ['file_path' => "{$root}/nix/CONTEXT.md", 'old_string' => 'x', 'new_string' => 'y'],
    ]);

    expect($result->exitCode())->toBe(0);
});

test('a segment that merely starts with a dot is not a whole dot segment', function (string $relative, string $content): void {
    $root = qualityGateWorktreeRoot();
    File::ensureDirectoryExists(dirname("{$root}/{$relative}"));
    File::put("{$root}/{$relative}", $content);

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => ['file_path' => "{$root}/{$relative}", 'old_string' => $content, 'new_string' => 'y'],
    ]);

    expect($result->exitCode())->toBe(0);
})->with([
    ['.envrc-notes.md', 'x'],
    ['.github/CONTEXT.md', 'x'],
]);

test('composer.json reached through macOS /tmp symlinked to /private/tmp still goes through', function (): void {
    $marker = 'harvst-quality-gate-tmp-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists("/tmp/{$marker}");
    File::copy(module_path('tests/fixtures/quality-gate/composer.json'), "/tmp/{$marker}/composer.json");

    $result = runQualityGateEditGuard([
        'tool_name' => 'Edit',
        'tool_input' => [
            'file_path' => "/tmp/{$marker}/composer.json",
            'old_string' => '"post-root-package-install": [',
            'new_string' => '"post-root-package-install-renamed": [',
        ],
    ]);

    expect($result->exitCode())->toBe(0);

    File::deleteDirectory("/tmp/{$marker}");
});

test('both probe corpora refuse once an app lists them, as Harvst does', function (string $relative): void {
    $root = qualityGateWorktreeRoot();
    File::put("{$root}/{$relative}", '{}');

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/{$relative}", 'content' => '{"x":1}'],
    ], extraFiles: harvstProbeCorpora());

    expect($result->exitCode())->toBe(2);
})->with([
    'tests/fixtures/worktrees/guard-probes.json',
    'tests/fixtures/nix/quality-gate-probes.json',
]);

test('composer.json Write with invalid JSON after the edit refuses', function (): void {
    $root = qualityGateWorktreeRoot();

    $result = runQualityGateEditGuard([
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => "{$root}/composer.json", 'content' => 'not json {{{'],
    ]);

    expect($result->exitCode())->toBe(2);
});

test('a Bash write to a listed file is refused', function (string $command): void {
    expect(runQualityGateShellGuard($command)->exitCode())->toBe(2);
})->with([
    "cd nix && sed -i '' s/x/y/ agents.nix",
    'echo x >phpstan.neon',
    'composer config scripts.test:types pest',
    'git apply x.patch',
    'patch -p1 < x.patch',
]);

test('a Bash read of a listed file is allowed in the forms the builder needs', function (string $command): void {
    expect(runQualityGateShellGuard($command)->exitCode())->toBe(0);
})->with([
    'cat phpstan.neon',
    'cat composer.json',
    'git add nix/agents.nix',
    'git diff nix/agents.nix',
    'git log phpstan.neon',
    'rg agents.nix nix/',
    'git apply --cached x.patch',
    'git apply --check x.patch',
    'vendor/bin/pest tests/Unit/Nix',
    'vendor/bin/pest tests/Unit/Nix/QualityGateGuardHookTest.php',
    'vendor/bin/pest --compact tests/Unit/Nix --filter=something',
]);

test('a Bash command that merely mentions git or patch without naming a listed path is not refused', function (string $command): void {
    expect(runQualityGateShellGuard($command)->exitCode())->toBe(0);
})->with([
    'ls -la',
    'composer ci:check',
    'git status',
]);

test('a wider read form waits for its own adversarial round', function (string $command): void {
    expect(runQualityGateShellGuard($command)->exitCode())->toBe(2);
})->with([
    'head phpstan.neon',
    'tail phpstan.neon',
    'wc -l phpstan.neon',
    'git show HEAD:phpstan.neon',
    'git blame phpstan.neon',
    'git diff --output=x nix/agents.nix',
]);

test('a shell command over 64 KiB refuses by default, before any trigger', function (): void {
    $command = "sed -i '' s/x/y/ nix/agents.nix\n# ".str_repeat('a', 200000);

    expect(runQualityGateShellGuard($command)->exitCode())->toBe(2);
});

test('a triggered command under the 64 KiB cap still refuses on its embedded newline', function (): void {
    $command = "sed -i '' s/x/y/ nix/agents.nix\n# ".str_repeat('a', 60000);

    expect(mb_strlen($command))->toBeLessThanOrEqual(65536)
        ->and(runQualityGateShellGuard($command)->exitCode())->toBe(2);
});

test('a clean read with no padding at all still goes through', function (): void {
    expect(runQualityGateShellGuard('rg -n quality nix/')->exitCode())->toBe(0);
});

/**
 * @return array<string, list<string>>
 */
function qualityGateProbes(): array
{
    /** @var array<string, list<string>> */
    return json_decode(
        (string) file_get_contents(module_path('tests/fixtures/nix/quality-gate-probes.json')),
        true
    );
}

test('the shell guard, listing both probe corpora as Harvst does, gives every recorded probe its outcome', function (string $outcome, string $command): void {
    expect(runQualityGateShellGuard($command, harvstProbeCorpora())->exitCode())
        ->toBe(in_array($outcome, ['block', 'over_block'], true) ? 2 : 0);
})->with(fn (): array => collect(qualityGateProbes())
    ->flatMap(fn (array $commands, string $outcome): array => array_map(fn (string $command): array => [$outcome, $command], $commands))
    ->values()
    ->all());

test('the probe corpus file itself is pure ASCII, so an editor cannot quietly reopen it', function (): void {
    $raw = (string) file_get_contents(module_path('tests/fixtures/nix/quality-gate-probes.json'));

    expect(mb_check_encoding($raw, 'ASCII'))->toBeTrue();
});

test('the guard matches exactly the edit tools and the shell tools', function (): void {
    expect(qualityGateGuard())
        ->editMatcher->toBe('^(Edit|Write|MultiEdit|NotebookEdit)$')
        ->shellMatcher->toBe('^(Bash|Monitor)$');
});
