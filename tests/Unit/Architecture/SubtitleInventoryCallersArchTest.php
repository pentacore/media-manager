<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Every caller has moved off the temporary SubtitleInventoryService facade to
 * the collaborator it needs; only the facade's own file may still name it
 * until Batch 4e deletes it.
 */
test('no application file reaches the subtitle inventory facade', function (): void {
    $root = dirname(__DIR__, 3);
    $offenders = [];

    foreach (Finder::create()->files()->in($root.'/app')->name('*.php') as $finder) {
        if ($finder->getRelativePathname() === 'Services/Bazarr/SubtitleInventoryService.php') {
            continue;
        }

        if (str_contains($finder->getContents(), 'SubtitleInventoryService')) {
            $offenders[] = $finder->getRelativePathname();
        }
    }

    sort($offenders);

    expect($offenders)->toBe([]);
});
