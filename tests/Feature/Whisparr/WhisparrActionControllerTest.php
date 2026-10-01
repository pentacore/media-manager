<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    $this->seed(ActionTypeConfigSeeder::class);

    $this->whisparr = ServiceConnection::factory()->whisparr()->create(['url' => 'http://whisparr.local:6969', 'api_key' => 'k', 'name' => 'Whisparr']);
    Http::fake([
        'whisparr.local:6969/api/v3/movie/11' => Http::response(['id' => 11, 'title' => 'Aurora Scene', 'year' => 2024]),
        'whisparr.local:6969/api/v3/qualityprofile' => Http::response([['id' => 2, 'name' => 'HD']]),
    ]);
    $this->admin = User::factory()->admin()->create(['name' => 'Ada']);
});

test('an admin toggles monitoring through the Action Queue, pinned to the connection', function (): void {
    $this->actingAs($this->admin)
        ->post(route('media.whisparr.actions.monitor'), ['service_connection_id' => $this->whisparr->id, 'item_id' => 11, 'monitored' => false])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.message', 'Monitoring updated.');

    $actionRequest = ActionRequest::query()->where('type', 'whisparr_monitor_item')->sole();
    expect($actionRequest->origin)->toBe('manual')
        ->and($actionRequest->payload)->toEqual(['whisparr_item_id' => 11, 'monitored' => false, 'service_connection_id' => $this->whisparr->id])
        ->and($actionRequest->title)->toBe('Unmonitor item "Aurora Scene (2024)"');
});

test('an admin changes the quality profile', function (): void {
    $this->actingAs($this->admin)
        ->post(route('media.whisparr.actions.quality-profile'), ['service_connection_id' => $this->whisparr->id, 'item_id' => 11, 'quality_profile_id' => 2])
        ->assertSessionHas('inertia.flash_data.toast.message', 'Quality profile updated.');

    expect(ActionRequest::query()->where('type', 'whisparr_set_quality_profile')->sole()->payload)
        ->toEqual(['whisparr_item_id' => 11, 'quality_profile_id' => 2, 'service_connection_id' => $this->whisparr->id]);
});

test('an admin starts a search with the new whisparr_search type', function (): void {
    $this->actingAs($this->admin)
        ->post(route('media.whisparr.actions.search'), ['service_connection_id' => $this->whisparr->id, 'item_id' => 11])
        ->assertSessionHas('inertia.flash_data.toast.message', 'Search started.');

    $actionRequest = ActionRequest::query()->where('type', 'whisparr_search')->sole();
    expect($actionRequest->status)->toBe(ActionRequestStatus::Approved)
        ->and($actionRequest->title)->toBe('Search for item "Aurora Scene (2024)"');
});

test('a delete is queued for approval and audited as whisparr.deleted', function (): void {
    $this->actingAs($this->admin)
        ->post(route('media.whisparr.actions.delete'), ['service_connection_id' => $this->whisparr->id, 'item_id' => 11, 'delete_files' => true])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.type', 'info')
        ->assertSessionHas('inertia.flash_data.toast.message', 'Queued for approval in the Action Queue.');

    $actionRequest = ActionRequest::query()->where('type', 'whisparr_delete_item')->sole();
    expect($actionRequest->payload)->toEqual(['whisparr_item_id' => 11, 'delete_files' => true, 'service_connection_id' => $this->whisparr->id]);

    $activityLog = ActivityLog::query()->where('category', 'audit')->where('action', 'whisparr.deleted')->sole();
    expect($activityLog->subject_id)->toBe($actionRequest->id)
        ->and($activityLog->user_id)->toBe($this->admin->id);
});

test('a delete that needs no approval starts and returns to the library', function (): void {
    ActionTypeConfig::query()->where('type', 'whisparr_delete_item')->update(['requires_approval' => false]);

    $this->actingAs($this->admin)
        ->post(route('media.whisparr.actions.delete'), ['service_connection_id' => $this->whisparr->id, 'item_id' => 11])
        ->assertRedirect(route('media.whisparr.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Deletion started.');
});

test('a pin to a connection that is not Whisparr is refused before anything is filed', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);

    $this->actingAs($this->admin)
        ->postJson(route('media.whisparr.actions.monitor'), ['service_connection_id' => $sonarr->id, 'item_id' => 11, 'monitored' => true])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The selected connection is unavailable or is not a Whisparr connection.');

    expect(ActionRequest::query()->count())->toBe(0);
});

test('a deactivated pinned connection is refused', function (): void {
    $this->whisparr->update(['is_active' => false]);

    $this->actingAs($this->admin)
        ->postJson(route('media.whisparr.actions.search'), ['service_connection_id' => $this->whisparr->id, 'item_id' => 11])
        ->assertUnprocessable();

    expect(ActionRequest::query()->count())->toBe(0);
});

test('members cannot run Whisparr actions', function (string $routeName): void {
    $this->actingAs(User::factory()->member()->create())
        ->post(route($routeName), ['service_connection_id' => $this->whisparr->id, 'item_id' => 11, 'monitored' => true, 'quality_profile_id' => 2])
        ->assertForbidden();

    expect(ActionRequest::query()->count())->toBe(0);
})->with([
    'media.whisparr.actions.monitor',
    'media.whisparr.actions.quality-profile',
    'media.whisparr.actions.search',
    'media.whisparr.actions.delete',
]);
