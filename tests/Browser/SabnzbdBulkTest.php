<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Sabnzbd\SabnzbdDownloadCounter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, string>
 */
function sabnzbdBrowserQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

/**
 * @return array<string, mixed>
 */
function sabnzbdBrowserSlot(string $nzoId, string $filename): array
{
    return ['nzo_id' => $nzoId, 'filename' => $filename, 'cat' => 'tv', 'size' => '1.0 GB', 'sizeleft' => '0.5 GB', 'percentage' => '50', 'timeleft' => '0:05:00', 'status' => 'Downloading', 'priority' => '0'];
}

/**
 * Fakes every SABnzbd call the page makes: the queue (polled every 5 s), the
 * history and every slot command. `$slotsForCall` gets the 1-based number
 * of the queue read, so a test can change the queue between polls.
 *
 * @param  Closure(int): list<array<string, mixed>>  $slotsForCall
 */
function fakeSabnzbdBrowser(Closure $slotsForCall): void
{
    $queueReads = 0;

    Http::fake(['sabnzbd.local:8080/api*' => function (Request $request) use (&$queueReads, $slotsForCall) {
        $query = sabnzbdBrowserQuery($request);

        if (($query['mode'] ?? null) === 'queue' && isset($query['name'])) {
            return Http::response(['status' => true]);
        }

        if (($query['mode'] ?? null) === 'queue') {
            $queueReads++;

            return Http::response(['queue' => ['paused' => false, 'speed' => '10 M', 'speedlimit' => '', 'speedlimit_abs' => '', 'slots' => $slotsForCall($queueReads)]]);
        }

        if (($query['mode'] ?? null) === 'history') {
            return Http::response(['history' => ['slots' => [], 'noofslots' => 0]]);
        }

        return Http::response(['status' => true]);
    }]);
}

beforeEach(function (): void {
    Cache::put(SabnzbdDownloadCounter::CACHE_KEY, ['queued' => 0, 'completed' => 0], 60);
    ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sabnzbd.local:8080', 'api_key' => 'k']);
});

test('an admin pauses two downloads in bulk', function (): void {
    fakeSabnzbdBrowser(fn (int $read): array => [
        sabnzbdBrowserSlot('SABnzbd_nzo_aaa', 'Show.S01E01.mkv'),
        sabnzbdBrowserSlot('SABnzbd_nzo_bbb', 'Show.S01E02.mkv'),
    ]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('sabnzbd.queue.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-sab-slot="SABnzbd_nzo_aaa"]', 'Show.S01E01.mkv')
        ->click('[data-bulk-select="SABnzbd_nzo_aaa"]')
        ->click('[data-bulk-select="SABnzbd_nzo_bbb"]')
        ->assertSeeIn('[data-bulk-count]', '2 selected')
        ->click('[data-bulk-sab-action="pause"]')
        ->assertSee('2 paused')
        ->assertCount('[data-bulk-bar]', 0)
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => (sabnzbdBrowserQuery($request)['name'] ?? null) === 'pause' && (sabnzbdBrowserQuery($request)['value'] ?? null) === 'SABnzbd_nzo_bbb');
});

test('a bulk delete asks first', function (): void {
    fakeSabnzbdBrowser(fn (int $read): array => [sabnzbdBrowserSlot('SABnzbd_nzo_aaa', 'Show.S01E01.mkv')]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('sabnzbd.queue.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-sab-slot="SABnzbd_nzo_aaa"]', 'Show.S01E01.mkv')
        ->click('[data-bulk-select="SABnzbd_nzo_aaa"]')
        ->click('[data-bulk-sab-action="delete"]')
        ->assertVisible('[data-bulk-sab-delete-confirm]')
        ->assertSee('Delete 1')
        ->click('[data-bulk-sab-delete-confirm]')
        ->assertSee('1 deleted');

    Http::assertSent(fn (Request $request): bool => (sabnzbdBrowserQuery($request)['name'] ?? null) === 'delete');
});

test('the selection clears after navigating away and back', function (): void {
    fakeSabnzbdBrowser(fn (int $read): array => [
        sabnzbdBrowserSlot('SABnzbd_nzo_aaa', 'Show.S01E01.mkv'),
        sabnzbdBrowserSlot('SABnzbd_nzo_bbb', 'Show.S01E02.mkv'),
    ]);
    $this->actingAs(User::factory()->admin()->create());

    $pendingAwaitablePage = visit(route('sabnzbd.queue.index', absolute: false));

    $pendingAwaitablePage->assertNoSmoke()
        ->click('[data-bulk-select="SABnzbd_nzo_aaa"]')
        ->assertSeeIn('[data-bulk-count]', '1 selected');

    $pendingAwaitablePage->navigate(route('dashboard', absolute: false))->assertNoSmoke();

    $pendingAwaitablePage->navigate(route('sabnzbd.queue.index', absolute: false))
        ->assertNoSmoke()
        ->assertCount('[data-bulk-bar]', 0);
});

test('a selected slot that finishes during the poll drops out of the selection', function (): void {
    fakeSabnzbdBrowser(fn (int $read): array => $read === 1
        ? [sabnzbdBrowserSlot('SABnzbd_nzo_aaa', 'Show.S01E01.mkv'), sabnzbdBrowserSlot('SABnzbd_nzo_bbb', 'Show.S01E02.mkv')]
        : [sabnzbdBrowserSlot('SABnzbd_nzo_aaa', 'Show.S01E01.mkv')]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('sabnzbd.queue.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-sab-slot="SABnzbd_nzo_bbb"]', 'Show.S01E02.mkv')
        ->click('[data-bulk-select="SABnzbd_nzo_aaa"]')
        ->click('[data-bulk-select="SABnzbd_nzo_bbb"]')
        ->assertSeeIn('[data-bulk-count]', '2 selected')
        // The 5-second poll returns a queue without bbb.
        ->assertSeeIn('[data-bulk-count]', '1 selected')
        ->click('[data-bulk-sab-action="pause"]')
        ->assertSee('1 paused');

    Http::assertNotSent(fn (Request $request): bool => (sabnzbdBrowserQuery($request)['name'] ?? null) === 'pause' && (sabnzbdBrowserQuery($request)['value'] ?? null) === 'SABnzbd_nzo_bbb');
});

test('a member sees no slot selection', function (): void {
    fakeSabnzbdBrowser(fn (int $read): array => [sabnzbdBrowserSlot('SABnzbd_nzo_aaa', 'Show.S01E01.mkv')]);
    $this->actingAs(User::factory()->member()->create());

    visit(route('sabnzbd.queue.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-sab-slot="SABnzbd_nzo_aaa"]', 'Show.S01E01.mkv')
        ->assertCount('[data-bulk-select]', 0);
});
