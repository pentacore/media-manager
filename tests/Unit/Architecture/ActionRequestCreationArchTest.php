<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

test('ActionRequest::create is only called by the orchestrator and demo tooling', function (): void {
    $allowed = [
        'app/Services/Actions/ActionOrchestrator.php',
        'app/Console/Commands/DemoFakeActions.php',
    ];

    $offenders = collect(Finder::create()->files()->in(dirname(__DIR__, 3).'/app')->name('*.php'))
        ->filter(fn (SplFileInfo $file): bool => preg_match('/ActionRequest::(create|query\(\)->create|forceCreate)\(/', (string) file_get_contents($file->getPathname())) === 1)
        ->map(fn (SplFileInfo $file): string => str_replace(dirname(__DIR__, 3).'/', '', $file->getPathname()))
        ->reject(fn (string $path): bool => in_array($path, $allowed, true))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
