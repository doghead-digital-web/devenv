<?php

declare(strict_types=1);

namespace Tests\Laravel;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;

const FEATURE_DATABASE = 'acme_acm_1_reseed';

function evaluateDestructiveDatabaseGuard(string $dbName, string $worktreeRoot, string $testDbName): ProcessResult
{
    return Process::path(module_path())->run([
        'nix-instantiate', '--eval', '--strict', '--json', 'laravel/guards/destructive-database.nix',
        '--argstr', 'jq', 'jq',
        '--argstr', 'grep', 'grep',
        '--argstr', 'appName', 'acme',
        '--argstr', 'dbName', $dbName,
        '--argstr', 'testDbName', $testDbName,
        '--argstr', 'worktreeRoot', $worktreeRoot,
    ]);
}

/**
 * @return array{matcher: string, command: string}
 */
function destructiveDatabaseGuard(string $dbName, string $worktreeRoot, ?string $testDbName = null): array
{
    $result = evaluateDestructiveDatabaseGuard($dbName, $worktreeRoot, $testDbName ?? $dbName.'_test');

    Assert::assertTrue($result->successful(), $result->errorOutput());

    /** @var array{matcher: string, command: string} */
    return json_decode($result->output(), true);
}

/**
 * @param  array<string, string|false>  $environment
 */
function runDestructiveDatabaseGuard(string $hook, string $tool, string $command, array $environment = []): ProcessResult
{
    return Process::input((string) json_encode(['tool_name' => $tool, 'tool_input' => ['command' => $command]]))
        ->env(['DB_URL' => false, 'APP_CONFIG_CACHE' => false, 'APP_ENV' => false, ...$environment])
        ->run(['bash', '-c', $hook]);
}

function guardedWorktreeRoots(): string
{
    return sys_get_temp_dir().'/acme.guard-roots-'.getmypid();
}

function guardedWorktreeRoot(): string
{
    $root = guardedWorktreeRoots().'/'.bin2hex(random_bytes(8));

    File::ensureDirectoryExists($root.'/bootstrap/cache');
    File::put($root.'/.env', "APP_ENV=local\nDB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\n");

    return $root;
}

function reseed(string $root, string $seed = '--seed', string $database = FEATURE_DATABASE): string
{
    return "DB_DATABASE={$database} php {$root}/artisan migrate:fresh {$seed}";
}

function featureHook(string $root): string
{
    return destructiveDatabaseGuard(FEATURE_DATABASE, $root)['command'];
}

afterEach(function (): void {
    File::deleteDirectory(guardedWorktreeRoots());
});

dataset('tools', ['Bash', 'Monitor']);

test('the hook watches the Monitor tool as well as Bash', function (string $tool): void {
    $root = guardedWorktreeRoot();

    expect($tool)->toMatch('/'.destructiveDatabaseGuard(FEATURE_DATABASE, $root)['matcher'].'/');
})->with('tools');

test('the hook matches exactly the Bash and Monitor tools', function (): void {
    expect(destructiveDatabaseGuard(FEATURE_DATABASE, guardedWorktreeRoot())['matcher'])->toBe('^(Bash|Monitor)$');
});

test('a feature worktree may rebuild its own dev database in either fixed form', function (string $tool, string $seed): void {
    $root = guardedWorktreeRoot();

    expect(runDestructiveDatabaseGuard(featureHook($root), $tool, reseed($root, $seed))->exitCode())->toBe(0);
})->with('tools')->with(['--seed', '--seeder=DemoSeeder']);

test('a feature worktree may still wipe its test database', function (string $tool): void {
    $root = guardedWorktreeRoot();

    expect(runDestructiveDatabaseGuard(featureHook($root), $tool, 'DB_DATABASE="${DB_TEST_DATABASE:?}" APP_ENV=testing php artisan migrate:fresh --force')->exitCode())->toBe(0);
})->with('tools');

test('a feature worktree refuses an abbreviated destructive command on another database', function (string $tool, string $name): void {
    $root = guardedWorktreeRoot();

    expect(runDestructiveDatabaseGuard(featureHook($root), $tool, "DB_DATABASE=acme_acm_2_other php artisan {$name}")->exitCode())->toBe(2);
})->with('tools')->with(['mi:fr', ':fr', 'd:wi', 'mi:rol', 'mi:res', 'mi:ref', 'MI:FR']);

test('a feature worktree lets through commands that wipe nothing', function (string $tool, string $name): void {
    $root = guardedWorktreeRoot();

    expect(runDestructiveDatabaseGuard(featureHook($root), $tool, "php artisan {$name}")->exitCode())->toBe(0);
})->with('tools')->with(['route:list', 'make:model', 'migrate:status', 'db:seed', 'db:show']);

/**
 * @return array<string, string>
 */
function migrateAndDatabaseAbbreviations(): array
{
    $console = new Application;
    $names = [];

    foreach (Artisan::all() as $name => $command) {
        if (is_string($name) && $command instanceof Command && $command->getName() === $name) {
            $console->addCommand(new Command($name)->setAliases(array_filter($command->getAliases(), is_string(...))));
            $names[] = $name;
        }
    }

    $abbreviations = [];

    foreach (array_filter($names, fn (string $name): bool => Str::startsWith($name, ['migrate:', 'db:'])) as $name) {
        [$namespace, $command] = explode(':', $name, 2);

        foreach (range(0, mb_strlen($namespace)) as $namespaceLength) {
            foreach (range(0, mb_strlen($command)) as $commandLength) {
                $abbreviation = mb_substr($namespace, 0, $namespaceLength).':'.mb_substr($command, 0, $commandLength);

                try {
                    $abbreviations[$abbreviation] = (string) $console->find($abbreviation)->getName();
                } catch (CommandNotFoundException) {
                    continue;
                }
            }
        }
    }

    return $abbreviations;
}

test('every abbreviation Symfony resolves to a destructive command is refused, in either case', function (): void {
    $hook = featureHook(guardedWorktreeRoot());
    $destructive = collect(migrateAndDatabaseAbbreviations())
        ->filter(fn (string $name): bool => in_array($name, ['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'db:wipe'], true))
        ->keys();

    expect($destructive)->toContain('mi:fr', ':fr', 'd:wi', 'mi:rol', 'migrate:fresh', 'db:wipe');

    foreach ($destructive->flatMap(fn (string $abbreviation): array => [$abbreviation, mb_strtoupper($abbreviation)]) as $spelling) {
        expect(runDestructiveDatabaseGuard($hook, 'Bash', "DB_DATABASE=acme_acm_2_other php artisan {$spelling}")->exitCode())->toBe(2, $spelling);
    }
});

test('every abbreviation Symfony resolves to another migrate or db command gets through', function (): void {
    $hook = featureHook(guardedWorktreeRoot());
    $harmless = collect(migrateAndDatabaseAbbreviations())
        ->reject(fn (string $name): bool => in_array($name, ['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'db:wipe'], true))
        ->keys();

    expect($harmless)->toContain('mi:st', 'd:se', 'migrate:status');

    foreach ($harmless as $abbreviation) {
        expect(runDestructiveDatabaseGuard($hook, 'Bash', "php artisan {$abbreviation}")->exitCode())->toBe(0, $abbreviation);
    }
});

test('a feature worktree refuses every other form and names the two it allows', function (string $tool, string $command): void {
    $root = guardedWorktreeRoot();

    $result = runDestructiveDatabaseGuard(featureHook($root), $tool, strtr($command, ['{root}' => $root, '{dotless root}' => str_replace('.', 'x', $root)]));

    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toContain(reseed($root));
})->with('tools')->with([
    'a relative artisan' => ['DB_DATABASE=acme_acm_1_reseed php artisan migrate:fresh --seed'],
    'no inline database' => ['php {root}/artisan migrate:fresh --seed'],
    'the main database inline' => ['DB_DATABASE=acme_main php {root}/artisan migrate:fresh --seed'],
    'another database inline' => ['DB_DATABASE=acme_acm_2_other php {root}/artisan migrate:fresh --seed'],
    'a quoted database' => ['DB_DATABASE="acme_acm_1_reseed" php {root}/artisan migrate:fresh --seed'],
    'a variable database' => ['DB_DATABASE="$DB_DATABASE" php {root}/artisan migrate:fresh --seed'],
    'a root whose dot is another character' => ['DB_DATABASE=acme_acm_1_reseed php {dotless root}/artisan migrate:fresh --seed'],
    'another root' => ['DB_DATABASE=acme_acm_1_reseed php /Users/joey/github.com/acme/main/artisan migrate:fresh --seed'],
    'a cd first' => ['cd {root} && DB_DATABASE=acme_acm_1_reseed php {root}/artisan migrate:fresh --seed'],
    'a cd after a semicolon' => ['cd /tmp; DB_DATABASE=acme_acm_1_reseed php {root}/artisan migrate:fresh --seed'],
    'chained after' => ['DB_DATABASE=acme_acm_1_reseed php {root}/artisan migrate:fresh --seed && echo done'],
    'chained with a semicolon' => ['DB_DATABASE=acme_acm_1_reseed php {root}/artisan migrate:fresh --seed; true'],
    'piped' => ['DB_DATABASE=acme_acm_1_reseed php {root}/artisan migrate:fresh --seed | cat'],
    'a trailing comment' => ['DB_DATABASE=acme_acm_1_reseed php {root}/artisan migrate:fresh --seed # ok'],
    'a trailing space' => ['DB_DATABASE=acme_acm_1_reseed php {root}/artisan migrate:fresh --seed '],
    'a leading space' => [' DB_DATABASE=acme_acm_1_reseed php {root}/artisan migrate:fresh --seed'],
    'an environment prefix' => ['APP_ENV=local DB_DATABASE=acme_acm_1_reseed php {root}/artisan migrate:fresh --seed'],
    'env in front' => ['env DB_DATABASE=acme_acm_1_reseed php {root}/artisan migrate:fresh --seed'],
    'forced' => ['DB_DATABASE=acme_acm_1_reseed php {root}/artisan migrate:fresh --seed --force'],
    'another connection' => ['DB_DATABASE=acme_acm_1_reseed php {root}/artisan migrate:fresh --seed --database=mysql'],
    'another environment' => ['DB_DATABASE=acme_acm_1_reseed php {root}/artisan migrate:fresh --seed --env=local'],
    'another seeder' => ['DB_DATABASE=acme_acm_1_reseed php {root}/artisan migrate:fresh --seeder=WorkbookSeeder'],
    'no seed' => ['DB_DATABASE=acme_acm_1_reseed php {root}/artisan migrate:fresh'],
    'an abbreviated command' => ['DB_DATABASE=acme_acm_1_reseed php {root}/artisan migrate:fre --seed'],
    'a refresh' => ['DB_DATABASE=acme_acm_1_reseed php {root}/artisan migrate:refresh --seed'],
    'a wipe' => ['DB_DATABASE=acme_acm_1_reseed php {root}/artisan db:wipe'],
]);

test('a feature worktree refuses a reseed that spans two lines', function (string $tool, string $separator): void {
    $root = guardedWorktreeRoot();

    $result = runDestructiveDatabaseGuard(featureHook($root), $tool, reseed($root).$separator);

    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toContain('single line');
})->with('tools')->with(["\r", "\necho done"]);

test('a feature worktree refuses a reseed while a config cache would redirect it', function (string $tool): void {
    $root = guardedWorktreeRoot();

    File::put($root.'/bootstrap/cache/config.php', '<?php return [];');

    $result = runDestructiveDatabaseGuard(featureHook($root), $tool, reseed($root));

    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toContain('config:clear');
})->with('tools');

test('a feature worktree refuses a reseed while .env could point it elsewhere', function (string $tool, string $line): void {
    $root = guardedWorktreeRoot();

    File::append($root.'/.env', $line."\n");

    $result = runDestructiveDatabaseGuard(featureHook($root), $tool, reseed($root));

    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toContain('Remove that line from .env');
})->with('tools')->with([
    'a database URL' => ['DB_URL=mysql://root@127.0.0.1:3306/acme_main'],
    'an empty database URL' => ['DB_URL='],
    'an exported database URL' => ['export DB_URL=mysql://root@127.0.0.1:3306/acme_main'],
    'an indented database URL' => ['  DB_URL = mysql://root@127.0.0.1:3306/acme_main'],
    'a config cache path' => ['APP_CONFIG_CACHE=/tmp/config.php'],
    'an exported config cache path' => ['export APP_CONFIG_CACHE=/tmp/config.php'],
    'a double-quoted database URL key' => ['"DB_URL"=mysql://root@127.0.0.1:3306/acme_main'],
    'a single-quoted config cache key' => ["'APP_CONFIG_CACHE'=/tmp/config.php"],
]);

test('a feature worktree refuses a reseed while the session could point it elsewhere', function (string $tool, string $variable): void {
    $root = guardedWorktreeRoot();

    $result = runDestructiveDatabaseGuard(featureHook($root), $tool, reseed($root), [$variable => 'x']);

    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toContain('devenv shell');
})->with('tools')->with(['DB_URL', 'APP_CONFIG_CACHE', 'APP_ENV']);

test('a feature worktree allows a reseed when .env only mentions those settings in a comment or the session leaves them empty', function (string $tool): void {
    $root = guardedWorktreeRoot();
    File::append($root.'/.env', "# DB_URL=mysql://root@127.0.0.1/acme_main\n# APP_CONFIG_CACHE=/tmp/config.php\n");

    expect(runDestructiveDatabaseGuard(featureHook($root), $tool, reseed($root), ['DB_URL' => '', 'APP_CONFIG_CACHE' => '', 'APP_ENV' => ''])->exitCode())->toBe(0);
})->with('tools');

test('a feature worktree allows a reseed without a .env', function (string $tool): void {
    $root = guardedWorktreeRoot();

    File::delete($root.'/.env');

    expect(runDestructiveDatabaseGuard(featureHook($root), $tool, reseed($root))->exitCode())->toBe(0);
})->with('tools');

test('a reserved checkout gets no reseed form at all', function (string $tool, string $dbName, string $testDbName): void {
    $root = guardedWorktreeRoot();

    $hook = destructiveDatabaseGuard($dbName, $root, $testDbName)['command'];
    $result = runDestructiveDatabaseGuard($hook, $tool, reseed($root, database: $dbName));

    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->not->toContain('--seeder=DemoSeeder')
        ->and($hook)->not->toContain('artisan migrate:fresh --seed');
})->with('tools')->with([
    'the main checkout, or a linked worktree named main' => ['acme_main', 'acme_main_test'],
    'CI' => ['acme_main', 'acme_test'],
    'develop' => ['acme_develop', 'acme_develop_test'],
    'a worktree named main-x' => ['acme_main_x', 'acme_main_x_test'],
    'e2e' => ['acme_e2e', 'acme_e2e_test'],
]);

test('a reserved checkout may still wipe its test database', function (string $tool): void {
    $root = guardedWorktreeRoot();

    $hook = destructiveDatabaseGuard('acme_main', $root, 'acme_main_test')['command'];

    expect(runDestructiveDatabaseGuard($hook, $tool, 'DB_DATABASE=acme_main_test APP_ENV=testing php artisan migrate:fresh --force')->exitCode())->toBe(0);
})->with('tools');

test('a worktree root that is not absolute or holds other than letters, digits, dots, underscores, dashes and slashes does not evaluate', function (string $root): void {
    $result = evaluateDestructiveDatabaseGuard(FEATURE_DATABASE, $root, FEATURE_DATABASE.'_test');

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('Move the worktree');
})->with([
    'a single quote' => ["/tmp/a'b"],
    'a double quote' => ['/tmp/a"b'],
    'a space' => ['/tmp/a b'],
    'a relative path' => ['tmp/a'],
]);
