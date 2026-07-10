<?php

declare(strict_types=1);

use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    $this->connection = ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'test-api-key',
    ]);
});

test('admin can link a second emby account to a user', function (): void {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    EmbyUserLink::factory()->create([
        'user_id' => $target->id,
        'emby_user_id' => 'emby-user-first',
        'emby_username' => 'first',
    ]);

    Http::fake([
        'emby.local:8096/Users' => Http::response([
            ['Id' => 'emby-user-second', 'Name' => 'second'],
        ]),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.users.link-emby', $target), [
            'emby_username' => 'second',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('emby_user_links', [
        'user_id' => $target->id,
        'emby_user_id' => 'emby-user-second',
        'emby_username' => 'second',
    ]);
    expect(EmbyUserLink::where('user_id', $target->id)->count())->toBe(2);
});
