<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, mixed>
 */
function grabQueueHistoryRecord(int $id, string $eventType, string $title): array
{
    return [
        'id' => $id,
        'eventType' => $eventType,
        'sourceTitle' => sprintf('%s.1080p.WEB', str_replace(' ', '.', $title)),
        'series' => ['title' => $title],
        'episode' => ['seasonNumber' => 1, 'episodeNumber' => $id, 'title' => 'Episode '.$id],
        'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
        'date' => '2026-09-28T08:00:00Z',
    ];
}

beforeEach(function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);

    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => Http::response(['records' => []]),
        'radarr.local:7878/api/v3/queue*' => Http::response(['records' => []]),
        'sonarr.local:8989/api/v3/history/failed/*' => Http::response('', 200),
        'sonarr.local:8989/api/v3/history*' => fn (Request $request) => Http::response(str_contains($request->url(), 'page=2&')
            ? ['totalRecords' => 60, 'records' => [grabQueueHistoryRecord(99, 'downloadFolderImported', 'Andor')]]
            : ['totalRecords' => 60, 'records' => [
                grabQueueHistoryRecord(55, 'grabbed', 'Severance'),
                grabQueueHistoryRecord(56, 'downloadFolderImported', 'Slow Horses'),
            ]]),
        'radarr.local:7878/api/v3/history*' => Http::response(['totalRecords' => 1, 'records' => [[
            'id' => 7, 'eventType' => 'downloadFailed', 'sourceTitle' => 'Dune.2021.mkv',
            'movie' => ['title' => 'Dune', 'year' => 2021], 'date' => '2026-09-28T09:00:00Z',
        ]]]),
    ]);
});

test('an admin pages Sonarr history and marks a grab as failed', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->click('[data-activity-tab="history"]')
        ->assertSeeIn('[data-history-row="sonarr-55"] [data-history-title]', 'Severance')
        ->assertCount('[data-history-row="sonarr-56"] [data-history-mark-failed]', 0)
        ->assertSeeIn('[data-history-page]', 'Page 1 of 2')
        ->click('[data-history-row="sonarr-55"] [data-history-mark-failed]')
        ->assertSeeIn('[data-confirm-dialog]', 'as failed?')
        ->click('[data-confirm-accept]')
        ->assertSee("Marked as failed — Sonarr will blocklist the release and search again if 'Redownload failed' is on.")
        ->click('[data-history-next]')
        ->assertSeeIn('[data-history-row="sonarr-99"] [data-history-title]', 'Andor')
        ->assertSeeIn('[data-history-page]', 'Page 2 of 2')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/api/v3/history/failed/55'));
});

test('the Radarr tab shows Radarr history', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->click('[data-activity-tab="history"]')
        ->click('[data-history-service-tab="radarr"]')
        ->assertSeeIn('[data-history-row="radarr-7"] [data-history-title]', 'Dune')
        ->assertMissing('[data-history-row="sonarr-55"]');
});

test('members see history without the mark failed control', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.library.activity.queue', ['history_service' => 'sonarr'], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-history-row="sonarr-55"] [data-history-title]', 'Severance')
        ->assertMissing('[data-history-mark-failed]');
});

test('a Sonarr outage shows the history error instead of an empty table', function (): void {
    // Point Sonarr at a distinct host so this fake wins outright instead of
    // competing with the beforeEach's sonarr.local stub for the same URL
    // (Http::fake matches the first-registered pattern, not the latest).
    ServiceConnection::query()->where('type', 'sonarr')->update(['url' => 'http://sonarr-down.local:8989']);
    Http::fake([
        'sonarr-down.local:8989/api/v3/queue*' => Http::response(['records' => []]),
        'sonarr-down.local:8989/api/v3/history*' => Http::response('Service Unavailable', 503),
    ]);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.library.activity.queue', ['history_service' => 'sonarr'], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-history-error]', 'Sonarr is unreachable right now')
        ->assertMissing('[data-history-empty]');
});

test('a history page past the end still shows the pager and leads back to the last page', function (): void {
    // A distinct host so these fakes win outright over the beforeEach's
    // sonarr.local stubs (Http::fake matches the first-registered pattern).
    ServiceConnection::query()->where('type', 'sonarr')->update(['url' => 'http://sonarr-paged.local:8989']);
    Http::fake([
        'sonarr-paged.local:8989/api/v3/queue*' => Http::response(['records' => []]),
        'sonarr-paged.local:8989/api/v3/history*' => fn (Request $request) => Http::response(str_contains($request->url(), 'page=1&')
            ? ['totalRecords' => 40, 'records' => [grabQueueHistoryRecord(99, 'downloadFolderImported', 'Andor')]]
            : ['totalRecords' => 40, 'records' => []]),
    ]);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.library.activity.queue', ['history_service' => 'sonarr', 'history_page' => 5], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-history-page]', 'Page 5 of 1')
        ->click('[data-history-prev]')
        ->assertSeeIn('[data-history-row="sonarr-99"] [data-history-title]', 'Andor')
        ->assertMissing('[data-history-page]');
});

test('mark failed asks first, and cancelling sends nothing', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.library.activity.queue', ['history_service' => 'sonarr'], absolute: false))
        ->assertNoSmoke()
        ->click('[data-history-row="sonarr-55"] [data-history-mark-failed]')
        ->assertSeeIn('[data-confirm-dialog]', 'as failed?')
        ->assertSeeIn('[data-confirm-dialog]', 'The release is blocklisted, and a new search runs if "Redownload failed" is on.')
        ->assertScript("document.querySelector('[data-confirm-accept]').classList.contains('bg-destructive') === true")
        ->click('[data-confirm-cancel]');

    $webpage->script(confirmDialogGoneScript());
    $webpage->assertCount('[data-confirm-dialog]', 0)
        ->assertEnabled('[data-history-row="sonarr-55"] [data-history-mark-failed]')
        ->assertNoSmoke();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/history/failed/'));
});
