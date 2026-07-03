<?php

declare(strict_types=1);

use App\Models\ServiceConnection;

test('webhook:simulate dry-run finds the Whisparr Download fixture', function (): void {
    ServiceConnection::factory()->whisparr()->create([
        'url' => 'http://whisparr.local:6969', 'is_active' => true,
    ]);

    $this->artisan('webhook:simulate', ['service' => 'whisparr', 'event' => 'Download', '--dry-run' => true])
        ->assertSuccessful();
});
