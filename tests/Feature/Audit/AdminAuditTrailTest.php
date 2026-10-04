<?php

declare(strict_types=1);

use App\Enums\ActivityLogCategory;
use App\Enums\UserRole;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ActivityLog;
use App\Models\BazarrServiceLink;
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

test('an audit row keeps its actor name after that admin account is deleted', function (): void {
    $ada = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $otherAdmin = User::factory()->admin()->create();

    $this->actingAs($ada)->post(route('admin.connections.store'), [
        'type' => 'sonarr', 'name' => 'Main Sonarr', 'url' => 'http://sonarr.local:8989', 'api_key' => 'created-api-key', 'webhook_token' => 'created-webhook-token',
    ])->assertRedirect(route('admin.connections.index'));
    $this->actingAs($otherAdmin)->delete(route('admin.users.destroy', $ada))->assertRedirect();

    $activityLog = adminAuditRow('connection.created');

    expect($activityLog->user_id)->toBeNull()
        ->and($activityLog->metadata['actor'])->toBe(['id' => $ada->id, 'name' => 'Ada Admin']);

    $this->actingAs($otherAdmin)
        ->get(route('activity-log', ['category' => 'audit']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'logs.data',
            fn ($rows): bool => collect($rows)->firstWhere('action', 'connection.created')['user_name'] === 'Ada Admin',
        ));
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

test('a Bazarr save that only repoints its mapping is audited', function (): void {
    $admin = User::factory()->admin()->create();
    $bazarr = ServiceConnection::factory()->bazarr()->create(['name' => 'Bazarr', 'url' => 'http://bazarr.local:6767']);
    $firstSonarr = ServiceConnection::factory()->sonarr()->create();
    $secondSonarr = ServiceConnection::factory()->sonarr()->create();
    BazarrServiceLink::factory()->sonarr()->create(['bazarr_connection_id' => $bazarr->id, 'related_connection_id' => $firstSonarr->id]);

    $this->actingAs($admin)->put(route('admin.connections.update', $bazarr), [
        'type' => 'bazarr', 'name' => 'Bazarr', 'url' => 'http://bazarr.local:6767', 'sonarr_connection_id' => $secondSonarr->id,
    ])->assertRedirect(route('admin.connections.index'))->assertSessionHasNoErrors();

    expect(adminAuditRow('connection.updated')->metadata['changes'])->toBe([
        'sonarr_connection_id' => ['from' => $firstSonarr->id, 'to' => $secondSonarr->id],
    ]);
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

    $this->actingAs($member)->delete(route('media.series.destroy', 42), ['delete_files' => true, 'service_connection_id' => $connection->id])->assertRedirect();

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

    $this->actingAs($member)->delete(route('media.movies.destroy', 7), ['service_connection_id' => $connection->id])->assertRedirect();

    expect(adminAuditRow('movie.delete_requested')->metadata['context'])
        ->toBe(['radarr_movie_id' => 7, 'delete_files' => false, 'service_connection_id' => $connection->id]);
});

test('a refused role change writes no audit row', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->patch(route('admin.users.update-role', $admin), ['role' => 'member'])->assertForbidden();

    expect(ActivityLog::query()->where('action', 'user.role_changed')->exists())->toBeFalse()
        ->and($admin->fresh()->role)->toBe(UserRole::Admin);
});

test('a member linking their own Emby account by password records the link', function (): void {
    ServiceConnection::factory()->emby()->create(['url' => 'http://emby.local:8096', 'api_key' => 'k']);
    $viewer = User::factory()->create(['name' => 'Vic']);
    Http::fake(['emby.local:8096/Users/AuthenticateByName' => Http::response(['User' => ['Id' => 'emby-7', 'Name' => 'vic-emby'], 'AccessToken' => 't'])]);

    $this->actingAs($viewer)->post(route('emby.links.store'), ['emby_username' => 'vic-emby', 'password' => 'secret'])->assertRedirect();

    $embyUserLink = EmbyUserLink::query()->sole();
    $activityLog = adminAuditRow('emby.user_linked');

    expect($activityLog->user_id)->toBe($viewer->id)
        ->and($activityLog->subject_type)->toBe($embyUserLink->getMorphClass())
        ->and($activityLog->subject_id)->toBe($embyUserLink->id)
        ->and($activityLog->description)->toBe('Linked Emby user "vic-emby" to Vic.')
        ->and($activityLog->metadata['context'])->toBe(['emby_user_id' => 'emby-7', 'user_id' => $viewer->id, 'source' => 'credentials'])
        ->and(json_encode($activityLog->metadata))->not->toContain('secret');
});

test('an admin link from the Emby directory and by username are recorded with their source', function (): void {
    ServiceConnection::factory()->emby()->create(['url' => 'http://emby.local:8096', 'api_key' => 'k']);
    $admin = User::factory()->admin()->create();
    $bobby = User::factory()->create(['name' => 'Bobby']);
    $carla = User::factory()->create(['name' => 'Carla']);
    Http::fake(['emby.local:8096/Users' => Http::response([
        ['Id' => 'emby-2', 'Name' => 'bob', 'LastActivityDate' => null, 'Policy' => ['IsAdministrator' => false]],
        ['Id' => 'emby-3', 'Name' => 'carla', 'LastActivityDate' => null, 'Policy' => ['IsAdministrator' => false]],
    ])]);

    $this->actingAs($admin)->post(route('emby.links.directory.store'), ['user_id' => $bobby->id, 'emby_user_id' => 'emby-2'])->assertRedirect();
    $this->actingAs($admin)->post(route('admin.users.link-emby', $carla), ['emby_username' => 'carla'])->assertRedirect();

    $rows = ActivityLog::query()->where('action', 'emby.user_linked')->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn (ActivityLog $activityLog): bool => $activityLog->isAudit() && $activityLog->user_id === $admin->id))->toBeTrue()
        ->and($rows[0]->description)->toBe('Linked Emby user "bob" to Bobby.')
        ->and($rows[0]->metadata['context'])->toBe(['emby_user_id' => 'emby-2', 'user_id' => $bobby->id, 'source' => 'directory'])
        ->and($rows[1]->description)->toBe('Linked Emby user "carla" to Carla.')
        ->and($rows[1]->metadata['context'])->toBe(['emby_user_id' => 'emby-3', 'user_id' => $carla->id, 'source' => 'username']);
});

test('a link whose audit row cannot be written is not kept', function (): void {
    ServiceConnection::factory()->emby()->create(['url' => 'http://emby.local:8096', 'api_key' => 'k']);
    $viewer = User::factory()->create();
    Http::fake(['emby.local:8096/Users/AuthenticateByName' => Http::response(['User' => ['Id' => 'emby-7', 'Name' => 'vic-emby'], 'AccessToken' => 't'])]);
    ActivityLog::creating(static function (ActivityLog $activityLog): void {
        throw_if($activityLog->category === ActivityLogCategory::Audit, RuntimeException::class, 'audit store unavailable');
    });

    expect(fn () => $this->withoutExceptionHandling()->actingAs($viewer)->post(route('emby.links.store'), ['emby_username' => 'vic-emby', 'password' => 'secret']))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(EmbyUserLink::query()->count())->toBe(0);
});

test('an Emby import that creates accounts records one summary row, and one that creates none records nothing', function (): void {
    $emby = ServiceConnection::factory()->emby()->create(['url' => 'http://emby.local:8096', 'api_key' => 'k']);
    $admin = User::factory()->admin()->create();
    EmbyUserLink::factory()->create(['emby_user_id' => 'emby-1', 'emby_username' => 'already']);
    Http::fake(['emby.local:8096/Users' => Http::response([
        ['Id' => 'emby-1', 'Name' => 'already'],
        ['Id' => 'emby-9', 'Name' => 'Newcomer'],
    ])]);

    $this->actingAs($admin)->post(route('admin.users.import-from-emby'))->assertRedirect();
    $this->actingAs($admin)->post(route('admin.users.import-from-emby'))->assertRedirect();

    $user = User::query()->where('email', 'emby+newcomer@local.invalid')->sole();
    $activityLog = adminAuditRow('emby.users_imported');

    expect($activityLog->user_id)->toBe($admin->id)
        ->and($activityLog->service_connection_id)->toBe($emby->id)
        ->and($activityLog->description)->toBe('Imported 1 Emby user(s), skipped 1.')
        ->and($activityLog->metadata['context'])->toBe(['created' => 1, 'skipped' => 1, 'user_ids' => [$user->id]]);
});
