<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, string>
 */
function downloadsPhoneQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

function downloadsPhoneFakeSabnzbd(): void
{
    ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sab.local:8080', 'api_key' => 'k']);
    Http::fake([
        'sab.local:8080/api*' => function (Request $request) {
            $query = downloadsPhoneQuery($request);

            return match (true) {
                ($query['mode'] ?? null) === 'queue' && ! isset($query['name']) => Http::response(['queue' => [
                    'paused' => false, 'speed' => '10 M', 'sizeleft' => '1.5 GB', 'timeleft' => '0:15:00', 'speedlimit' => '', 'speedlimit_abs' => '', 'noofslots' => 2,
                    'slots' => [
                        ['nzo_id' => 'SABnzbd_nzo_1', 'filename' => 'Severance.S01E01.2160p.WEB-DL.DDP5.1.Atmos.DV.HDR.H.265-EXAMPLEGROUP', 'cat' => 'tv', 'size' => '4.5 GB', 'sizeleft' => '2.0 GB', 'percentage' => '55', 'timeleft' => '0:05:00', 'status' => 'Downloading', 'priority' => '0'],
                        ['nzo_id' => 'SABnzbd_nzo_2', 'filename' => 'Andor.S01E02.1080p', 'cat' => 'tv', 'size' => '1.0 GB', 'sizeleft' => '1.0 GB', 'percentage' => '0', 'timeleft' => '0:10:00', 'status' => 'Paused', 'priority' => '0'],
                    ],
                ]]),
                ($query['mode'] ?? null) === 'history' && ! isset($query['name']) => Http::response(['history' => ['noofslots' => 1, 'slots' => [
                    ['nzo_id' => 'SABnzbd_nzo_failed1', 'name' => 'Dune.Part.Two.2024.2160p.UHD.BluRay.REMUX.HDR.HEVC.Atmos-EXAMPLEGROUP', 'category' => 'movies', 'status' => 'Failed', 'fail_message' => 'Unpacking failed, archive requires a password'],
                ]]]),
                default => Http::response(['status' => true]),
            };
        },
    ]);
}

function downloadsPhoneFakeSonarrQueue(): void
{
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => fn (Request $request) => $request->method() === 'DELETE'
            ? Http::response('', 200)
            : Http::response(['records' => [[
                'id' => 41, 'title' => 'Severance.S01E01.2160p.WEB-DL.DDP5.1.Atmos.DV.HDR.H.265-EXAMPLEGROUP', 'series' => ['title' => 'Severance'],
                'episode' => ['seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Good News About Hell'],
                'status' => 'downloading', 'trackedDownloadStatus' => 'warning', 'trackedDownloadState' => 'downloading',
                'protocol' => 'usenet', 'downloadClient' => 'SABnzbd', 'size' => 1_000_000_000, 'sizeleft' => 500_000_000,
                'timeleft' => '00:05:00', 'statusMessages' => [['title' => 'Example warning', 'messages' => ['No files found are eligible for import']]],
                'added' => '2026-09-29T10:00:00Z', 'quality' => ['quality' => ['name' => 'WEBDL-1080p']], 'downloadId' => 'SABnzbd_nzo_41',
            ]]]),
        'sonarr.local:8989/*' => Http::response([]),
    ]);
}

test('SABnzbd queue and history cards fit a phone and keep pause, resume, delete and retry reachable', function (): void {
    downloadsPhoneFakeSabnzbd();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('sabnzbd.queue.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertVisible('[data-sab-card="SABnzbd_nzo_1"]')
        ->assertSeeIn('[data-sab-card="SABnzbd_nzo_1"]', 'EXAMPLEGROUP')
        ->assertSeeIn('[data-sab-card="SABnzbd_nzo_1"]', '55%')
        ->assertMissing('[data-sab-slot="SABnzbd_nzo_1"]')
        ->assertVisible('[data-sab-history-card="SABnzbd_nzo_failed1"]')
        ->assertSeeIn('[data-sab-history-card="SABnzbd_nzo_failed1"]', 'Unpacking failed')
        ->assertMissing('[data-history-row="SABnzbd_nzo_failed1"]')
        ->assertScript(phoneOverflowScript('main'), '')
        ->click('[data-sab-card-toggle="SABnzbd_nzo_1"]')
        ->assertSee('Job paused.')
        ->click('[data-sab-card-toggle="SABnzbd_nzo_2"]')
        ->assertSee('Job resumed.')
        ->click('[data-sab-card-delete="SABnzbd_nzo_2"]')
        ->click('[data-confirm-accept]')
        ->assertSee('Job deleted.')
        ->click('[data-sab-history-card-retry="SABnzbd_nzo_failed1"]')
        ->assertSee('Retry queued.')
        ->click('[data-sab-history-card-delete="SABnzbd_nzo_failed1"]')
        ->assertVisible('[data-history-delete-dialog]')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => (downloadsPhoneQuery($request)['mode'] ?? null) === 'retry' && (downloadsPhoneQuery($request)['value'] ?? null) === 'SABnzbd_nzo_failed1');
});

test('a member sees SABnzbd cards without the admin-only controls', function (): void {
    downloadsPhoneFakeSabnzbd();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('sabnzbd.queue.index', absolute: false))->on()->mobile()->assertNoSmoke();

    $webpage->assertVisible('[data-sab-card="SABnzbd_nzo_1"]')
        ->assertCount('[data-sab-card-select]', 0)
        ->assertCount('[data-sab-history-card-retry]', 0)
        ->assertCount('[data-sab-history-card-delete]', 0);
});

test('grab queue cards fit a phone and keep Open and the admin row menu reachable', function (): void {
    downloadsPhoneFakeSonarrQueue();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.library.activity.queue', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertVisible('[data-queue-card="sonarr-41"]')
        ->assertSeeIn('[data-queue-card="sonarr-41"]', 'No files found are eligible for import')
        ->assertMissing('[data-queue-row="sonarr-41"]')
        ->assertAttribute('[data-queue-card="sonarr-41"] [data-queue-card-open]', 'href', 'http://sonarr.local:8989/activity/queue')
        ->assertScript(phoneOverflowScript('main'), '')
        ->click('[data-queue-card-menu="sonarr-41"]')
        ->click('[data-queue-remove="remove"]')
        ->click('[data-confirm-accept]')
        ->assertSee('Removed from queue.')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), '/api/v3/queue/41?'));
});

test('a picked service turns on the grab queue card checkboxes on a phone', function (): void {
    downloadsPhoneFakeSonarrQueue();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.library.activity.queue', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertCount('[data-queue-card-select]', 0)
        ->click('[data-service-filter="sonarr"]')
        ->click('[data-queue-card-select="41"]')
        ->assertSeeIn('[data-bulk-count]', '1 selected');
});

test('an empty SABnzbd queue and history say so once on a phone', function (): void {
    ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sab.local:8080', 'api_key' => 'k']);
    Http::fake(['sab.local:8080/api*' => Http::response([
        'queue' => ['paused' => false, 'speed' => '0', 'speedlimit' => '', 'speedlimit_abs' => '', 'noofslots' => 0, 'slots' => []],
        'history' => ['noofslots' => 0, 'slots' => []],
    ])]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('sabnzbd.queue.index', absolute: false))->on()->mobile()->assertNoSmoke();

    $webpage->assertSeeIn('[data-sabnzbd-history]', 'No recent downloads.')
        ->assertSee('Queue is empty.')
        ->assertCount('[data-sab-cards]', 0)
        ->assertCount('[data-sab-history-cards]', 0)
        ->assertScript(phoneOverflowScript('main'), '');
});

test('at desktop width the download pages keep their tables', function (): void {
    downloadsPhoneFakeSabnzbd();
    downloadsPhoneFakeSonarrQueue();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('sabnzbd.queue.index', absolute: false))->assertNoSmoke();
    $webpage->assertVisible('[data-sab-slot="SABnzbd_nzo_1"]')
        ->assertVisible('[data-history-row="SABnzbd_nzo_failed1"]')
        ->assertMissing('[data-sab-cards]')
        ->assertMissing('[data-sab-history-cards]');

    $webpage->navigate(route('media.library.activity.queue', absolute: false));
    $webpage->assertVisible('[data-queue-row="sonarr-41"] [data-queue-row-menu]')
        ->assertMissing('[data-queue-cards]');
});
