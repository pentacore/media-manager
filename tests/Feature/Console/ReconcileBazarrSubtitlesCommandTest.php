<?php

declare(strict_types=1);

use App\Jobs\ReconcileBazarrConnection;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Queue;

test('the command dispatches one job for each active Bazarr connection', function (): void {
    $first = ServiceConnection::factory()->bazarr()->create();
    $second = ServiceConnection::factory()->bazarr()->create();
    ServiceConnection::factory()->bazarr()->create(['is_active' => false]);
    ServiceConnection::factory()->sonarr()->create();
    Queue::fake([ReconcileBazarrConnection::class]);

    $this->artisan('bazarr:reconcile')->assertSuccessful();

    Queue::assertPushed(ReconcileBazarrConnection::class, 2);
    Queue::assertPushed(fn (ReconcileBazarrConnection $reconcileBazarrConnection): bool => $reconcileBazarrConnection->connectionId === $first->id);
    Queue::assertPushed(fn (ReconcileBazarrConnection $reconcileBazarrConnection): bool => $reconcileBazarrConnection->connectionId === $second->id);
});

test('the connection option scopes discovery to one active Bazarr connection', function (): void {
    $selected = ServiceConnection::factory()->bazarr()->create();
    ServiceConnection::factory()->bazarr()->create();
    Queue::fake([ReconcileBazarrConnection::class]);

    $this->artisan('bazarr:reconcile', ['--connection' => $selected->id])->assertSuccessful();

    Queue::assertPushed(ReconcileBazarrConnection::class, 1);
    Queue::assertPushed(fn (ReconcileBazarrConnection $reconcileBazarrConnection): bool => $reconcileBazarrConnection->connectionId === $selected->id);
});

test('the scheduler registers bounded Bazarr discovery every five minutes', function (): void {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('bazarr:reconcile')
        ->expectsOutputToContain('*/5 * * * *')
        ->assertSuccessful();
});
