<?php

declare(strict_types=1);

use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    $this->connection = ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'test-api-key',
    ]);
});

// ----- INDEX (admin only) -----

test('guests are redirected from links index', function (): void {
    $this->get(route('emby.links.index'))->assertRedirect(route('login'));
});

test('viewers cannot access links index', function (): void {
    $viewer = User::factory()->create();
    $this->actingAs($viewer)->get(route('emby.links.index'))->assertForbidden();
});

test('members cannot access links index', function (): void {
    $member = User::factory()->member()->create();
    $this->actingAs($member)->get(route('emby.links.index'))->assertForbidden();
});

test('admin can list all links', function (): void {
    $admin = User::factory()->admin()->create();
    EmbyUserLink::factory()->count(3)->create();

    $this->actingAs($admin)
        ->get(route('emby.links.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Emby/UserLinks')
            ->has('links', 3)
        );
});

// ----- STORE (any auth user, links own account) -----

test('guests cannot store a link', function (): void {
    $this->post(route('emby.links.store'), [])->assertRedirect(route('login'));
});

test('store validates required fields', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->post(route('emby.links.store'), [])
        ->assertSessionHasErrors(['emby_username', 'password']);
});

test('user can link their own emby account with valid credentials', function (): void {
    $user = User::factory()->create();

    Http::fake([
        'emby.local:8096/Users/AuthenticateByName' => Http::response([
            'User' => ['Id' => 'emby-user-xyz', 'Name' => 'alice'],
            'AccessToken' => 'token-123',
        ]),
    ]);

    $this->actingAs($user)
        ->post(route('emby.links.store'), [
            'emby_username' => 'alice',
            'password' => 'secret',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('emby_user_links', [
        'user_id' => $user->id,
        'emby_user_id' => 'emby-user-xyz',
        'emby_username' => 'alice',
    ]);

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/Users/AuthenticateByName')
        && $request['Username'] === 'alice'
        && $request['Pw'] === 'secret'
    );
});

test('store rejects invalid emby credentials', function (): void {
    $user = User::factory()->create();

    Http::fake([
        'emby.local:8096/Users/AuthenticateByName' => Http::response('', 401),
    ]);

    $this->actingAs($user)
        ->post(route('emby.links.store'), [
            'emby_username' => 'alice',
            'password' => 'wrong',
        ])
        ->assertRedirect();

    $this->assertDatabaseMissing('emby_user_links', ['user_id' => $user->id]);
});

test('user cannot link a second emby account', function (): void {
    $user = User::factory()->create();
    EmbyUserLink::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('emby.links.store'), [
            'emby_username' => 'alice',
            'password' => 'secret',
        ])
        ->assertRedirect();

    expect(EmbyUserLink::where('user_id', $user->id)->count())->toBe(1);
});

test('store rejects emby account already linked to another user', function (): void {
    $other = User::factory()->create();
    EmbyUserLink::factory()->create(['user_id' => $other->id, 'emby_user_id' => 'emby-user-xyz']);

    $user = User::factory()->create();

    Http::fake([
        'emby.local:8096/Users/AuthenticateByName' => Http::response([
            'User' => ['Id' => 'emby-user-xyz', 'Name' => 'alice'],
        ]),
    ]);

    $this->actingAs($user)
        ->post(route('emby.links.store'), [
            'emby_username' => 'alice',
            'password' => 'secret',
        ])
        ->assertRedirect();

    $this->assertDatabaseMissing('emby_user_links', ['user_id' => $user->id]);
});

test('emby_user_links has a unique index on emby_user_id alone', function (): void {
    $indexes = Schema::getIndexes('emby_user_links');

    $hasSingleColumnUnique = collect($indexes)->contains(
        fn (array $index): bool => $index['unique']
            && $index['columns'] === ['emby_user_id']
    );

    $hasOldCompoundUnique = collect($indexes)->contains(
        fn (array $index): bool => $index['unique']
            && $index['columns'] === ['user_id', 'emby_user_id']
    );

    expect($hasSingleColumnUnique)->toBeTrue(
        'Expected a unique index on emby_user_id alone to prevent two users linking the same Emby account.'
    );
    expect($hasOldCompoundUnique)->toBeFalse(
        'The old (user_id, emby_user_id) compound unique must have been dropped.'
    );
});

test('unique constraint on emby_user_id throws QueryException on duplicate insert', function (): void {
    // Direct DB-level check that the unique constraint is enforced. We wrap
    // the conflicting insert in a nested transaction so the QueryException
    // rolls back via SAVEPOINT and does not poison the outer RefreshDatabase
    // transaction.
    $first = User::factory()->create();
    $second = User::factory()->create();

    EmbyUserLink::create([
        'user_id' => $first->id,
        'emby_user_id' => 'unique-test-id',
        'emby_username' => 'Alice',
    ]);

    $caught = false;
    try {
        DB::transaction(function () use ($second): void {
            EmbyUserLink::create([
                'user_id' => $second->id,
                'emby_user_id' => 'unique-test-id',
                'emby_username' => 'Alice',
            ]);
        });
    } catch (QueryException) {
        $caught = true;
    }

    expect($caught)->toBeTrue();
    expect(EmbyUserLink::where('emby_user_id', 'unique-test-id')->count())->toBe(1);
});

test('store fails gracefully when no active emby connection', function (): void {
    $this->connection->update(['is_active' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('emby.links.store'), [
            'emby_username' => 'alice',
            'password' => 'secret',
        ])
        ->assertRedirect();

    $this->assertDatabaseMissing('emby_user_links', ['user_id' => $user->id]);
});

// ----- DESTROY (own link, or admin can delete any) -----

test('guests cannot destroy a link', function (): void {
    $link = EmbyUserLink::factory()->create();
    $this->delete(route('emby.links.destroy', $link))->assertRedirect(route('login'));
});

test('user can destroy their own link', function (): void {
    $user = User::factory()->create();
    $link = EmbyUserLink::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->delete(route('emby.links.destroy', $link))
        ->assertRedirect();

    $this->assertDatabaseMissing('emby_user_links', ['id' => $link->id]);
});

test('user cannot destroy another users link', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $link = EmbyUserLink::factory()->create(['user_id' => $other->id]);

    $this->actingAs($user)
        ->delete(route('emby.links.destroy', $link))
        ->assertForbidden();

    $this->assertDatabaseHas('emby_user_links', ['id' => $link->id]);
});

test('admin can destroy any link', function (): void {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();
    $link = EmbyUserLink::factory()->create(['user_id' => $other->id]);

    $this->actingAs($admin)
        ->delete(route('emby.links.destroy', $link))
        ->assertRedirect(route('emby.links.index'));

    $this->assertDatabaseMissing('emby_user_links', ['id' => $link->id]);
});
