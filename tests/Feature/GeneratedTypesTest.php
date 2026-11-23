<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/*
 * resources/js/typefinder is committed (config/typefinder.php). This regenerates
 * it into a temporary folder against the migrated Postgres test schema and
 * fails when generation warns (a model, request or resource was skipped and
 * silently has no type) or when the committed files differ, showing the diff
 * of the first stale file. Fix drift with `php artisan typefinder:generate`
 * (Vite's dev server and build also regenerate it) and commit the result.
 */
test('the committed Typefinder types match a fresh generation without warnings', function (): void {
    $committed = resource_path('js/typefinder');
    $fresh = sys_get_temp_dir().'/typefinder-test-'.uniqid('', true);
    config()->set('typefinder.output_path', $fresh);

    try {
        Artisan::call('typefinder:generate', ['--strict' => true, '--json' => true]);

        /** @var array{warnings: list<string>, errors: list<string>} $report */
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($report['errors'])->toBe([])
            ->and($report['warnings'])->toBe([], 'Generation warned; a skipped class has no type.');

        $files = static fn (string $root): array => collect(File::allFiles($root))
            ->mapWithKeys(fn (SplFileInfo $file): array => [substr($file->getPathname(), strlen($root) + 1) => (string) file_get_contents($file->getPathname())])
            ->sortKeys()
            ->all();
        $expected = $files($fresh);
        $actual = $files($committed);

        $stale = collect(array_keys($expected + $actual))
            ->filter(fn (string $path): bool => ($expected[$path] ?? null) !== ($actual[$path] ?? null))
            ->values()
            ->all();
        $hint = 'Stale resources/js/typefinder; run `php artisan typefinder:generate` and commit it. Stale: '.implode(', ', $stale);

        if ($stale !== []) {
            expect($actual[$stale[0]] ?? '(missing)')->toBe($expected[$stale[0]] ?? '(missing)', $hint);
        }

        expect($stale)->toBe([]);
    } finally {
        File::deleteDirectory($fresh);
    }
});
