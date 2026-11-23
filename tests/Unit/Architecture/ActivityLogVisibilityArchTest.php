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

/**
 * Lines that read ActivityLog rows without visibleTo(), keyed by file. Each
 * entry is one exact line, so a new unscoped read in the same file still
 * fails the test.
 *
 * @return array<string, list<string>>
 */
function activityLogVisibilityReviewedLines(): array
{
    return [
        // Admin-only page (role:admin) listing one webhook event's rows;
        // audit rows never carry a webhook_event_id.
        'app/Http/Controllers/Admin/WebhookLogController.php' => [
            "->withCount(['activityLogs', 'actionRequests']);",
            "'activityLogs' => fn (\$query) => \$query->latest(),",
            '\'activity\' => $webhookEvent->activityLogs->map(fn (ActivityLog $activityLog): array => [',
        ],
    ];
}

/**
 * Reader lines (an ActivityLog:: query or an activityLogs relation use) whose
 * own chain (the lines that continue it with `->`) has no visibleTo().
 *
 * @param  list<string>  $reviewedLines
 * @return list<string>
 */
function activityLogVisibilityOffenders(string $path, string $code, array $reviewedLines): array
{
    $lines = array_map(trim(...), explode("\n", $code));
    $offenders = [];

    foreach ($lines as $index => $line) {
        $isReader = preg_match('/ActivityLog::(?!create\(|class\b)[A-Za-z]+\(/', $line) === 1 || str_contains($line, 'activityLogs');

        if (! $isReader || in_array($line, $reviewedLines, true)) {
            continue;
        }

        $chain = $line;

        for ($next = $index + 1; isset($lines[$next]) && str_starts_with($lines[$next], '->'); $next++) {
            $chain .= ' '.$lines[$next];
        }

        if (! str_contains($chain, '->visibleTo(')) {
            $offenders[] = sprintf('%s: %s', $path, $line);
        }
    }

    return $offenders;
}

test('the visibility scanner flags an unscoped read even in a file that also has a scoped one', function (): void {
    $code = <<<'PHP'
        $mine = ActivityLog::query()
            ->visibleTo($user)
            ->get();
        $all = ActivityLog::query()
            ->latest()
            ->get();
        $count = ActivityLog::count();
        ActivityLog::create(['action' => 'x']);
        $reviewed = $event->activityLogs;
        PHP;

    expect(activityLogVisibilityOffenders('app/Example.php', $code, ['$reviewed = $event->activityLogs;']))->toBe([
        'app/Example.php: $all = ActivityLog::query()',
        'app/Example.php: $count = ActivityLog::count();',
    ]);
});

test('every ActivityLog reader in app/ applies visibleTo() or is a reviewed line', function (): void {
    $reviewed = activityLogVisibilityReviewedLines();
    $files = activityLogVisibilityAppFiles();
    $offenders = [];
    $scoped = [];

    foreach ($files as $path => $code) {
        $offenders = [...$offenders, ...activityLogVisibilityOffenders($path, $code, $reviewed[$path] ?? [])];

        if (preg_match('/ActivityLog::(?!create\(|class\b)[A-Za-z]+\(/', $code) === 1 && str_contains($code, '->visibleTo(')) {
            $scoped[] = $path;
        }
    }

    // The scanner must actually find the known readers, or it proves nothing.
    expect($scoped)->toContain(
        'app/Http/Controllers/ActivityLogController.php',
        'app/Http/Controllers/DashboardController.php',
        'app/Ai/Tools/System/QueryActivityTool.php',
    )
        ->and($offenders)->toBe([]);

    // Every reviewed line must still exist, or the exception is stale.
    foreach ($reviewed as $path => $lines) {
        foreach ($lines as $line) {
            expect($files[$path])->toContain($line);
        }
    }
});
