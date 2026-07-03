<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;

beforeEach(function (): void {
    config()->set('mediamanager.presence.key', 'presence:users:test-'.getmypid());
    config()->set('mediamanager.presence.heartbeat_ttl', 90);
    Redis::connection()->del(config('mediamanager.presence.key'));
});

afterEach(function (): void {
    Redis::connection()->del(config('mediamanager.presence.key'));
});

test('guests are redirected to login from the heartbeat endpoint', function (): void {
    $this->post(route('heartbeat'))->assertRedirect(route('login'));
});

test('an authenticated user heartbeat records presence and returns 204', function (): void {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('heartbeat'))
        ->assertNoContent();

    $score = Redis::connection()->zscore(
        config('mediamanager.presence.key'),
        (string) $user->id,
    );

    expect($score)->not->toBeNull();
    expect((int) $score)->toBeGreaterThan(Date::now()->getTimestamp());
});

test('repeated heartbeats from the same user keep a single sorted-set entry', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)->post(route('heartbeat'))->assertNoContent();
    $this->actingAs($user)->post(route('heartbeat'))->assertNoContent();
    $this->actingAs($user)->post(route('heartbeat'))->assertNoContent();

    expect((int) Redis::connection()->zcard(config('mediamanager.presence.key')))->toBe(1);
});

test('the first heartbeat in an idle window queues an immediate cache warm', function (): void {
    Artisan::shouldReceive('queue')
        ->with('services:warm-caches')
        ->once();

    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)->post(route('heartbeat'))->assertNoContent();
});

test('subsequent heartbeats while users are already present do not re-queue the warm', function (): void {
    Artisan::shouldReceive('queue')
        ->with('services:warm-caches')
        ->once();

    $alice = User::factory()->create(['email_verified_at' => now()]);
    $bob = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($alice)->post(route('heartbeat'))->assertNoContent();
    $this->actingAs($alice)->post(route('heartbeat'))->assertNoContent();
    $this->actingAs($bob)->post(route('heartbeat'))->assertNoContent();
});
