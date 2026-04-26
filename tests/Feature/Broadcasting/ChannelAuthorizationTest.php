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

$memberOnlyChannels = ['private-services', 'private-members.actions'];
$openToAuthChannels = ['private-emby.activity', 'private-dashboard', 'private-activity'];

test('members can join member-only channels', function () use ($memberOnlyChannels): void {
    $user = User::factory()->member()->create();

    foreach ($memberOnlyChannels as $memberOnlyChannel) {
        $this->actingAs($user)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.1234567',
                'channel_name' => $memberOnlyChannel,
            ])
            ->assertOk();
    }
});

test('admins can join member-only channels', function () use ($memberOnlyChannels): void {
    $user = User::factory()->admin()->create();

    foreach ($memberOnlyChannels as $memberOnlyChannel) {
        $this->actingAs($user)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.1234567',
                'channel_name' => $memberOnlyChannel,
            ])
            ->assertOk();
    }
});

test('viewers cannot join member-only channels', function () use ($memberOnlyChannels): void {
    $viewer = User::factory()->create(); // default role is Viewer

    foreach ($memberOnlyChannels as $memberOnlyChannel) {
        $this->actingAs($viewer)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.1234567',
                'channel_name' => $memberOnlyChannel,
            ])
            ->assertForbidden();
    }
});

test('all authenticated users can join the shared dashboard/activity/emby.activity channels', function () use ($openToAuthChannels): void {
    foreach (['viewer', 'member', 'admin'] as $rolename) {
        $user = match ($rolename) {
            'admin' => User::factory()->admin()->create(),
            'member' => User::factory()->member()->create(),
            default => User::factory()->create(),
        };

        foreach ($openToAuthChannels as $openToAuthChannel) {
            $this->actingAs($user)
                ->post('/broadcasting/auth', [
                    'socket_id' => '1234.1234567',
                    'channel_name' => $openToAuthChannel,
                ])
                ->assertOk();
        }
    }
});

test('viewer can still join their own private user channel', function (): void {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->post('/broadcasting/auth', [
            'socket_id' => '1234.1234567',
            'channel_name' => 'private-App.Models.User.'.$viewer->id,
        ])
        ->assertOk();
});
