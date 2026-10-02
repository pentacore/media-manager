<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function scheduleOverlapCadenceMinutes(Event $event): int
{
    $from = CarbonImmutable::parse('2026-01-05 12:00:00');

    return (int) CarbonImmutable::instance($event->nextRunDate($from))
        ->diffInMinutes(CarbonImmutable::instance($event->nextRunDate($from, 1)));
}

function scheduleOverlapEventLabel(Event $event): string
{
    return (string) ($event->command ?? $event->description);
}

test('every overlap-guarded task sets an explicit lock expiry sized to its cadence', function (): void {
    $violations = collect(resolve(Schedule::class)->events())
        ->filter(fn (Event $event): bool => $event->withoutOverlapping)
        ->reject(fn (Event $event): bool => $event->expiresAt >= 5
            && $event->expiresAt <= max(10, scheduleOverlapCadenceMinutes($event) - 5))
        ->map(fn (Event $event): string => sprintf('%s (lock expires after %d min)', scheduleOverlapEventLabel($event), $event->expiresAt))
        ->values()
        ->all();

    expect($violations)->toBe([]);
});

test('long-running tasks keep enough lock time to finish', function (string $needle, int $minutes): void {
    $event = collect(resolve(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains(scheduleOverlapEventLabel($event), $needle));

    expect($event)->not->toBeNull()
        ->and($event->expiresAt)->toBe($minutes);
})->with([
    'weekly price refresh' => ['ai:refresh-prices --scheduled', 120],
    'monthly price verification' => ['ai:refresh-prices --verify --scheduled', 120],
    'nightly retention prune' => ['model:prune', 180],
    'statistics prune' => ['statistics:prune', 180],
]);

test('the stuck action reconcile runs every fifteen minutes', function (): void {
    $event = collect(resolve(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains(scheduleOverlapEventLabel($event), 'actions:reconcile-stuck'));

    expect($event)->not->toBeNull()
        ->and(scheduleOverlapCadenceMinutes($event))->toBe(15)
        ->and($event->expiresAt)->toBe(10);
});
