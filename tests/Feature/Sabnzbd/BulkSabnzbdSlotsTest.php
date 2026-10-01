<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Sabnzbd\SabnzbdDownloadCounter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    Cache::put(SabnzbdDownloadCounter::CACHE_KEY, ['queued' => 0, 'completed' => 0], 60);
    $this->sabnzbd = ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sabnzbd.local:8080', 'api_key' => 'sab-secret-key']);
    $this->admin = User::factory()->admin()->create();
});

/**
 * @return array<string, string>
 */
function sabnzbdQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

/**
 * @param  list<string>  $failingIds
 */
function fakeSabnzbdBulk(array $failingIds = []): void
{
    Http::fake(['sabnzbd.local:8080/api*' => function (Request $request) use ($failingIds) {
        $query = sabnzbdQuery($request);

        if (($query['mode'] ?? null) === 'queue' && isset($query['name'])) {
            return in_array($query['value'] ?? null, $failingIds, true)
                ? Http::response(['error' => 'upstream body /data/secret'], 503)
                : Http::response(['status' => true]);
        }

        if (($query['mode'] ?? null) === 'queue') {
            return Http::response(['queue' => ['paused' => false, 'slots' => [
                ['nzo_id' => 'SABnzbd_nzo_aaa', 'filename' => 'Show.S01E01.mkv'],
                ['nzo_id' => 'SABnzbd_nzo_bbb', 'filename' => 'Show.S01E02.mkv'],
                ['nzo_id' => 'SABnzbd_nzo_ccc', 'filename' => 'Movie.2021.mkv'],
            ]]]);
        }

        return Http::response(['history' => ['slots' => []]]);
    }]);
}

test('an admin pauses several slots; each is paused and logged like the single action', function (): void {
    fakeSabnzbdBulk();

    $this->actingAs($this->admin)
        ->postJson(route('sabnzbd.queue.bulk'), ['ids' => ['SABnzbd_nzo_aaa', 'SABnzbd_nzo_bbb'], 'action' => 'pause'])
        ->assertOk()
        ->assertJsonPath('started', 2)
        ->assertJsonPath('toast.message', '2 paused');

    foreach (['SABnzbd_nzo_aaa', 'SABnzbd_nzo_bbb'] as $nzoId) {
        Http::assertSent(fn (Request $request): bool => (sabnzbdQuery($request)['name'] ?? null) === 'pause' && (sabnzbdQuery($request)['value'] ?? null) === $nzoId);
    }

    expect(ActivityLog::query()->where('action', 'sabnzbd.slot.paused')->get()->pluck('metadata.nzo_id')->sort()->values()->all())
        ->toBe(['SABnzbd_nzo_aaa', 'SABnzbd_nzo_bbb']);
});

test('a bulk delete writes one audit row per slot', function (): void {
    fakeSabnzbdBulk();

    $this->actingAs($this->admin)
        ->postJson(route('sabnzbd.queue.bulk'), ['ids' => ['SABnzbd_nzo_aaa', 'SABnzbd_nzo_ccc'], 'action' => 'delete'])
        ->assertJsonPath('toast.message', '2 deleted');

    expect(ActivityLog::query()->where('category', 'audit')->where('action', 'sabnzbd.slot_deleted')->count())->toBe(2)
        ->and(ActivityLog::query()->where('action', 'sabnzbd.slot.deleted')->count())->toBe(2);
});

test('one unreachable slot fails with a clean reason and the others still run', function (): void {
    Sleep::fake();
    fakeSabnzbdBulk(failingIds: ['SABnzbd_nzo_bbb']);

    $response = $this->actingAs($this->admin)
        ->postJson(route('sabnzbd.queue.bulk'), ['ids' => ['SABnzbd_nzo_aaa', 'SABnzbd_nzo_bbb', 'SABnzbd_nzo_ccc'], 'action' => 'resume'])
        ->assertJsonPath('started', 2)
        ->assertJsonPath('failed', [['id' => 'SABnzbd_nzo_bbb', 'title' => 'Show.S01E02.mkv', 'reason' => 'SABnzbd is unreachable right now.']])
        ->assertJsonPath('toast.type', 'error');

    expect($response->getContent())->not->toContain('upstream body')
        ->and($response->getContent())->not->toContain('sab-secret-key');
});

test('a malformed nzo_id is refused and nothing reaches SABnzbd', function (string $nzoId): void {
    fakeSabnzbdBulk();

    $this->actingAs($this->admin)
        ->postJson(route('sabnzbd.queue.bulk'), ['ids' => [$nzoId], 'action' => 'delete'])
        ->assertUnprocessable();

    Http::assertNothingSent();
})->with([
    'parameter smuggling' => ['SABnzbd_nzo_aaa&mode=shutdown'],
    'not an nzo id' => ['../../etc'],
    'empty' => [''],
]);

test('bulk slot actions without a SABnzbd connection are refused', function (): void {
    $this->sabnzbd->update(['is_active' => false]);

    $this->actingAs($this->admin)
        ->postJson(route('sabnzbd.queue.bulk'), ['ids' => ['SABnzbd_nzo_aaa'], 'action' => 'pause'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No SABnzbd connection configured.');
});

test('members cannot run SABnzbd bulk actions', function (): void {
    $this->actingAs(User::factory()->member()->create())
        ->postJson(route('sabnzbd.queue.bulk'), ['ids' => ['SABnzbd_nzo_aaa'], 'action' => 'pause'])
        ->assertForbidden();
});
