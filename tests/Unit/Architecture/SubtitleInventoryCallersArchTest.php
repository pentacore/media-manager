<?php

declare(strict_types=1);

/**
 * Callers already moved off the temporary SubtitleInventoryService facade to
 * the collaborator they need. Extended task by task during Batch 4e.
 *
 * @return list<string>
 */
function subtitleInventoryMigratedFiles(): array
{
    return [
        'app/Http/Controllers/Bazarr/OverviewController.php',
        'app/Http/Controllers/Bazarr/LibraryController.php',
        'app/Http/Controllers/Bazarr/MissingController.php',
        'app/Http/Controllers/Bazarr/HistoryController.php',
        'app/Http/Controllers/Bazarr/OperationController.php',
        'app/Http/Controllers/Bazarr/SearchController.php',
        'app/Ai/Tools/Bazarr/InspectSubtitleTool.php',
        'app/Ai/Tools/Bazarr/SearchSubtitlesTool.php',
        'app/Ai/Tools/Bazarr/RequestSubtitleOperationTool.php',
    ];
}

test('migrated callers no longer reach the subtitle inventory facade', function (): void {
    $root = dirname(__DIR__, 3);
    $offenders = array_values(array_filter(
        subtitleInventoryMigratedFiles(),
        static fn (string $path): bool => str_contains((string) file_get_contents($root.'/'.$path), 'SubtitleInventoryService'),
    ));

    expect($offenders)->toBe([]);
});
