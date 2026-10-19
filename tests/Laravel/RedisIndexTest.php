<?php

declare(strict_types=1);

namespace Tests\Laravel;

use function Tests\workspace;
use function Tests\worktreeRoot;

test('a worktree gets the Redis index the slot hash picks from 1 to 127', function (string $appName, string $worktree, bool $mainCheckout): void {
    $hash = (int) hexdec(mb_substr(hash('sha256', $appName.'/'.($mainCheckout ? 'main' : $worktree)), 0, 4));

    expect(workspace($appName, worktreeRoot($worktree, $mainCheckout))['redisDbIndex'])->toBe($hash % 127 + 1);
})->with([
    'the main checkout' => ['jshq', 'jshq', true],
    'the same worktree name in another app' => ['harvst', 'harvst', true],
    'a ticket worktree' => ['jshq', 'jsh-12-quotes', false],
    'a worktree that hashes past 63' => ['jshq', 'hrvst-225-strain-filter', false],
    'a short name' => ['harvst', 'b2', false],
]);

test('the Redis index reaches past the 63 a 64-database Redis allowed', function (): void {
    expect(workspace('jshq', worktreeRoot('jsh-12-quotes'))['redisDbIndex'])->toBeGreaterThan(64)
        ->and(workspace('harvst', worktreeRoot('jsh-12-quotes'))['redisDbIndex'])->toBeLessThanOrEqual(127);
});
