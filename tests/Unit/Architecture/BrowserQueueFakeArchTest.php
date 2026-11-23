<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

test('browser tests fake extra jobs through browserQueueFake(), never a bare Queue::fake([...])', function (): void {
    $root = dirname(__DIR__, 3);

    $offenders = collect(Finder::create()->files()->in($root.'/tests/Browser')->name('*.php'))
        ->filter(fn (SplFileInfo $file): bool => preg_match('/Queue::fake\(\s*\[/', (string) file_get_contents($file->getPathname())) === 1)
        ->map(fn (SplFileInfo $file): string => str_replace($root.'/', '', $file->getPathname()))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
