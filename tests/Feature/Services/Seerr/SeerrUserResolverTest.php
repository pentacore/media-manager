<?php

declare(strict_types=1);

use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Seerr\SeerrUserResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->connection = ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
});

/**
 * @param  list<array<string, mixed>>  $users
 */
function fakeSeerrUsers(array $users): void
{
    Http::fake(['seerr.local:5055/api/v1/user*' => Http::response([
        'pageInfo' => ['pages' => 1, 'page' => 1, 'results' => count($users)],
        'results' => $users,
    ])]);
}

test('it matches the linked Emby account against the Seerr media-server id, ignoring case and dashes', function (): void {
    $user = User::factory()->create(['email' => 'someone-else@example.com']);
    EmbyUserLink::factory()->for($user)->create(['emby_user_id' => 'A1B2C3D4-E5F6-4711-8899-AABBCCDDEEFF']);
    fakeSeerrUsers([
        ['id' => 3, 'email' => 'x@example.com', 'jellyfinUserId' => null],
        ['id' => 9, 'email' => 'y@example.com', 'jellyfinUserId' => 'a1b2c3d4e5f647118899aabbccddeeff'],
    ]);

    expect(resolve(SeerrUserResolver::class)->resolve($this->connection, $user))->toBe(9);
});

test('it falls back to a case-insensitive email match', function (): void {
    $user = User::factory()->create(['email' => 'Viewer@Example.com']);
    fakeSeerrUsers([['id' => 4, 'email' => ' viewer@example.COM ', 'jellyfinUserId' => 'ffff']]);

    expect(resolve(SeerrUserResolver::class)->resolve($this->connection, $user))->toBe(4);
});

test('an Emby link match wins over an email match on another Seerr user', function (): void {
    $user = User::factory()->create(['email' => 'viewer@example.com']);
    EmbyUserLink::factory()->for($user)->create(['emby_user_id' => 'abc123']);
    fakeSeerrUsers([
        ['id' => 4, 'email' => 'viewer@example.com', 'jellyfinUserId' => null],
        ['id' => 5, 'email' => 'other@example.com', 'jellyfinUserId' => 'ABC123'],
    ]);

    expect(resolve(SeerrUserResolver::class)->resolve($this->connection, $user))->toBe(5);
});

test('it returns null when nothing matches', function (): void {
    fakeSeerrUsers([['id' => 4, 'email' => 'other@example.com', 'jellyfinUserId' => 'ffff']]);

    expect(resolve(SeerrUserResolver::class)->resolve($this->connection, User::factory()->create()))->toBeNull();
});

test('it walks every page of Seerr users', function (): void {
    $user = User::factory()->create(['email' => 'late@example.com']);
    Http::fake(['seerr.local:5055/api/v1/user*' => function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $skip = (int) ($query['skip'] ?? 0);
        $results = $skip === 0
            ? array_map(static fn (int $id): array => ['id' => $id, 'email' => sprintf('u%d@example.com', $id)], range(1, 100))
            : [['id' => 500, 'email' => 'late@example.com']];

        return Http::response(['pageInfo' => ['pages' => 2, 'page' => $skip === 0 ? 1 : 2], 'results' => $results]);
    }]);

    expect(resolve(SeerrUserResolver::class)->resolve($this->connection, $user))->toBe(500);
});

test('the match, including no match, is cached for ten minutes', function (): void {
    $user = User::factory()->create(['email' => 'viewer@example.com']);
    fakeSeerrUsers([['id' => 4, 'email' => 'viewer@example.com']]);
    $seerrUserResolver = resolve(SeerrUserResolver::class);

    $seerrUserResolver->resolve($this->connection, $user);
    $this->travel(9)->minutes();
    $seerrUserResolver->resolve($this->connection, $user);
    Http::assertSentCount(1);

    $this->travel(2)->minutes();
    $seerrUserResolver->resolve($this->connection, $user);
    Http::assertSentCount(2);
});

test('an unreachable Seerr throws and caches nothing', function (): void {
    $user = User::factory()->create();
    // A single stateful stub, flipped between phases: Http::fake() merges
    // rather than replaces stub callbacks for a repeated URL pattern, so two
    // separate Http::fake() calls for the same pattern would leave the first
    // (always-throwing) stub in place and it would fire again on the second
    // request regardless of the later stub.
    $seerr = (object) ['failing' => true];
    Http::fake(['seerr.local:5055/api/v1/user*' => function () use ($seerr, $user) {
        throw_if($seerr->failing, ConnectionException::class, 'down');

        return Http::response(['pageInfo' => ['pages' => 1, 'page' => 1, 'results' => 1], 'results' => [['id' => 4, 'email' => $user->email]]]);
    }]);

    expect(fn (): ?int => resolve(SeerrUserResolver::class)->resolve($this->connection, $user))->toThrow(ConnectionException::class);

    $seerr->failing = false;
    expect(resolve(SeerrUserResolver::class)->resolve($this->connection, $user))->toBe(4);
});

test('a viewer gets their own match and no picker', function (): void {
    $viewer = User::factory()->create(['email' => 'viewer@example.com']);
    fakeSeerrUsers([['id' => 4, 'email' => 'viewer@example.com', 'displayName' => 'Viewer'], ['id' => 5, 'email' => 'b@example.com', 'displayName' => 'B']]);

    expect(resolve(SeerrUserResolver::class)->requestingContext($this->connection, $viewer))
        ->toBe(['canChooseUser' => false, 'userId' => 4, 'users' => [], 'partial' => false, 'error' => null]);
});

test('a member gets the picker defaulting to their own match', function (): void {
    $member = User::factory()->member()->create(['email' => 'b@example.com']);
    fakeSeerrUsers([['id' => 4, 'email' => 'a@example.com', 'displayName' => 'A'], ['id' => 5, 'email' => 'b@example.com', 'displayName' => 'B']]);

    expect(resolve(SeerrUserResolver::class)->requestingContext($this->connection, $member))->toBe([
        'canChooseUser' => true,
        'userId' => 5,
        'users' => [['id' => 4, 'label' => 'A'], ['id' => 5, 'label' => 'B']],
        'partial' => false,
        'error' => null,
    ]);
});

test('an unreachable Seerr leaves a viewer without an id and with an error', function (): void {
    Http::fake(['seerr.local:5055/api/v1/user*' => Http::response([], 503)]);

    expect(resolve(SeerrUserResolver::class)->requestingContext($this->connection, User::factory()->create()))
        ->toBe(['canChooseUser' => false, 'userId' => null, 'users' => [], 'partial' => false, 'error' => 'Seerr is unreachable right now.']);
});

test('a partial match found before a later page fails still becomes the picker default, without being cached', function (): void {
    $member = User::factory()->member()->create(['email' => 'b@example.com']);
    $firstPage = collect()->range(1, 100)->map(fn (int $i): array => [
        'id' => $i,
        'email' => $i === 50 ? 'b@example.com' : sprintf('user%d@example.com', $i),
    ])->all();

    // A single stub (see the note above): page 1 always succeeds and
    // contains the member's match; page 2 fails with a client error (no
    // retry) until $pageTwoFails is flipped off, so the follow-up resolve()
    // call below proves the failed walk cached nothing rather than the
    // partial match.
    $pageTwoFails = true;
    Http::fake(['seerr.local:5055/api/v1/user*' => function (Request $request) use ($firstPage, &$pageTwoFails) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if ((int) ($query['skip'] ?? 0) === 0) {
            return Http::response(['pageInfo' => ['page' => 1, 'pages' => 2], 'results' => $firstPage]);
        }

        return $pageTwoFails
            ? Http::response([], 400)
            : Http::response(['pageInfo' => ['page' => 2, 'pages' => 2], 'results' => []]);
    }]);

    $seerrUserResolver = resolve(SeerrUserResolver::class);
    $options = $seerrUserResolver->pickerOptions($this->connection, $member);

    expect($options['defaultId'])->toBe(50);
    expect($options['partial'])->toBeTrue();

    $pageTwoFails = false;
    expect($seerrUserResolver->resolve($this->connection, $member))->toBe(50);
    Http::assertSentCount(4);
});

test('pickerOptions() never falls back to an arbitrary first Seerr user when the caller has no own match', function (): void {
    $member = User::factory()->member()->create(['email' => 'nobody@example.com']);
    fakeSeerrUsers([
        ['id' => 4, 'email' => 'a@example.com', 'displayName' => 'A'],
        ['id' => 5, 'email' => 'b@example.com', 'displayName' => 'B'],
    ]);

    $options = resolve(SeerrUserResolver::class)->pickerOptions($this->connection, $member);

    expect($options['defaultId'])->toBeNull();
    expect($options['partial'])->toBeFalse();
    expect($options['users'])->toHaveCount(2);
});

test('a "no match" result is cached, so a second resolve() call does not re-fetch', function (): void {
    $user = User::factory()->create(['email' => 'nobody@example.com']);
    fakeSeerrUsers([['id' => 4, 'email' => 'other@example.com']]);
    $seerrUserResolver = resolve(SeerrUserResolver::class);

    expect($seerrUserResolver->resolve($this->connection, $user))->toBeNull();
    expect($seerrUserResolver->resolve($this->connection, $user))->toBeNull();

    Http::assertSentCount(1);
});

test('pickerOptions() primes the resolve() cache, so a follow-up resolve() call does not re-fetch', function (): void {
    $user = User::factory()->create(['email' => 'viewer@example.com']);
    fakeSeerrUsers([['id' => 4, 'email' => 'viewer@example.com']]);
    $seerrUserResolver = resolve(SeerrUserResolver::class);

    $seerrUserResolver->pickerOptions($this->connection, $user);

    expect($seerrUserResolver->resolve($this->connection, $user))->toBe(4);
    Http::assertSentCount(1);
});

test('an empty MediaManager email never matches a Seerr user with an empty or null email', function (): void {
    $user = User::factory()->create(['email' => '']);
    fakeSeerrUsers([
        ['id' => 4, 'email' => '', 'jellyfinUserId' => null],
        ['id' => 5, 'email' => null, 'jellyfinUserId' => null],
    ]);

    expect(resolve(SeerrUserResolver::class)->resolve($this->connection, $user))->toBeNull();
});

// ── resolveUserId() ─────────────────────────────────────────────────────
// Pure logic over a requestingContext() shape — no HTTP needed. Shared by
// DiscoverController and AnimeController so both file requests under the
// same identity rules.

/**
 * @param  list<array{id: int, label: string}>  $users
 * @return array{canChooseUser: bool, userId: int|null, users: list<array{id: int, label: string}>, partial: bool, error: string|null}
 */
function seerrContext(bool $canChooseUser, ?int $userId, array $users = [], bool $partial = false, ?string $error = null): array
{
    return ['canChooseUser' => $canChooseUser, 'userId' => $userId, 'users' => $users, 'partial' => $partial, 'error' => $error];
}

test('resolveUserId reports the context error immediately, before any identity rule', function (): void {
    $context = seerrContext(true, 5, [['id' => 5, 'label' => 'Me']], error: 'Seerr is unreachable right now.');

    expect(resolve(SeerrUserResolver::class)->resolveUserId($context, null))
        ->toBe(['userId' => null, 'error' => 'Seerr is unreachable right now.']);
});

test('resolveUserId gives a non-chooser their own resolved match, ignoring any posted id', function (): void {
    $context = seerrContext(false, 7);

    expect(resolve(SeerrUserResolver::class)->resolveUserId($context, 999))
        ->toBe(['userId' => 7, 'error' => null]);
});

test('resolveUserId refuses a non-chooser with no Seerr account', function (): void {
    $context = seerrContext(false, null);

    expect(resolve(SeerrUserResolver::class)->resolveUserId($context, null))
        ->toBe(['userId' => null, 'error' => 'No Seerr account is linked to you — ask an admin.']);
});

test('resolveUserId defaults a chooser to their own match when no id is posted', function (): void {
    $context = seerrContext(true, 5, [['id' => 4, 'label' => 'A'], ['id' => 5, 'label' => 'B']]);

    expect(resolve(SeerrUserResolver::class)->resolveUserId($context, null))
        ->toBe(['userId' => 5, 'error' => null]);
});

test('resolveUserId lets a chooser pick a different Seerr user than their own match', function (): void {
    $context = seerrContext(true, 5, [['id' => 4, 'label' => 'A'], ['id' => 5, 'label' => 'B']]);

    expect(resolve(SeerrUserResolver::class)->resolveUserId($context, 4))
        ->toBe(['userId' => 4, 'error' => null]);
});

test('resolveUserId refuses an id missing from a complete users list as not found', function (): void {
    $context = seerrContext(true, null, [['id' => 4, 'label' => 'A']], partial: false);

    expect(resolve(SeerrUserResolver::class)->resolveUserId($context, 999))
        ->toBe(['userId' => null, 'error' => 'That Seerr user was not found.']);
});

test('resolveUserId treats an id missing from a partial users list as an outage, not "not found"', function (): void {
    $context = seerrContext(true, null, [['id' => 4, 'label' => 'A']], partial: true);

    expect(resolve(SeerrUserResolver::class)->resolveUserId($context, 999))
        ->toBe(['userId' => null, 'error' => 'Seerr is unreachable right now.']);
});

test('resolveUserId refuses a chooser with no own match and no posted id, never guessing an arbitrary user', function (): void {
    $context = seerrContext(true, null, [['id' => 4, 'label' => 'A'], ['id' => 5, 'label' => 'B']]);

    expect(resolve(SeerrUserResolver::class)->resolveUserId($context, null))
        ->toBe(['userId' => null, 'error' => 'Choose which Seerr user to request as.']);
});
