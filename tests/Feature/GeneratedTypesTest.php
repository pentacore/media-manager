<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/*
 * resources/js/typefinder is committed (config/typefinder.php). This regenerates
 * it into a temporary folder against the migrated Postgres test schema and
 * fails when the committed files differ, or when generation warns: a warning
 * means a model, request or resource was skipped and silently has no type.
 * Fix drift with `php artisan typefinder:generate` (Vite's dev server and
 * build also regenerate it) and commit the result.
 */
test('the committed Typefinder types match a fresh generation without warnings', function (): void {
    $exitCode = Artisan::call('typefinder:generate', ['--check' => true, '--strict' => true, '--json' => true]);

    /** @var array{success: bool, warnings: list<string>, errors: list<string>} $report */
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['errors'])->toBe([])
        ->and($report['warnings'])->toBe([], 'Stale or skipped types; run `php artisan typefinder:generate` and commit resources/js/typefinder.')
        ->and($exitCode)->toBe(0);
});
