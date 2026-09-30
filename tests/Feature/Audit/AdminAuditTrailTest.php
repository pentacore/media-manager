<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ActivityLog;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
});

function adminAuditRow(string $action): ActivityLog
{
    $activityLog = ActivityLog::query()->where('action', $action)->sole();

    expect($activityLog->isAudit())->toBeTrue();

    return $activityLog;
}

test('a role change records who changed whom from what to what', function (): void {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->member()->create(['name' => 'Morgan']);

    $this->actingAs($admin)
        ->patch(route('admin.users.update-role', $member), ['role' => 'viewer'])
        ->assertRedirect(route('admin.users.index'));

    $activityLog = adminAuditRow('user.role_changed');

    expect($activityLog->user_id)->toBe($admin->id)
        ->and($activityLog->subject_id)->toBe($member->id)
        ->and($activityLog->description)->toBe("Changed Morgan's role from Member to Viewer.")
        ->and($activityLog->metadata['changes'])->toBe(['role' => ['from' => 'member', 'to' => 'viewer']]);
});

test('an invite and a direct account creation are recorded separately', function (): void {
    Mail::fake();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.users.store'), ['name' => 'Ivy', 'email' => 'ivy@example.com', 'role' => 'member'])->assertRedirect();
    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'Dan', 'email' => 'dan@example.com', 'role' => 'viewer',
        'set_password' => true, 'password' => 'Str0ng-Passw0rd!', 'password_confirmation' => 'Str0ng-Passw0rd!',
    ])->assertRedirect();

    expect(adminAuditRow('invite.created')->description)->toBe('Invited Ivy <ivy@example.com> as Member.')
        ->and(adminAuditRow('user.created')->description)->toBe('Created Dan <dan@example.com> as Viewer.');
});

test('deleting an account is user.deleted and deleting an unaccepted invite is invite.revoked', function (): void {
    $admin = User::factory()->admin()->create();
    $active = User::factory()->member()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
    $invited = User::factory()->create(['name' => 'Ivy', 'email' => 'ivy@example.com', 'password' => null, 'invite_accepted_at' => null]);

    $this->actingAs($admin)->delete(route('admin.users.destroy', $active))->assertRedirect();
    $this->actingAs($admin)->delete(route('admin.users.destroy', $invited))->assertRedirect();

    expect(adminAuditRow('user.deleted')->description)->toBe('Deleted Ana <ana@example.com>.')
        ->and(adminAuditRow('invite.revoked')->description)->toBe('Revoked the invitation for Ivy <ivy@example.com>.');
});

test('connection create, toggle and delete are audited with the connection as subject', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.connections.store'), [
        'type' => 'sonarr', 'name' => 'Main Sonarr', 'url' => 'http://sonarr.local:8989',
        'api_key' => 'created-api-key', 'webhook_token' => 'created-webhook-token',
    ])->assertRedirect(route('admin.connections.index'));

    $serviceConnection = ServiceConnection::query()->sole();
    $activityLog = adminAuditRow('connection.created');

    expect($activityLog->service_connection_id)->toBe($serviceConnection->id)
        ->and($activityLog->metadata['changes']['api_key'])->toBe(['changed' => true])
        ->and($activityLog->metadata['changes']['name'])->toBe(['from' => null, 'to' => 'Main Sonarr'])
        ->and(json_encode($activityLog->metadata, JSON_THROW_ON_ERROR))->not->toContain('created-api-key')->not->toContain('created-webhook-token');

    $this->actingAs($admin)->patch(route('admin.connections.toggle', $serviceConnection))->assertRedirect();
    $this->actingAs($admin)->patch(route('admin.connections.toggle', $serviceConnection))->assertRedirect();

    expect(adminAuditRow('connection.deactivated')->metadata['changes'])->toBe(['is_active' => ['from' => true, 'to' => false]])
        ->and(adminAuditRow('connection.activated')->description)->toBe('Activated Sonarr connection "Main Sonarr".');

    $this->actingAs($admin)->delete(route('admin.connections.destroy', $serviceConnection))->assertRedirect();

    $deleted = adminAuditRow('connection.deleted');

    expect($deleted->subject_id)->toBe($serviceConnection->id)
        ->and($deleted->service_connection_id)->toBeNull()
        ->and($deleted->description)->toBe('Deleted Sonarr connection "Main Sonarr".');
});

test('a connection update masks a credential URL and a rotated API key', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create(['name' => 'Sonarr', 'url' => 'http://sonarr.local:8989', 'api_key' => 'old-api-key']);

    $this->actingAs($admin)->put(route('admin.connections.update', $connection), [
        'type' => 'sonarr',
        'name' => 'Main',
        'url' => 'http://admin:hunter2@sonarr.local:8989',
        'api_key' => 'rotated-api-key',
    ])->assertRedirect(route('admin.connections.index'));

    $activityLog = adminAuditRow('connection.updated');

    expect($activityLog->metadata['changes'])->toMatchArray([
        'name' => ['from' => 'Sonarr', 'to' => 'Main'],
        'url' => ['changed' => true],
        'api_key' => ['changed' => true],
    ])->and(json_encode($activityLog->metadata, JSON_THROW_ON_ERROR))
        ->not->toContain('hunter2')
        ->not->toContain('rotated-api-key')
        ->not->toContain('old-api-key');
});

test('an update that changes nothing writes no audit row', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create(['name' => 'Sonarr', 'url' => 'http://sonarr.local:8989']);

    $this->actingAs($admin)->put(route('admin.connections.update', $connection), [
        'type' => 'sonarr', 'name' => 'Sonarr', 'url' => 'http://sonarr.local:8989',
    ])->assertRedirect();

    expect(ActivityLog::query()->where('action', 'connection.updated')->exists())->toBeFalse();
});

test('unlinking an Emby account records the unlink', function (): void {
    $viewer = User::factory()->create(['name' => 'Vic']);
    $link = EmbyUserLink::factory()->create(['user_id' => $viewer->id, 'emby_username' => 'vic-emby', 'emby_user_id' => 'emby-7']);

    $this->actingAs($viewer)->delete(route('emby.links.destroy', $link))->assertRedirect();

    $activityLog = adminAuditRow('emby.user_unlinked');

    expect($activityLog->user_id)->toBe($viewer->id)
        ->and($activityLog->description)->toBe('Unlinked Emby user "vic-emby" from Vic.')
        ->and($activityLog->metadata['context'])->toBe(['emby_user_id' => 'emby-7', 'user_id' => $viewer->id]);
});

test('a series delete requested from the series page is audited against its action request', function (): void {
    Queue::fake();
    $connection = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    ActionTypeConfig::factory()->create(['type' => 'delete_series', 'requires_approval' => true, 'is_enabled' => true]);
    Http::fake(['sonarr.local:8989/api/v3/series/42' => Http::response(['id' => 42, 'title' => 'My Show', 'year' => 2024])]);
    $member = User::factory()->member()->create();

    $this->actingAs($member)->delete(route('media.series.destroy', 42), ['delete_files' => true])->assertRedirect();

    $activityLog = adminAuditRow('series.delete_requested');
    $actionRequest = ActionRequest::query()->where('type', 'delete_series')->sole();

    expect($activityLog->user_id)->toBe($member->id)
        ->and($activityLog->subject_type)->toBe(ActionRequest::class)
        ->and($activityLog->subject_id)->toBe($actionRequest->id)
        ->and($activityLog->metadata['context'])->toBe(['sonarr_series_id' => 42, 'delete_files' => true, 'service_connection_id' => $connection->id]);
});

test('a movie delete requested from the movie page is audited against its action request', function (): void {
    Queue::fake();
    $connection = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    ActionTypeConfig::factory()->create(['type' => 'delete_movie', 'requires_approval' => true, 'is_enabled' => true]);
    Http::fake(['radarr.local:7878/api/v3/movie/7' => Http::response(['id' => 7, 'title' => 'Dune', 'year' => 2021])]);
    $member = User::factory()->member()->create();

    $this->actingAs($member)->delete(route('media.movies.destroy', 7))->assertRedirect();

    expect(adminAuditRow('movie.delete_requested')->metadata['context'])
        ->toBe(['radarr_movie_id' => 7, 'delete_files' => false, 'service_connection_id' => $connection->id]);
});

test('a refused role change writes no audit row', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->patch(route('admin.users.update-role', $admin), ['role' => 'member'])->assertForbidden();

    expect(ActivityLog::query()->where('action', 'user.role_changed')->exists())->toBeFalse()
        ->and($admin->fresh()->role)->toBe(UserRole::Admin);
});
