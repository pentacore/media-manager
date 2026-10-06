<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Every Sonarr/Radarr client in the app is built through
 * App\Services\Arr\ArrConnections (client(), sonarr(), radarr()) — the one
 * place that owns `new SonarrClient`/`new RadarrClient` construction (see
 * app.md and the ArrConnections docblock). No other file may construct one
 * directly.
 */
test('only ArrConnections constructs a SonarrClient or RadarrClient', function (): void {
    $root = dirname(__DIR__, 3);
    $allowed = 'Services/Arr/ArrConnections.php';
    $offenders = [];

    foreach (Finder::create()->files()->in($root.'/app')->name('*.php') as $finder) {
        $relativePath = $finder->getRelativePathname();

        if ($relativePath === $allowed) {
            continue;
        }

        if (preg_match('/new\s+(Sonarr|Radarr)Client\(/', $finder->getContents()) === 1) {
            $offenders[] = $relativePath;
        }
    }

    sort($offenders);

    expect($offenders)->toBe([]);
});
