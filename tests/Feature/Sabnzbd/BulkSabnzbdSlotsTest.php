<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Sabnzbd\SabnzbdDownloadCounter;
use Illuminate\Http\Client\ConnectionException;
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
 * @param  list<string>  $refusedIds
 */
function fakeSabnzbdBulk(array $failingIds = [], array $refusedIds = []): void
{
    Http::fake(['sabnzbd.local:8080/api*' => function (Request $request) use ($failingIds, $refusedIds) {
        $query = sabnzbdQuery($request);

        if (($query['mode'] ?? null) === 'queue' && isset($query['name'])) {
            if (in_array($query['value'] ?? null, $failingIds, true)) {
                return Http::response(['error' => 'upstream body /data/secret'], 503);
            }

            if (in_array($query['value'] ?? null, $refusedIds, true)) {
                return Http::response(['status' => false]);
            }

            return Http::response(['status' => true]);
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

    $pausedLogs = ActivityLog::query()->where('action', 'sabnzbd.slot.paused')->get();

    expect($pausedLogs->pluck('metadata.nzo_id')->sort()->values()->all())
        ->toBe(['SABnzbd_nzo_aaa', 'SABnzbd_nzo_bbb'])
        ->and($pausedLogs->pluck('user_id')->unique()->all())
        ->toBe([$this->admin->id]);
});

test('a bulk delete writes one audit row per slot, matching the single-action shape', function (): void {
    fakeSabnzbdBulk();

    $this->actingAs($this->admin)
        ->postJson(route('sabnzbd.queue.bulk'), ['ids' => ['SABnzbd_nzo_aaa', 'SABnzbd_nzo_ccc'], 'action' => 'delete'])
        ->assertJsonPath('toast.message', '2 deleted');

    expect(ActivityLog::query()->where('action', 'sabnzbd.slot.deleted')->count())->toBe(2);

    $auditLogs = ActivityLog::query()
        ->where('category', 'audit')
        ->where('action', 'sabnzbd.slot_deleted')
        ->get()
        ->keyBy(fn (ActivityLog $activityLog): mixed => $activityLog->metadata['context']['nzo_id'] ?? null);

    expect($auditLogs)->toHaveCount(2);

    foreach (['SABnzbd_nzo_aaa', 'SABnzbd_nzo_ccc'] as $nzoId) {
        $auditLog = $auditLogs->get($nzoId);

        expect($auditLog)->not->toBeNull()
            ->and($auditLog->subject_type)->toBe(ServiceConnection::class)
            ->and($auditLog->subject_id)->toBe($this->sabnzbd->id)
            ->and($auditLog->description)->toBe(sprintf('Deleted SABnzbd queue job %s.', $nzoId))
            ->and($auditLog->metadata['context'])->toBe(['nzo_id' => $nzoId])
            ->and($auditLog->user_id)->toBe($this->admin->id);
    }
});

test('a refused slot fails cleanly and writes nothing while the other slot still runs', function (string $action, string $activityAction): void {
    fakeSabnzbdBulk(refusedIds: ['SABnzbd_nzo_bbb']);

    $this->actingAs($this->admin)
        ->postJson(route('sabnzbd.queue.bulk'), ['ids' => ['SABnzbd_nzo_aaa', 'SABnzbd_nzo_bbb'], 'action' => $action])
        ->assertJsonPath('started', 1)
        ->assertJsonPath('failed', [['id' => 'SABnzbd_nzo_bbb', 'title' => 'Show.S01E02.mkv', 'reason' => 'SABnzbd refused the change.']])
        ->assertJsonPath('toast.type', 'error');

    expect(ActivityLog::query()->where('action', $activityAction)->get()->pluck('metadata.nzo_id')->all())
        ->toBe(['SABnzbd_nzo_aaa']);

    if ($action === 'delete') {
        $auditLog = ActivityLog::query()->where('category', 'audit')->where('action', 'sabnzbd.slot_deleted')->sole();

        expect($auditLog->metadata['context'])->toBe(['nzo_id' => 'SABnzbd_nzo_aaa']);
    }
})->with([
    'pause' => ['pause', 'sabnzbd.slot.paused'],
    'resume' => ['resume', 'sabnzbd.slot.resumed'],
    'delete' => ['delete', 'sabnzbd.slot.deleted'],
]);

test('an outage short-circuits the rest of the batch after the first unreachable id', function (): void {
    Sleep::fake();
    $slotAttempts = 0;

    Http::fake(['sabnzbd.local:8080/api*' => function (Request $request) use (&$slotAttempts) {
        $query = sabnzbdQuery($request);

        if (($query['mode'] ?? null) === 'queue' && isset($query['name'])) {
            $slotAttempts++;

            throw new ConnectionException('Connection refused.');
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

    $this->actingAs($this->admin)
        ->postJson(route('sabnzbd.queue.bulk'), ['ids' => ['SABnzbd_nzo_aaa', 'SABnzbd_nzo_bbb', 'SABnzbd_nzo_ccc'], 'action' => 'pause'])
        ->assertJsonPath('started', 0)
        ->assertJsonPath('failed', [
            ['id' => 'SABnzbd_nzo_aaa', 'title' => 'Show.S01E01.mkv', 'reason' => 'SABnzbd is unreachable right now.'],
            ['id' => 'SABnzbd_nzo_bbb', 'title' => 'Show.S01E02.mkv', 'reason' => 'SABnzbd is unreachable right now.'],
            ['id' => 'SABnzbd_nzo_ccc', 'title' => 'Movie.2021.mkv', 'reason' => 'SABnzbd is unreachable right now.'],
        ]);

    // Only the first id's pause command (and its internal HTTP-client
    // retries) reaches SABnzbd; the rest are failed locally.
    expect($slotAttempts)->toBe(3);
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

test('an invalid bulk payload is refused before SABnzbd is called', function (array $ids, string $action): void {
    fakeSabnzbdBulk();

    $this->actingAs($this->admin)
        ->postJson(route('sabnzbd.queue.bulk'), ['ids' => $ids, 'action' => $action])
        ->assertUnprocessable();

    Http::assertNothingSent();
})->with([
    'duplicate id' => [['SABnzbd_nzo_aaa', 'SABnzbd_nzo_aaa'], 'pause'],
    '101 ids' => [array_map(static fn (int $n): string => sprintf('SABnzbd_nzo_x%d', $n), range(1, 101)), 'pause'],
    'empty list' => [[], 'pause'],
    'a non-string id' => [[123], 'pause'],
    'an unknown action' => [['SABnzbd_nzo_aaa'], 'purge'],
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
