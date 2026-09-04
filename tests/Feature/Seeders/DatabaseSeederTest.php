<?php

declare(strict_types=1);

use App\Models\ActionTypeConfig;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('non-local seeding does not create a fixed admin account', function (): void {
    $this->app['env'] = 'staging';

    $this->seed(DatabaseSeeder::class);

    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
    expect(ActionTypeConfig::count())->toBe(28);
});
