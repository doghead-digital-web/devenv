<?php

declare(strict_types=1);

namespace Tests\Laravel;

use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Assert;

pest()->group('wiring');

/**
 * @return array<string, array{hookType: string, matcher: string, command: string}>
 */
function fixtureAppHooks(): array
{
    /** @var array<string, array{hookType: string, matcher: string, command: string}>|null $hooks */
    static $hooks = null;

    if ($hooks !== null) {
        return $hooks;
    }

    $result = Process::path(module_path('tests/fixtures/app'))
        ->env(['CI' => false])
        ->timeout(600)
        ->run(['devenv', 'eval', 'claude.code.hooks']);

    Assert::assertTrue($result->successful(), $result->errorOutput());

    /** @var array{'claude.code.hooks': array<string, array{hookType: string, matcher: string, command: string}>} $evaluated */
    $evaluated = json_decode($result->output(), true);

    return $hooks = $evaluated['claude.code.hooks'];
}

/**
 * @param  list<string>  $arguments
 */
function guardCommand(string $file, array $arguments, string $attribute = 'command'): string
{
    $result = Process::path(module_path())->run([
        'nix-instantiate', '--eval', '--strict', '--json', $file, ...$arguments,
    ]);

    Assert::assertTrue($result->successful(), $result->errorOutput());

    /** @var array<string, string> $guard */
    $guard = json_decode($result->output(), true);

    return $guard[$attribute];
}

function withToolNames(string $command): string
{
    return (string) preg_replace('#/nix/store/[0-9a-z]{32}-[^/\s]+/bin/([A-Za-z0-9_-]+)#', '$1', $command);
}

test('the module gives an app every shared hook', function (): void {
    expect(array_keys(fixtureAppHooks()))->toEqualCanonicalizing([
        'git-hooks-run',
        'block-destructive-database',
        'block-teardown',
        'block-process-kill',
        'ask-before-publishing',
        'builder-ci-gate',
        'block-quality-gate-edits',
        'block-quality-gate-shell-writes',
    ]);
});

test('each shared hook runs on its event and watches its tools', function (string $hook, string $hookType, string $matcher): void {
    expect(fixtureAppHooks()[$hook])
        ->hookType->toBe($hookType)
        ->matcher->toBe($matcher);
})->with([
    ['block-destructive-database', 'PreToolUse', '^(Bash|Monitor)$'],
    ['block-teardown', 'PreToolUse', '^(Bash|Monitor)$'],
    ['block-process-kill', 'PreToolUse', '^(Bash|Monitor)$'],
    ['ask-before-publishing', 'PreToolUse', '^(Bash|Monitor)$'],
    ['block-quality-gate-shell-writes', 'PreToolUse', '^(Bash|Monitor)$'],
    ['block-quality-gate-edits', 'PreToolUse', '^(Edit|Write|MultiEdit|NotebookEdit)$'],
    ['git-hooks-run', 'PostToolUse', '^(Edit|Write|MultiEdit|NotebookEdit)$'],
    ['builder-ci-gate', 'TaskCompleted', ''],
]);

test('pint never rewrites an installed dependency, and still formats app code', function (string $file, bool $excluded): void {
    $result = Process::path(module_path('tests/fixtures/app'))
        ->env(['CI' => false])
        ->timeout(600)
        ->run(['devenv', 'eval', 'git-hooks.hooks.pint.excludes']);

    Assert::assertTrue($result->successful(), $result->errorOutput());

    /** @var array{'git-hooks.hooks.pint.excludes': list<string>} $evaluated */
    $evaluated = json_decode($result->output(), true);
    $matches = array_filter($evaluated['git-hooks.hooks.pint.excludes'], fn (string $pattern): bool => preg_match('#'.$pattern.'#', $file) === 1);

    expect($matches !== [])->toBe($excluded);
})->with([
    ['vendor/laravel/framework/src/Illuminate/Support/Str.php', true],
    ['node_modules/some-package/stub.php', true],
    ['app/Models/User.php', false],
    ['tests/Feature/vendor/ExampleTest.php', false],
]);

test('the publishing hook runs a /nix/store copy of ask-before-publishing.sh', function (): void {
    expect(fixtureAppHooks()['ask-before-publishing']['command'])->toMatch('#^/nix/store/[0-9a-z]{32}-ask-before-publishing$#');
});

test('the word guards run the commands their guard files build', function (string $hook, string $file): void {
    expect(withToolNames(fixtureAppHooks()[$hook]['command']))
        ->toBe(guardCommand($file, ['--argstr', 'grep', 'grep', '--argstr', 'jq', 'jq']));
})->with([
    ['block-teardown', 'laravel/guards/teardown.nix'],
    ['block-process-kill', 'laravel/guards/process-kill.nix'],
]);

test('claude-yolo starts Claude with no hooks and no permission prompts', function (): void {
    $result = Process::path(module_path('tests/fixtures/app'))
        ->env(['CI' => false])
        ->timeout(600)
        ->run(['devenv', 'eval', 'scripts.claude-yolo.exec']);

    expect($result->successful())->toBeTrue($result->errorOutput());

    /** @var array{'scripts.claude-yolo.exec': string} $evaluated */
    $evaluated = json_decode($result->output(), true);

    expect($evaluated['scripts.claude-yolo.exec'])
        ->toBe("exec claude --dangerously-skip-permissions --settings '{\"disableAllHooks\":true}' \"\$@\"\n");
});

test("the destructive database guard runs the command its guard file builds for the app's own worktree", function (): void {
    expect(withToolNames(fixtureAppHooks()['block-destructive-database']['command']))
        ->toBe(guardCommand('laravel/guards/destructive-database.nix', [
            '--argstr', 'jq', 'jq',
            '--argstr', 'grep', 'grep',
            '--argstr', 'appName', 'fixture',
            '--argstr', 'dbName', 'fixture_app',
            '--argstr', 'testDbName', 'fixture_app_test',
            '--argstr', 'worktreeRoot', (string) realpath(module_path('tests/fixtures/app')),
        ]));
});
