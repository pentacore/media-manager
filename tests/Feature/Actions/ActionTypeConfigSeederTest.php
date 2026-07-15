<?php

declare(strict_types=1);

use App\Models\ActionTypeConfig;
use Database\Seeders\ActionTypeConfigSeeder;

test('seeder creates all baseline action types', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);

    $types = ['delete_series', 'delete_movie', 'cleanup_seerr_request', 'emby_library_scan'];

    foreach ($types as $type) {
        $config = ActionTypeConfig::where('type', $type)->first();
        expect($config)->not->toBeNull();
        expect($config->is_enabled)->toBeTrue();
    }
});

test('seeder is idempotent', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);
    $this->seed(ActionTypeConfigSeeder::class);

    expect(ActionTypeConfig::count())->toBe(15);
});

test('seeds the media replacement rule as enabled and requiring approval', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);

    $config = ActionTypeConfig::where('type', 'replace_media_file')->first();

    expect($config)->not->toBeNull()
        ->and($config->is_enabled)->toBeTrue()
        ->and($config->requires_approval)->toBeTrue();
});

test('destructive types default to requires_approval=true', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);

    expect(ActionTypeConfig::where('type', 'delete_series')->first()->requires_approval)->toBeTrue();
    expect(ActionTypeConfig::where('type', 'delete_movie')->first()->requires_approval)->toBeTrue();
});

test('safe types default to requires_approval=false', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);

    expect(ActionTypeConfig::where('type', 'emby_library_scan')->first()->requires_approval)->toBeFalse();
    expect(ActionTypeConfig::where('type', 'cleanup_seerr_request')->first()->requires_approval)->toBeFalse();
});
