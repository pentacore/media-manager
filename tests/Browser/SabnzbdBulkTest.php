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

test('the delete confirm closes when a poll empties the selection', function (): void {
    fakeSabnzbdBrowser(fn (int $read): array => $read === 1
        ? [sabnzbdBrowserSlot('SABnzbd_nzo_aaa', 'Show.S01E01.mkv')]
        : []);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('sabnzbd.queue.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-sab-slot="SABnzbd_nzo_aaa"]', 'Show.S01E01.mkv')
        ->click('[data-bulk-select="SABnzbd_nzo_aaa"]')
        ->click('[data-bulk-sab-action="delete"]')
        ->assertVisible('[data-bulk-sab-delete-confirm]');

    // The 5-second poll returns an empty queue: the job finished.
    $webpage->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 500; attempt++) {
                if (!document.querySelector('[data-bulk-sab-delete-confirm]')) {
                    return;
                }
                await new Promise((resolve) => setTimeout(resolve, 20));
            }
        })()
    JS);

    $webpage->assertCount('[data-bulk-sab-delete-confirm]', 0)
        ->assertCount('[data-bulk-bar]', 0);

    Http::assertNotSent(fn (Request $request): bool => (sabnzbdBrowserQuery($request)['name'] ?? null) === 'delete');
});

test('a failed poll keeps the selection; the next good poll still has it', function (): void {
    $queueReads = 0;

    Http::fake(['sabnzbd.local:8080/api*' => function (Request $request) use (&$queueReads) {
        $query = sabnzbdBrowserQuery($request);

        if (($query['mode'] ?? null) === 'queue' && isset($query['name'])) {
            return Http::response(['status' => true]);
        }

        if (($query['mode'] ?? null) === 'queue') {
            $queueReads++;

            // The second page load (the first poll) hits a SABnzbd restart.
            if ($queueReads === 2) {
                return Http::response(['error' => 'restarting'], 401);
            }

            return Http::response(['queue' => ['paused' => false, 'speed' => '10 M', 'speedlimit' => '', 'speedlimit_abs' => '', 'slots' => [
                sabnzbdBrowserSlot('SABnzbd_nzo_aaa', 'Show.S01E01.mkv'),
                sabnzbdBrowserSlot('SABnzbd_nzo_bbb', 'Show.S01E02.mkv'),
            ]]]);
        }

        return Http::response(['history' => ['slots' => [], 'noofslots' => 0]]);
    }]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('sabnzbd.queue.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-sab-slot="SABnzbd_nzo_aaa"]', 'Show.S01E01.mkv')
        ->click('[data-bulk-select="SABnzbd_nzo_aaa"]')
        ->click('[data-bulk-select="SABnzbd_nzo_bbb"]')
        ->assertSeeIn('[data-bulk-count]', '2 selected')
        // The bar is hidden while the error shows; the selection is not.
        ->assertSeeIn('[data-sabnzbd-error]', 'Could not reach SABnzbd.')
        // The next poll recovers, and both slots are still selected.
        ->assertSeeIn('[data-sab-slot="SABnzbd_nzo_bbb"]', 'Show.S01E02.mkv')
        ->assertSeeIn('[data-bulk-count]', '2 selected')
        ->click('[data-bulk-sab-action="pause"]')
        ->assertSee('2 paused');
});

test('a single queue delete asks first; Cancel keeps the job and Remove deletes it', function (): void {
    fakeSabnzbdBrowser(fn (int $read): array => [sabnzbdBrowserSlot('SABnzbd_nzo_aaa', 'Show.S01E01.mkv')]);
    $this->actingAs(User::factory()->admin()->create());
    $delete = '[data-sab-slot="SABnzbd_nzo_aaa"] [data-sab-slot-delete]';
    $isSlotDelete = fn (Request $request): bool => (sabnzbdBrowserQuery($request)['name'] ?? null) === 'delete'
        && (sabnzbdBrowserQuery($request)['value'] ?? null) === 'SABnzbd_nzo_aaa';

    $webpage = visit(route('sabnzbd.queue.index', absolute: false))
        ->assertNoSmoke()
        ->click($delete)
        ->assertSeeIn('[data-confirm-dialog] [data-slot="dialog-title"]', 'Remove "Show.S01E01.mkv" from the queue?')
        ->click('[data-confirm-cancel]');

    $webpage->script(confirmDialogGoneScript());
    Http::assertNotSent($isSlotDelete);

    $webpage->click($delete)
        ->click('[data-confirm-accept]')
        ->assertSee('Job deleted.')
        ->assertNoSmoke();

    Http::assertSent($isSlotDelete);
});
