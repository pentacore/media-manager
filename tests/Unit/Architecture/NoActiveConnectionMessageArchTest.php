<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * The standard refusal for a missing connection is worded once, in
 * Controller::noActiveConnectionMessage(); a controller that restates
 * "No active Sonarr connection configured." drifts from it (and skips
 * __()). Pages with their own wording ("… is configured.") are not matched.
 */
test('no controller restates the standard no-active-connection sentence', function (): void {
    $root = dirname(__DIR__, 3);
    $offenders = [];

    foreach (Finder::create()->files()->in($root.'/app/Http/Controllers')->name('*.php') as $finder) {
        if (preg_match('/No active [A-Z][A-Za-z]+ connection configured\./', $finder->getContents()) === 1) {
            $offenders[] = $finder->getRelativePathname();
        }
    }

    sort($offenders);

    expect($offenders)->toBe([]);
});
