<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

it('schedules a daily telescope prune when the package is installed', function (): void {
    $events = collect(resolve(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'telescope:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->command)->toContain('--hours=48')
        ->and($events->first()->expression)->toBe('0 0 * * *');
});
