<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Support\Facades\Http;

/**
 * A release with a long scene name, so the table is far wider than a phone.
 *
 * @return array<string, mixed>
 */
function mobileContainmentRelease(int $index): array
{
    return [
        'guid' => 'guid-'.$index, 'indexerId' => 3, 'indexer' => 'NZBgeek', 'protocol' => 'usenet',
        'title' => sprintf('Severance.S01E0%d.2160p.WEB-DL.DDP5.1.Atmos.DV.HDR.H.265-EXAMPLEGROUP', $index),
        'size' => 4_500_000_000, 'ageHours' => 30, 'rejected' => false, 'rejections' => [],
        'quality' => ['quality' => ['name' => 'WEBDL-2160p']],
    ];
}

function mobileContainmentFakeSonarr(): void
{
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake([
        'sonarr.local:8989/api/v3/series/7*' => Http::response([
            'id' => 7, 'title' => 'Severance', 'titleSlug' => 'severance', 'year' => 2022, 'status' => 'continuing',
            'monitored' => true, 'qualityProfileId' => 1, 'images' => [],
            'seasons' => [['seasonNumber' => 1, 'monitored' => true, 'statistics' => ['episodeCount' => 1, 'episodeFileCount' => 0, 'sizeOnDisk' => 0]]],
            'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 1, 'episodeFileCount' => 0],
        ]),
        'sonarr.local:8989/api/v3/episode*' => Http::response([
            ['id' => 70, 'seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Good News About Hell', 'airDate' => '2022-02-18', 'hasFile' => false, 'monitored' => true],
        ]),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
        'sonarr.local:8989/api/v3/release*' => Http::response([mobileContainmentRelease(1), mobileContainmentRelease(2), mobileContainmentRelease(3)]),
        'sonarr.local:8989/*' => Http::response([]),
    ]);
}

test('the settings column fits a phone and the notification matrices scroll inside their own frame', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('settings.notifications.edit', absolute: false))->on()->mobile()->assertNoSmoke();

    $webpage->assertScript('document.querySelector("main").scrollWidth - document.querySelector("main").clientWidth', 0)
        ->assertScript('(() => { const frame = document.querySelector("[data-notification-matrix]"); return frame.scrollWidth > frame.clientWidth && frame.getBoundingClientRect().right <= window.innerWidth; })()', true)
        ->assertScript('(() => { const save = document.querySelector("[data-notification-save]"); save.scrollIntoView({ block: "center" }); const rect = save.getBoundingClientRect(); return rect.left >= 0 && rect.right <= window.innerWidth; })()', true)
        ->assertScript(phoneOverflowScript('main'), '');
});

test('the interactive search releases scroll inside the dialog and Grab can be reached', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);
    mobileContainmentFakeSonarr();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.show', ['id' => 7], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('Season 1');
    // The season actions sit in a 3h-2 toolbar row that may still be clipped; the dialog is what this test measures.
    $webpage->script('document.querySelector("[data-season-actions=\"1\"] [data-interactive-search]").click()');
    $webpage->script(confirmDialogWaitUntilScript('document.querySelectorAll("[data-interactive-search-dialog] [data-release-grab]").length === 3 && document.querySelector("[data-interactive-search-dialog]").getAnimations().length === 0'));

    $webpage->assertScript('(() => { const rect = document.querySelector("[data-interactive-search-dialog]").getBoundingClientRect(); return rect.left >= 0 && rect.right <= window.innerWidth && rect.top >= 0 && rect.bottom <= window.innerHeight; })()', true)
        ->assertScript('(() => { const frame = document.querySelector("[data-interactive-search-scroll]"); return frame.scrollWidth > frame.clientWidth; })()', true)
        ->assertScript('(() => { const grab = document.querySelector("[data-release-grab]"); grab.scrollIntoView({ inline: "end", block: "nearest" }); const rect = grab.getBoundingClientRect(); const dialog = document.querySelector("[data-interactive-search-dialog]").getBoundingClientRect(); return rect.right <= dialog.right + 1 && rect.left >= dialog.left - 1; })()', true)
        ->assertScript(phoneOverflowScript('[role="dialog"]'), '');
});

test('a dialog taller than the phone viewport scrolls inside itself', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.users.index', absolute: false))->on()->mobile()->assertNoSmoke();
    // 360 px stands in for a phone with the on-screen keyboard up.
    $webpage->resize(375, 360);
    $webpage->script(appHydratedScript());
    $webpage->script('[...document.querySelectorAll("main button")].find((button) => button.innerText.includes("Invite user")).click()');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[role=\"dialog\"]") && document.querySelector("[role=\"dialog\"]").getAnimations().length === 0'));

    $webpage->assertScript('(() => { const rect = document.querySelector("[role=\"dialog\"]").getBoundingClientRect(); return rect.top >= 0 && rect.bottom <= window.innerHeight && rect.left >= 0 && rect.right <= window.innerWidth; })()', true)
        ->assertScript('(() => { const dialog = document.querySelector("[role=\"dialog\"]"); return dialog.scrollHeight > dialog.clientHeight; })()', true)
        ->assertScript('(() => { const dialog = document.querySelector("[role=\"dialog\"]"); const submit = dialog.querySelector("button[type=\"submit\"]"); submit.scrollIntoView({ block: "nearest" }); const rect = submit.getBoundingClientRect(); const frame = dialog.getBoundingClientRect(); return rect.top >= frame.top - 1 && rect.bottom <= frame.bottom + 1; })()', true);
});

test('now playing session cards fit a phone screen', function (): void {
    ServiceConnection::factory()->emby()->create(['url' => 'http://emby.local:8096', 'api_key' => 'k']);
    Http::fake([
        'emby.local:8096/Sessions*' => Http::response([
            ['Id' => 's1', 'UserName' => 'Alice', 'UserId' => 'emby-1', 'Client' => 'Emby Web', 'DeviceName' => 'Living Room TV', 'NowPlayingItem' => ['Id' => 'i1', 'Name' => 'Half Loop', 'SeriesName' => 'Severance', 'Type' => 'Episode', 'RunTimeTicks' => 33_000_000_000], 'PlayState' => ['PositionTicks' => 12_000_000_000, 'IsPaused' => false]],
            ['Id' => 's2', 'UserName' => 'Bob', 'UserId' => 'emby-2', 'Client' => 'Emby for iOS', 'DeviceName' => 'iPhone', 'NowPlayingItem' => ['Id' => 'i2', 'Name' => 'An Extremely Long Example Movie Title That Wraps Badly', 'Type' => 'Movie', 'RunTimeTicks' => 66_000_000_000], 'PlayState' => ['PositionTicks' => 6_000_000_000, 'IsPaused' => true]],
        ]),
        'emby.local:8096/*' => Http::response([]),
    ]);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('monitoring.now-playing', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(confirmDialogWaitUntilScript('document.querySelectorAll("[data-session-card]").length === 2'));

    $webpage->assertScript('document.querySelectorAll("[data-session-card]").length', 2)
        ->assertScript('[...document.querySelectorAll("[data-session-card]")].every((card) => card.getBoundingClientRect().right <= window.innerWidth)', true)
        ->assertScript(phoneOverflowScript('[data-session-card]'), '');
});

test('service health rows and classification gate cards fit a phone screen', function (): void {
    ServiceConnection::factory()->sonarr()->create(['name' => 'Example Sonarr', 'url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake(['sonarr.local:8989/*' => Http::response([])]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('monitoring.service-health', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->assertVisible('[data-health-row]')
        ->assertScript('[...document.querySelectorAll("[data-health-row]")].every((row) => row.getBoundingClientRect().right <= window.innerWidth)', true)
        ->assertScript(phoneOverflowScript('[data-health-row]'), '');

    $webpage->navigate(route('admin.ai-usage.index', absolute: false));
    $webpage->assertVisible('[data-classification-gates]')
        ->assertScript('[...document.querySelectorAll("[data-gate]")].every((gate) => gate.getBoundingClientRect().right <= window.innerWidth)', true)
        ->assertScript(phoneOverflowScript('[data-classification-gates]'), '');
});

test('Seerr request cards keep to the screen width', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    Http::fake([
        'seerr.local:5055/api/v1/request/count' => Http::response(['total' => 1, 'pending' => 1, 'approved' => 0, 'declined' => 0, 'movie' => 1, 'tv' => 0]),
        'seerr.local:5055/api/v1/request*' => Http::response(['pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 50, 'results' => 1], 'results' => [
            ['id' => 1, 'status' => 1, 'type' => 'movie', 'media' => ['mediaType' => 'movie', 'tmdbId' => 9001, 'status' => 3], 'requestedBy' => ['displayName' => 'Example User', 'email' => 'user@example.com'], 'createdAt' => '2026-10-01T00:00:00Z', 'seasons' => []],
        ]]),
        'seerr.local:5055/api/v1/movie/*' => Http::response(['id' => 9001, 'title' => 'Example Requested Movie With Long Title', 'posterPath' => '/example.jpg', 'releaseDate' => '2025-05-01']),
        'seerr.local:5055/*' => Http::response([]),
    ]);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->assertVisible('[data-request-card="1"]')
        ->assertScript('document.querySelector("[data-request-card=\"1\"]").getBoundingClientRect().right <= window.innerWidth', true);
});
