<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * A missing connection is an ordinary state: look it up with
 * ServiceConnection::findActive() and branch on null. resolveActive() is for
 * callers where a missing connection is an error (executors, AI tools), so
 * no class may catch its ModelNotFoundException to mean "not configured".
 * The pinned lookups' own
 * `catch (InvalidArgumentException|ModelNotFoundException)` sit in files
 * that no longer call resolveActive().
 */
test('no class catches the exception of resolveActive() to mean "not configured"', function (): void {
    $root = dirname(__DIR__, 3);
    $offenders = [];

    foreach (Finder::create()->files()->in($root.'/app')->name('*.php') as $finder) {
        $code = $finder->getContents();

        if (str_contains($code, 'resolveActive(') && preg_match('/catch\s*\([^)]*ModelNotFoundException/', $code) === 1) {
            $offenders[] = $finder->getRelativePathname();
        }
    }

    sort($offenders);

    expect($offenders)->toBe([]);
});
