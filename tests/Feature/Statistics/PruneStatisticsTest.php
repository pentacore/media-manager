<?php

declare(strict_types=1);

use App\Models\ServiceMetric;
use App\Models\StatRollup;

it('prunes hour rollups past retention but keeps day rows', function (): void {
    StatRollup::factory()->hour()->create(['bucket' => now()->subDays(100)->startOfHour()]);
    StatRollup::factory()->hour()->create(['bucket' => now()->subDays(5)->startOfHour()]);
    StatRollup::factory()->create(['bucket' => now()->subDays(400)->startOfDay()]); // day row

    $this->artisan('statistics:prune')->assertSuccessful();

    expect(StatRollup::query()->where('period', 'hour')->count())->toBe(1)
        ->and(StatRollup::query()->where('period', 'day')->count())->toBe(1);
});

it('prunes raw service metrics when enabled and skips when disabled', function (): void {
    ServiceMetric::factory()->create(['recorded_at' => now()->subDays(100)]);
    ServiceMetric::factory()->create(['recorded_at' => now()->subDays(10)]);

    $this->artisan('statistics:prune')->assertSuccessful();
    expect(ServiceMetric::query()->count())->toBe(1);

    config()->set('mediamanager.statistics.prune_service_metrics', false);
    ServiceMetric::factory()->create(['recorded_at' => now()->subDays(100)]);

    $this->artisan('statistics:prune')->assertSuccessful();
    expect(ServiceMetric::query()->count())->toBe(2);
});
