<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Every app/ file's code without model property docs and relation
 * definitions, keyed by path relative to the repo root.
 *
 * @return array<string, string>
 */
function activityLogVisibilityAppFiles(): array
{
    $root = dirname(__DIR__, 3);
    $files = [];

    foreach (Finder::create()->files()->in($root.'/app')->name('*.php') as $finder) {
        $lines = array_filter(
            explode("\n", (string) file_get_contents($finder->getPathname())),
            static fn (string $line): bool => ! str_contains($line, '@property') && ! str_contains($line, 'function activityLogs('),
        );

        $files[str_replace($root.'/', '', $finder->getPathname())] = implode("\n", $lines);
    }

    return $files;
}

test('every ActivityLog reader in app/ applies visibleTo() or is a reviewed exception', function (): void {
    $reviewed = [
        // Admin-only page (role:admin) listing one webhook event's rows;
        // audit rows never carry a webhook_event_id.
        'app/Http/Controllers/Admin/WebhookLogController.php',
    ];

    $readers = collect(activityLogVisibilityAppFiles())
        ->filter(fn (string $code): bool => preg_match('/ActivityLog::(?!create\(|class\b)[A-Za-z]+\(/', $code) === 1
            || str_contains($code, 'activityLogs'));

    // The scanner must actually find the known readers, or it proves nothing.
    expect($readers->keys()->all())->toContain(
        'app/Http/Controllers/ActivityLogController.php',
        'app/Http/Controllers/DashboardController.php',
        'app/Ai/Tools/System/QueryActivityTool.php',
    );

    $unscoped = $readers
        ->reject(fn (string $code, string $path): bool => in_array($path, $reviewed, true) || str_contains($code, '->visibleTo('))
        ->keys()
        ->values()
        ->all();

    expect($unscoped)->toBe([]);
});
