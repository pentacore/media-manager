<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('it fails when no Emby connection is active, and sends nothing', function (): void {
    ServiceConnection::factory()->emby()->inactive()->create(['url' => 'http://emby.local:8096']);

    $this->artisan('emby:debug-sessions')
        ->expectsOutputToContain('No active Emby connection configured.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});
