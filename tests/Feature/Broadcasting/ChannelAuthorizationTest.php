<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;

beforeEach(function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app-id',
        'broadcasting.connections.reverb.options.host' => 'localhost',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'broadcasting.connections.reverb.options.useTLS' => false,
    ]);

    resolve(BroadcastManager::class)->purge();

    require base_path('routes/channels.php');
});

test('user can authenticate their own private channel', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'socket_id' => '1234.1234567',
            'channel_name' => 'private-App.Models.User.'.$user->id,
        ])
        ->assertOk();
});

test('user cannot authenticate another users private channel', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'socket_id' => '1234.1234567',
            'channel_name' => 'private-App.Models.User.'.$otherUser->id,
        ])
        ->assertForbidden();
});

test('authenticated users can join shared channels', function (): void {
    $user = User::factory()->create();

    $channels = ['private-services', 'private-emby.activity', 'private-dashboard'];

    foreach ($channels as $channel) {
        $this->actingAs($user)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.1234567',
                'channel_name' => $channel,
            ])
            ->assertOk();
    }
});
