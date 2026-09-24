<?php

declare(strict_types=1);

use App\Jobs\ExecuteActionRequest;
use App\Models\ActionTypeConfig;
use Database\Seeders\ActionTypeConfigSeeder;

test('seeder creates missing action types with seeded defaults', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);

    $whisparrAdd = ActionTypeConfig::query()->where('type', 'whisparr_add_item')->first();
    expect($whisparrAdd)->not->toBeNull()
        ->and($whisparrAdd->requires_approval)->toBeTrue()
        ->and($whisparrAdd->is_enabled)->toBeTrue();

    expect(ActionTypeConfig::query()->where('type', 'whisparr_delete_item')->value('requires_approval'))->toBeTrue()
        ->and(ActionTypeConfig::query()->where('type', 'whisparr_monitor_item')->value('requires_approval'))->toBeFalse()
        ->and(ActionTypeConfig::query()->where('type', 'whisparr_set_quality_profile')->value('requires_approval'))->toBeFalse();
});

test('seeder preserves admin-owned toggles on existing rows but refreshes copy', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);

    $actionTypeConfig = ActionTypeConfig::query()->where('type', 'delete_series')->firstOrFail();
    $actionTypeConfig->update([
        'requires_approval' => false, // admin flipped it
        'is_enabled' => false,        // admin disabled it
        'label' => 'stale label',
    ]);

    $this->seed(ActionTypeConfigSeeder::class);

    $actionTypeConfig->refresh();
    expect($actionTypeConfig->requires_approval)->toBeFalse()
        ->and($actionTypeConfig->is_enabled)->toBeFalse()
        ->and($actionTypeConfig->label)->toBe('Delete series from Sonarr');
});

test('every action type mapped by the executor is seeded', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);

    $seeded = ActionTypeConfig::query()->pluck('type')->all();

    // Empty on success; otherwise lists the executor-mapped types missing from the seeder.
    expect(array_values(array_diff(array_keys(ExecuteActionRequest::EXECUTORS), $seeded)))->toBe([]);
});

test('every seeded action type has an executor', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);

    $seeded = ActionTypeConfig::query()->pluck('type')->all();

    // Empty on success; otherwise lists seeded types the queue would fail with no_executor.
    expect(array_values(array_diff($seeded, array_keys(ExecuteActionRequest::EXECUTORS))))->toBe([]);
});
