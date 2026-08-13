<?php

declare(strict_types=1);

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

    foreach ([
        'whisparr_add_item', 'whisparr_delete_item', 'whisparr_monitor_item', 'whisparr_set_quality_profile',
        'replace_media_file', 'bazarr_download_best',
    ] as $type) {
        expect(ActionTypeConfig::query()->where('type', $type)->exists())
            ->toBeTrue("missing seeded action type: {$type}");
    }
});

test('seeder tolerates a concurrent replica creating the same type', function (): void {
    ActionTypeConfig::creating(function (ActionTypeConfig $model): bool {
        if ($model->type === 'whisparr_add_item' && ActionTypeConfig::query()->where('type', 'whisparr_add_item')->doesntExist()) {
            ActionTypeConfig::factory()->createQuietly([
                'type' => 'whisparr_add_item',
                'label' => 'replica label',
                'description' => 'replica description',
                'requires_approval' => false,
                'is_enabled' => true,
            ]);
        }

        return true;
    });

    $this->seed(ActionTypeConfigSeeder::class);

    $actionTypeConfig = ActionTypeConfig::query()->where('type', 'whisparr_add_item')->sole();
    expect($actionTypeConfig->requires_approval)->toBeFalse()
        ->and($actionTypeConfig->label)->toBe('Add item to Whisparr');
});
