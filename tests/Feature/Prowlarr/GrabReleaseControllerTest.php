<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Prowlarr\IndexerReleaseCache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    $this->prowlarr = ServiceConnection::factory()->prowlarr()->create(['url' => 'http://prowlarr.local:9696', 'api_key' => 'prowlarr-secret-key']);
    $this->admin = User::factory()->admin()->create();
});

/**
 * @return array<string, mixed>
 */
function prowlarrGrabRelease(): array
{
    return [
        'guid' => 'https://tracker.example/download/1?passkey=tracker-passkey',
        'indexerId' => 3,
        'title' => 'Severance.S02E07.1080p.WEB-DL',
        'indexer' => 'NZBgeek',
        'size' => 2_500_000_000,
        'seeders' => 412,
        'age' => 1,
        'publishDate' => '2026-09-28T00:00:00Z',
        'downloadUrl' => 'http://prowlarr.local:9696/3/download?apikey=prowlarr-secret-key&link=abc',
        'magnetUrl' => 'magnet:?xt=urn:btih:abc&tr=https://tracker.example/announce?passkey=tracker-passkey',
        'infoUrl' => 'https://tracker.example/details/1?passkey=tracker-passkey',
    ];
}

/**
 * @return array{key: string|null, indexer_id: int|null}
 */
function prowlarrGrabRemembered(ServiceConnection $serviceConnection): array
{
    $row = resolve(IndexerReleaseCache::class)->remember($serviceConnection, [prowlarrGrabRelease()])[0];

    return ['key' => $row['key'], 'indexer_id' => $row['indexer_id']];
}

test('an admin grabs a release Prowlarr returned', function (): void {
    Http::fake(['prowlarr.local:9696/api/v1/search' => Http::response(['title' => 'Severance.S02E07.1080p.WEB-DL'], 200)]);
    $row = prowlarrGrabRemembered($this->prowlarr);

    $response = $this->actingAs($this->admin)
        ->postJson(route('prowlarr.grab'), ['release_key' => $row['key'], 'indexer_id' => $row['indexer_id']])
        ->assertOk()
        ->assertJsonPath('message', 'Sent "Severance.S02E07.1080p.WEB-DL" to the download client.');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v1/search')
        && $request['guid'] === prowlarrGrabRelease()['guid']
        && $request['indexerId'] === 3);

    $activityLog = ActivityLog::query()->where('action', 'prowlarr.release.grabbed')->sole();

    expect($activityLog->service_connection_id)->toBe($this->prowlarr->id)
        ->and($activityLog->metadata)->toBe(['indexer_id' => 3, 'title' => 'Severance.S02E07.1080p.WEB-DL', 'indexer' => 'NZBgeek'])
        ->and(json_encode($activityLog->toArray(), JSON_THROW_ON_ERROR).$response->getContent())->not->toContain('passkey');
});

test('a stale or re-paired release key is refused without calling Prowlarr', function (): void {
    $row = prowlarrGrabRemembered($this->prowlarr);

    $this->actingAs($this->admin)
        ->postJson(route('prowlarr.grab'), ['release_key' => $row['key'], 'indexer_id' => 4])
        ->assertStatus(422)
        ->assertJsonPath('message', 'That release is no longer available — run the search again.');

    $this->actingAs($this->admin)
        ->postJson(route('prowlarr.grab'), ['release_key' => hash('sha256', 'a-guid-nobody-searched'), 'indexer_id' => 3])
        ->assertStatus(422);

    $this->travel(IndexerReleaseCache::TTL_SECONDS + 60)->seconds();

    $this->actingAs($this->admin)
        ->postJson(route('prowlarr.grab'), ['release_key' => $row['key'], 'indexer_id' => $row['indexer_id']])
        ->assertStatus(422)
        ->assertJsonPath('message', 'That release is no longer available — run the search again.');

    Http::assertNothingSent();
});

test('a key cached for another Prowlarr connection is a miss', function (): void {
    $other = ServiceConnection::factory()->prowlarr()->create(['url' => 'http://prowlarr2.local:9696']);
    $row = prowlarrGrabRemembered($other);

    $this->actingAs($this->admin)
        ->postJson(route('prowlarr.grab'), ['release_key' => $row['key'], 'indexer_id' => $row['indexer_id']])
        ->assertStatus(422);

    Http::assertNothingSent();
});

test('Prowlarr refusals are sanitized and never retried', function (int $status, mixed $body, int $expectedStatus, string $message): void {
    Http::fake(['prowlarr.local:9696/api/v1/search' => Http::response($body, $status)]);
    $row = prowlarrGrabRemembered($this->prowlarr);

    $this->actingAs($this->admin)
        ->postJson(route('prowlarr.grab'), ['release_key' => $row['key'], 'indexer_id' => $row['indexer_id']])
        ->assertStatus($expectedStatus)
        ->assertJsonPath('message', $message);

    Http::assertSentCount(1);
    expect(ActivityLog::query()->where('action', 'prowlarr.release.grabbed')->exists())->toBeFalse();
})->with([
    'not in prowlarr cache' => [404, ['message' => "Couldn't find requested release in cache"], 422, 'Prowlarr no longer has that release cached — run the search again.'],
    'no download client' => [500, ['message' => "Usenet Download client isn't configured yet, see http://prowlarr.local:9696/settings?apikey=prowlarr-secret-key"], 422, "Prowlarr could not grab the release: Usenet Download client isn't configured yet, see http:/[redacted path]:9696/settings?[redacted]"],
    'bare server error' => [503, 'Service Unavailable', 502, 'Prowlarr is unreachable right now.'],
    'bare refusal' => [400, '', 422, 'Prowlarr refused the grab.'],
]);

test('a lost connection is reported as an unknown outcome, not a plain outage', function (): void {
    $attempts = 0;
    // A fake that throws never reaches recordRequestResponsePair(), so
    // Http::assertSentCount() would see 0 — count invocations directly
    // instead (same pattern as SonarrActionsTest's ConnectionException case).
    Http::fake(['prowlarr.local:9696/api/v1/search' => function () use (&$attempts): never {
        $attempts++;

        throw new ConnectionException('reset');
    }]);
    $row = prowlarrGrabRemembered($this->prowlarr);

    $this->actingAs($this->admin)
        ->postJson(route('prowlarr.grab'), ['release_key' => $row['key'], 'indexer_id' => $row['indexer_id']])
        ->assertStatus(502)
        ->assertJsonPath('message', 'No answer from Prowlarr — check the download client before grabbing again.');

    expect($attempts)->toBe(1)
        ->and(ActivityLog::query()->where('action', 'prowlarr.release.grabbed')->exists())->toBeFalse();
});

test('grab is admin-only and validates the key shape', function (): void {
    $row = prowlarrGrabRemembered($this->prowlarr);

    foreach ([User::factory()->create(), User::factory()->member()->create()] as $user) {
        $this->actingAs($user)
            ->postJson(route('prowlarr.grab'), ['release_key' => $row['key'], 'indexer_id' => $row['indexer_id']])
            ->assertForbidden();
    }

    $this->actingAs($this->admin)
        ->postJson(route('prowlarr.grab'), ['release_key' => 'not-a-hash', 'indexer_id' => 0])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['release_key', 'indexer_id']);

    Http::assertNothingSent();
});

test('search results carry the release key and indexer id but no release secrets', function (): void {
    Http::fake(['prowlarr.local:9696/api/v1/search*' => Http::response([prowlarrGrabRelease()])]);

    $response = $this->actingAs(User::factory()->member()->create())->get(route('prowlarr.search', ['q' => 'severance']));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('results.0.key', hash('sha256', prowlarrGrabRelease()['guid']))
        ->where('results.0.indexer_id', 3)
        ->missing('results.0.guid')
        ->missing('results.0.downloadUrl')
        ->missing('results.0.magnetUrl')
        ->missing('results.0.infoUrl'));

    expect($response->getContent())
        ->not->toContain('prowlarr-secret-key')
        ->not->toContain('tracker-passkey');
});
