<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, mixed>
 */
function grabQueueBrowserRecord(int $id, string $series): array
{
    return [
        'id' => $id, 'title' => sprintf('%s.S01E01.1080p', $series), 'series' => ['title' => $series],
        'episode' => ['seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Pilot'],
        'status' => 'downloading', 'trackedDownloadStatus' => 'ok', 'trackedDownloadState' => 'downloading',
        'protocol' => 'usenet', 'downloadClient' => 'SABnzbd', 'size' => 1_000_000_000, 'sizeleft' => 500_000_000,
        'timeleft' => '00:05:00', 'statusMessages' => [], 'added' => '2026-09-29T10:00:00Z',
        'quality' => ['quality' => ['name' => 'WEBDL-1080p']], 'downloadId' => sprintf('SABnzbd_nzo_%d', $id),
    ];
}

function fakeGrabQueueBrowser(): void
{
    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => fn (Request $request) => $request->method() === 'DELETE'
            ? Http::response('', 200)
            : Http::response(['records' => [grabQueueBrowserRecord(41, 'Severance'), grabQueueBrowserRecord(42, 'Andor')]]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => [], 'totalRecords' => 0]),
        'sonarr.local:8989/api/v3/manualimport*' => Http::response([[
            'path' => '/downloads/Severance.S01E01.mkv', 'name' => 'Severance.S01E01', 'size' => 1_000_000_000,
            'series' => ['id' => 12, 'title' => 'Severance'], 'seasonNumber' => 1,
            'episodes' => [['id' => 555, 'seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Pilot']],
            'quality' => ['quality' => ['id' => 4, 'name' => 'WEBDL-1080p']], 'languages' => [['id' => 1, 'name' => 'English']],
            'releaseGroup' => 'GROUP', 'releaseType' => 'singleEpisode', 'rejections' => [],
        ]]),
        'sonarr.local:8989/api/v3/command' => Http::response(['id' => 99]),
    ]);
}

beforeEach(function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
});

test('an admin removes two Sonarr queue items in bulk', function (): void {
    fakeGrabQueueBrowser();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-queue-row="sonarr-41"]', 'Severance')
        ->click('[data-service-filter="sonarr"]')
        ->click('[data-bulk-select="41"]')
        ->click('[data-bulk-select="42"]')
        ->assertSeeIn('[data-bulk-count]', '2 selected')
        ->click('[data-bulk-queue-action="remove"]')
        ->assertSee('Remove 2 items from the queue?')
        ->assertSeeIn('[data-bulk-queue-confirm]', 'Confirm')
        ->click('[data-bulk-queue-confirm]')
        ->assertSee('2 removed')
        ->assertMissing('[data-bulk-bar]')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), '/api/v3/queue/41?'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), '/api/v3/queue/42?'));
});

test('with both services shown the page asks to pick one before selecting', function (): void {
    fakeGrabQueueBrowser();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-queue-row="sonarr-41"]', 'Severance')
        ->assertPresent('[data-queue-bulk-hint]')
        ->assertCount('[data-bulk-select]', 0);
});

test('a member sees no queue selection', function (): void {
    fakeGrabQueueBrowser();
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-queue-row="sonarr-41"]', 'Severance')
        ->click('[data-service-filter="sonarr"]')
        ->assertCount('[data-bulk-select]', 0);
});

test('the selection clears when the service filter changes, even though the same id stays visible', function (): void {
    // id 41 exists in both queues: if the selection only dropped ids that
    // vanished from the page (retain()), switching to Radarr would keep it
    // selected. Only the serviceFilter reset in useBulkSelection clears it.
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);

    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => fn (Request $request) => $request->method() === 'DELETE'
            ? Http::response('', 200)
            : Http::response(['records' => [grabQueueBrowserRecord(41, 'Severance'), grabQueueBrowserRecord(42, 'Andor')]]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => [], 'totalRecords' => 0]),
        'radarr.local:7878/api/v3/queue*' => fn (Request $request) => $request->method() === 'DELETE'
            ? Http::response('', 200)
            : Http::response(['records' => [grabQueueBrowserRecord(41, 'Dune')]]),
        'radarr.local:7878/api/v3/history*' => Http::response(['records' => [], 'totalRecords' => 0]),
    ]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->click('[data-service-filter="sonarr"]')
        ->click('[data-bulk-select="41"]')
        ->assertSeeIn('[data-bulk-count]', '1 selected')
        ->click('[data-service-filter="radarr"]')
        ->assertPresent('[data-queue-row="radarr-41"]')
        ->assertDontSee('1 selected');
});

test('the selection clears when a refresh renders another connection with the same queue ids', function (): void {
    // Queue ids overlap across instances: if only vanished ids were dropped
    // (retain()), the selection would survive the switch and Confirm would
    // remove the second instance's items under the new pin.
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr-4k.local:8989', 'api_key' => 'k']);
    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => Http::response(['records' => [grabQueueBrowserRecord(41, 'Severance')]]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => [], 'totalRecords' => 0]),
        'sonarr-4k.local:8989/api/v3/queue*' => Http::response(['records' => [grabQueueBrowserRecord(41, 'Andor')]]),
        'sonarr-4k.local:8989/api/v3/history*' => Http::response(['records' => [], 'totalRecords' => 0]),
    ]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-queue-row="sonarr-41"]', 'Severance')
        ->click('[data-service-filter="sonarr"]')
        ->click('[data-bulk-select="41"]')
        ->assertSeeIn('[data-bulk-count]', '1 selected');

    // Bypasses the observer on purpose: no health ping, just the state change.
    ServiceConnection::query()->where('url', 'http://sonarr.local:8989')->update(['is_active' => false]);

    $webpage->click('[data-activity-refresh]')
        ->assertSeeIn('[data-queue-row="sonarr-41"]', 'Andor')
        ->assertMissing('[data-bulk-bar]')
        ->assertDontSee('1 selected');
});

test('the selection clears when switching to the history tab and back', function (): void {
    fakeGrabQueueBrowser();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->click('[data-service-filter="sonarr"]')
        ->click('[data-bulk-select="41"]')
        ->assertSeeIn('[data-bulk-count]', '1 selected')
        ->click('[data-activity-tab="history"]')
        ->click('[data-activity-tab="queue"]')
        ->click('[data-service-filter="sonarr"]')
        ->assertDontSee('1 selected');
});

test('the remove confirm closes when the selection empties under it, and nothing is sent', function (): void {
    fakeGrabQueueBrowser();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-queue-row="sonarr-41"]', 'Severance')
        ->click('[data-service-filter="sonarr"]')
        ->click('[data-bulk-select="41"]')
        ->click('[data-bulk-queue-action="remove"]')
        ->assertSeeIn('[data-bulk-queue-confirm]', 'Confirm');

    // Unticking the only row behind the dialog (what a refresh that drops
    // the row does) empties the selection.
    $webpage->script("document.querySelector('[data-bulk-select=\"41\"]').click()");
    // The dialog's exit animation keeps it in the DOM briefly.
    $webpage->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 250; attempt++) {
                if (!document.querySelector('[data-bulk-queue-confirm]')) {
                    return;
                }
                await new Promise((resolve) => setTimeout(resolve, 20));
            }
        })()
    JS);
    $webpage->assertCount('[data-bulk-queue-confirm]', 0);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('an admin removes one queue item from its row menu', function (): void {
    fakeGrabQueueBrowser();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-queue-row="sonarr-41"]', 'Severance');

    $webpage->click('[data-queue-row="sonarr-41"] [data-queue-row-menu]')
        ->click('[data-queue-remove="remove"]')
        ->assertSeeIn('[data-confirm-dialog] [data-slot="dialog-title"]', 'from the queue?')
        ->click('[data-confirm-accept]')
        ->assertSee('Removed from queue.')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), '/api/v3/queue/41?'));
});

test('a row removal whose connection was deactivated after the page loaded is refused and sends nothing', function (): void {
    fakeGrabQueueBrowser();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-queue-row="sonarr-41"]', 'Severance');

    // Bypasses the observer on purpose: no health ping, just the state change.
    ServiceConnection::query()->where('type', 'sonarr')->update(['is_active' => false]);

    $webpage->click('[data-queue-row="sonarr-41"] [data-queue-row-menu]')
        ->click('[data-queue-remove="remove"]')
        ->assertSeeIn('[data-confirm-dialog] [data-slot="dialog-title"]', 'from the queue?')
        ->click('[data-confirm-accept]')
        ->assertSee('That Sonarr connection is unavailable — refresh and try again.');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('a bulk removal whose connection was deactivated after the page loaded is refused and sends nothing', function (): void {
    fakeGrabQueueBrowser();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-queue-row="sonarr-41"]', 'Severance')
        ->click('[data-service-filter="sonarr"]')
        ->click('[data-bulk-select="41"]')
        ->click('[data-bulk-queue-action="remove"]')
        ->assertSeeIn('[data-bulk-queue-confirm]', 'Confirm');

    ServiceConnection::query()->where('type', 'sonarr')->update(['is_active' => false]);

    $webpage->click('[data-bulk-queue-confirm]')
        ->assertSee('That Sonarr connection is unavailable — refresh and try again.');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('an admin force-grabs one queue item from its row menu', function (): void {
    fakeGrabQueueBrowser();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-queue-row="sonarr-41"]', 'Severance');

    $webpage->click('[data-queue-row="sonarr-41"] [data-queue-row-menu]')
        ->click('[data-queue-grab]')
        ->assertSeeIn('[data-confirm-dialog]', 'Force grab')
        ->click('[data-confirm-accept]')
        ->assertSee('Grab triggered.')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/api/v3/queue/grab/41'));
});

test('a force grab whose connection was deactivated after the page loaded is refused and sends nothing', function (): void {
    fakeGrabQueueBrowser();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-queue-row="sonarr-41"]', 'Severance');

    // Bypasses the observer on purpose: no health ping, just the state change.
    ServiceConnection::query()->where('type', 'sonarr')->update(['is_active' => false]);

    $webpage->click('[data-queue-row="sonarr-41"] [data-queue-row-menu]')
        ->click('[data-queue-grab]')
        ->assertSeeIn('[data-confirm-dialog]', 'Force grab')
        ->click('[data-confirm-accept]')
        ->assertSee('That Sonarr connection is unavailable — refresh and try again.');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/queue/grab/'));
});

test('an admin imports a stuck download from its row menu', function (): void {
    fakeGrabQueueBrowser();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-queue-row="sonarr-41"]', 'Severance');

    $webpage->click('[data-queue-row="sonarr-41"] [data-queue-row-menu]')
        ->click('[data-queue-manual-import]')
        ->assertSeeIn('[data-manual-import-candidate]', 'Severance · S01E01')
        ->click('[data-manual-import-submit]')
        ->assertSee('Manual import queued (1 file(s)).')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && str_contains($request->url(), '/api/v3/manualimport?downloadId=SABnzbd_nzo_41'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/api/v3/command') && $request['name'] === 'ManualImport');
});

test('a manual import whose connection was deactivated after the page loaded shows the refusal in the dialog and sends nothing', function (): void {
    fakeGrabQueueBrowser();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-queue-row="sonarr-41"]', 'Severance');

    ServiceConnection::query()->where('type', 'sonarr')->update(['is_active' => false]);

    $webpage->click('[data-queue-row="sonarr-41"] [data-queue-row-menu]')
        ->click('[data-queue-manual-import]')
        ->assertSeeIn('[data-manual-import-error]', 'That Sonarr connection is unavailable — refresh and try again.')
        ->assertMissing('[data-manual-import-candidate]');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/manualimport'));
});

test('cancelling a blocklist removal or a force grab sends nothing', function (): void {
    fakeGrabQueueBrowser();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-queue-row="sonarr-41"]', 'Severance');

    $webpage->click('[data-queue-row="sonarr-41"] [data-queue-row-menu]')
        ->click('[data-queue-remove="block"]')
        ->assertSeeIn('[data-confirm-dialog]', 'blocklist the release?')
        ->assertSeeIn('[data-confirm-dialog]', 'A fresh search runs afterwards.')
        ->click('[data-confirm-cancel]');
    $webpage->script(confirmDialogGoneScript());

    $webpage->click('[data-queue-row="sonarr-41"] [data-queue-row-menu]')
        ->click('[data-queue-grab]')
        ->assertSeeIn('[data-confirm-dialog]', 'This bypasses the RSS sync delay.')
        ->click('[data-confirm-cancel]');
    $webpage->script(confirmDialogGoneScript());
    $webpage->assertCount('[data-confirm-dialog]', 0)
        ->assertNoSmoke();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE' || str_contains($request->url(), '/api/v3/queue/grab/'));
});

test('an admin blocklists one queue item from its row menu', function (): void {
    fakeGrabQueueBrowser();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-queue-row="sonarr-41"]', 'Severance');

    $webpage->click('[data-queue-row="sonarr-41"] [data-queue-row-menu]')
        ->click('[data-queue-remove="block"]')
        ->assertSeeIn('[data-confirm-dialog]', 'blocklist the release?')
        ->assertSeeIn('[data-confirm-dialog]', 'A fresh search runs afterwards.')
        ->click('[data-confirm-accept]')
        ->assertSee('Removed and blocklisted; a fresh search will run.')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/41?')
        && str_contains($request->url(), 'blocklist=true'));
});
