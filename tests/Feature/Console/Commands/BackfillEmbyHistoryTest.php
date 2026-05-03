<?php

declare(strict_types=1);

use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('fails when no active Emby connection is configured', function (): void {
    $this->artisan('emby:backfill-history')
        ->expectsOutputToContain('No active Emby connection')
        ->assertExitCode(1);
});

test('fails when no EmbyUserLink rows exist', function (): void {
    ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'k',
    ]);

    $this->artisan('emby:backfill-history')
        ->expectsOutputToContain('No EmbyUserLink')
        ->assertExitCode(1);
});

test('matches --user against EmbyUserLink.emby_username first', function (): void {
    ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'k',
    ]);
    EmbyUserLink::factory()->create(['emby_user_id' => 'u-alice', 'emby_username' => 'alice']);
    EmbyUserLink::factory()->create(['emby_user_id' => 'u-bob', 'emby_username' => 'bob']);

    Http::fake([
        'emby.local:8096/Users/u-alice/Items*' => Http::response(['Items' => [
            ['Id' => 'm1', 'Type' => 'Movie', 'Name' => 'Alice Movie', 'UserData' => ['Played' => true]],
        ], 'TotalRecordCount' => 1]),
        'emby.local:8096/Users/u-bob/Items*' => Http::response(['Items' => [], 'TotalRecordCount' => 0]),
    ]);

    $this->artisan('emby:backfill-history', ['--user' => 'alice'])->assertExitCode(0);

    expect(EmbyActivity::count())->toBe(1);
    expect(EmbyActivity::first()->media_title)->toBe('Alice Movie');
});

test('falls back to User.email when --user does not match emby_username', function (): void {
    ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'k',
    ]);
    $user = User::factory()->create(['email' => 'foo@example.com']);
    EmbyUserLink::factory()->create([
        'user_id' => $user->id,
        'emby_user_id' => 'u-foo',
        'emby_username' => 'someone-else',
    ]);

    Http::fake([
        'emby.local:8096/Users/u-foo/Items*' => Http::response(['Items' => [
            ['Id' => 'm1', 'Type' => 'Movie', 'Name' => 'EmailMatch', 'UserData' => ['Played' => true]],
        ], 'TotalRecordCount' => 1]),
    ]);

    $this->artisan('emby:backfill-history', ['--user' => 'foo@example.com'])->assertExitCode(0);

    expect(EmbyActivity::count())->toBe(1);
});

test('falls back to numeric User.id when --user is digits', function (): void {
    ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'k',
    ]);
    $user = User::factory()->create();
    EmbyUserLink::factory()->create([
        'user_id' => $user->id,
        'emby_user_id' => 'u-num',
        'emby_username' => 'somebody',
    ]);

    Http::fake([
        'emby.local:8096/Users/u-num/Items*' => Http::response(['Items' => [
            ['Id' => 'm1', 'Type' => 'Movie', 'Name' => 'NumMatch', 'UserData' => ['Played' => true]],
        ], 'TotalRecordCount' => 1]),
    ]);

    $this->artisan('emby:backfill-history', ['--user' => (string) $user->id])->assertExitCode(0);

    expect(EmbyActivity::count())->toBe(1);
});

test('fails when --user matches nothing', function (): void {
    ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'k',
    ]);
    EmbyUserLink::factory()->create(['emby_username' => 'alice']);

    $this->artisan('emby:backfill-history', ['--user' => 'nope'])
        ->expectsOutputToContain("No EmbyUserLink matched 'nope'")
        ->assertExitCode(1);
});

test('iterates all EmbyUserLinks when --user is omitted', function (): void {
    ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'k',
    ]);
    EmbyUserLink::factory()->create(['emby_user_id' => 'u-1', 'emby_username' => 'one']);
    EmbyUserLink::factory()->create(['emby_user_id' => 'u-2', 'emby_username' => 'two']);

    Http::fake([
        'emby.local:8096/Users/u-1/Items*' => Http::response(['Items' => [
            ['Id' => 'a', 'Type' => 'Movie', 'Name' => 'AA', 'UserData' => ['Played' => true]],
        ], 'TotalRecordCount' => 1]),
        'emby.local:8096/Users/u-2/Items*' => Http::response(['Items' => [
            ['Id' => 'b', 'Type' => 'Movie', 'Name' => 'BB', 'UserData' => ['Played' => true]],
        ], 'TotalRecordCount' => 1]),
    ]);

    $this->artisan('emby:backfill-history')->assertExitCode(0);

    expect(EmbyActivity::count())->toBe(2);
});

test('continues on per-user errors and exits SUCCESS if at least one user processed', function (): void {
    ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'k',
    ]);
    EmbyUserLink::factory()->create(['emby_user_id' => 'u-good', 'emby_username' => 'good']);
    EmbyUserLink::factory()->create(['emby_user_id' => 'u-bad', 'emby_username' => 'bad']);

    Http::fake([
        'emby.local:8096/Users/u-good/Items*' => Http::response(['Items' => [
            ['Id' => 'g1', 'Type' => 'Movie', 'Name' => 'OK', 'UserData' => ['Played' => true]],
        ], 'TotalRecordCount' => 1]),
        'emby.local:8096/Users/u-bad/Items*' => Http::response([], 500),
    ]);

    $this->artisan('emby:backfill-history')->assertExitCode(0);

    expect(EmbyActivity::count())->toBe(1);
});

test('dry-run does not write to the database', function (): void {
    ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'k',
    ]);
    EmbyUserLink::factory()->create(['emby_user_id' => 'u-dry', 'emby_username' => 'dry']);

    Http::fake([
        'emby.local:8096/Users/u-dry/Items*' => Http::response(['Items' => [
            ['Id' => 'd1', 'Type' => 'Movie', 'Name' => 'D', 'UserData' => ['Played' => true]],
        ], 'TotalRecordCount' => 1]),
    ]);

    $this->artisan('emby:backfill-history', ['--dry-run' => true])->assertExitCode(0);

    expect(EmbyActivity::count())->toBe(0);
});

test('prints per-user summary line containing fetched/created/updated/skipped', function (): void {
    ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'k',
    ]);
    EmbyUserLink::factory()->create(['emby_user_id' => 'u-sum', 'emby_username' => 'sum']);

    Http::fake([
        'emby.local:8096/Users/u-sum/Items*' => Http::response(['Items' => [
            ['Id' => 's1', 'Type' => 'Movie', 'Name' => 'S', 'UserData' => ['Played' => true]],
        ], 'TotalRecordCount' => 1]),
    ]);

    $this->artisan('emby:backfill-history')
        ->expectsOutputToContain('fetched=')
        ->expectsOutputToContain('created=')
        ->expectsOutputToContain('updated=')
        ->expectsOutputToContain('skipped=')
        ->assertExitCode(0);
});
