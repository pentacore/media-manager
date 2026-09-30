<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, string>
 */
function sabnzbdDownloadsQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

function fakeSabnzbdDownloads(): void
{
    Http::fake([
        'sab.local:8080/api*' => function (Request $request) {
            $query = sabnzbdDownloadsQuery($request);

            return match (true) {
                $query['mode'] === 'queue' => Http::response(['queue' => [
                    'paused' => false, 'speed' => '1.2 M', 'sizeleft' => '0 B', 'timeleft' => '0:00:00',
                    'speedlimit' => '100', 'speedlimit_abs' => '', 'noofslots' => 0, 'slots' => [],
                ]]),
                $query['mode'] === 'history' && ($query['name'] ?? null) === 'delete' => Http::response(['status' => true]),
                $query['mode'] === 'history' => Http::response(['history' => [
                    'noofslots' => 120,
                    'slots' => match (true) {
                        (int) ($query['start'] ?? 0) === 50 => [['nzo_id' => 'SABnzbd_nzo_page2', 'name' => 'Andor.S02E01.1080p', 'category' => 'tv', 'status' => 'Completed', 'fail_message' => '']],
                        (int) ($query['start'] ?? 0) >= 150 => [],
                        default => [
                            ['nzo_id' => 'SABnzbd_nzo_failed1', 'name' => 'Severance.S02E07.1080p', 'category' => 'tv', 'status' => 'Failed', 'fail_message' => 'Repair failed'],
                            ['nzo_id' => 'SABnzbd_nzo_done1', 'name' => 'Dune.Part.Two.2024', 'category' => 'movies', 'status' => 'Completed', 'fail_message' => ''],
                        ],
                    },
                ]]),
                in_array($query['mode'], ['config', 'retry'], true) => Http::response(['status' => true]),
                default => Http::response(['status' => false], 404),
            };
        },
    ]);
}

beforeEach(function (): void {
    ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sab.local:8080', 'api_key' => 'k']);
    fakeSabnzbdDownloads();
});

test('an admin sets a preset and a custom speed limit', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('sabnzbd.queue.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-speed-limit-current]', 'No limit')
        ->click('[data-speed-limit-trigger]')
        ->click('[data-speed-limit-preset="50"]')
        ->assertSee('Speed limit set to 50%.')
        ->click('[data-speed-limit-trigger]')
        ->click('[data-speed-limit-custom]')
        ->fill('[data-speed-limit-input]', '5m')
        ->click('[data-speed-limit-save]')
        ->assertSee('Speed limit set to 5 MB/s.')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => (sabnzbdDownloadsQuery($request)['name'] ?? null) === 'speedlimit' && sabnzbdDownloadsQuery($request)['value'] === '50');
    Http::assertSent(fn (Request $request): bool => (sabnzbdDownloadsQuery($request)['name'] ?? null) === 'speedlimit' && sabnzbdDownloadsQuery($request)['value'] === '5M');
});

test('an admin pages through history, retries a failed job and removes one with its files', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('sabnzbd.queue.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-history-row="SABnzbd_nzo_failed1"]', 'Severance.S02E07.1080p')
        ->assertSeeIn('[data-history-page]', 'Page 1 of 3')
        ->assertCount('[data-history-row="SABnzbd_nzo_done1"] [data-history-retry]', 0)
        ->click('[data-history-row="SABnzbd_nzo_failed1"] [data-history-retry]')
        ->assertSee('Retry queued.')
        ->click('[data-history-row="SABnzbd_nzo_done1"] [data-history-delete]')
        ->assertSeeIn('[data-history-delete-dialog]', 'Dune.Part.Two.2024')
        ->click('[data-history-delete-files]')
        ->assertSee('History entry and files removed.')
        ->click('[data-history-next]')
        ->assertSeeIn('[data-history-row="SABnzbd_nzo_page2"]', 'Andor.S02E01.1080p')
        ->assertSeeIn('[data-history-page]', 'Page 2 of 3')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => (sabnzbdDownloadsQuery($request)['mode'] ?? null) === 'retry' && sabnzbdDownloadsQuery($request)['value'] === 'SABnzbd_nzo_failed1');
    Http::assertSent(fn (Request $request): bool => (sabnzbdDownloadsQuery($request)['name'] ?? null) === 'delete'
        && sabnzbdDownloadsQuery($request)['value'] === 'SABnzbd_nzo_done1'
        && (sabnzbdDownloadsQuery($request)['del_files'] ?? null) === '1');
});

test('a member sees the history without any admin controls', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit(route('sabnzbd.queue.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-history-row="SABnzbd_nzo_failed1"]', 'Severance.S02E07.1080p')
        ->assertMissing('[data-speed-limit-trigger]')
        ->assertMissing('[data-history-retry]')
        ->assertMissing('[data-history-delete]');
});

test('a history page past the end still shows the pager and leads back to the last page', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit(route('sabnzbd.queue.index', ['history_page' => 5], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-sabnzbd-history]', 'No downloads on this page.')
        ->assertSeeIn('[data-history-page]', 'Page 5 of 3')
        ->click('[data-history-prev]')
        ->assertSeeIn('[data-history-page]', 'Page 3 of 3')
        ->assertSeeIn('[data-history-row="SABnzbd_nzo_failed1"]', 'Severance.S02E07.1080p');
});

test('a poll that hits a SABnzbd outage shows the error and a recovered poll clears it', function (): void {
    $sabnzbdDown = false;
    // A distinct host so this fake wins outright over the beforeEach's
    // sab.local stub (Http::fake matches the first-registered pattern).
    ServiceConnection::query()->where('type', 'sabnzbd')->update(['url' => 'http://sab-flaky.local:8080']);
    Http::fake([
        'sab-flaky.local:8080/api*' => function (Request $request) use (&$sabnzbdDown) {
            if ($sabnzbdDown) {
                return Http::response('Service Unavailable', 503);
            }

            return sabnzbdDownloadsQuery($request)['mode'] === 'queue'
                ? Http::response(['queue' => ['paused' => false, 'noofslots' => 0, 'slots' => []]])
                : Http::response(['history' => ['noofslots' => 1, 'slots' => [
                    ['nzo_id' => 'SABnzbd_nzo_flaky', 'name' => 'Slow.Horses.S04E01', 'category' => 'tv', 'status' => 'Completed', 'fail_message' => ''],
                ]]]);
        },
    ]);
    $this->actingAs(User::factory()->member()->create());

    $page = visit(route('sabnzbd.queue.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-history-row="SABnzbd_nzo_flaky"]', 'Slow.Horses.S04E01');

    $sabnzbdDown = true;

    $page->assertSeeIn('[data-sabnzbd-error]', 'Could not reach SABnzbd.')
        ->assertMissing('[data-sabnzbd-history]');

    $sabnzbdDown = false;

    $page->assertSeeIn('[data-history-row="SABnzbd_nzo_flaky"]', 'Slow.Horses.S04E01')
        ->assertMissing('[data-sabnzbd-error]');
});

test('a double-clicked retry posts once', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $page = visit(route('sabnzbd.queue.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-history-row="SABnzbd_nzo_failed1"]', 'Severance.S02E07.1080p');

    // Two synchronous clicks land before Vue re-renders the button disabled,
    // so only the handler's own in-flight guard can stop the second post.
    $page->script(<<<'JS'
        (() => {
            const retry = document.querySelector('[data-history-row="SABnzbd_nzo_failed1"] [data-history-retry]');
            retry.click();
            retry.click();
        })()
        JS);

    $page->assertSee('Retry queued.')->assertNoSmoke();

    expect(collect(Http::recorded())->filter(fn (array $pair): bool => (sabnzbdDownloadsQuery($pair[0])['mode'] ?? null) === 'retry'))->toHaveCount(1);
});
