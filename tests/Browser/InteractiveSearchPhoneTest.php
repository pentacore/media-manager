<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function interactiveSearchPhoneFakeSonarr(): void
{
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake([
        'sonarr.local:8989/api/v3/series/7*' => Http::response([
            'id' => 7, 'title' => 'Severance', 'titleSlug' => 'severance', 'year' => 2022, 'status' => 'continuing', 'monitored' => true, 'qualityProfileId' => 1, 'images' => [],
            'seasons' => [['seasonNumber' => 1, 'monitored' => true, 'statistics' => ['episodeCount' => 1, 'episodeFileCount' => 0, 'sizeOnDisk' => 0]]],
            'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 1, 'episodeFileCount' => 0],
        ]),
        'sonarr.local:8989/api/v3/episode*' => Http::response([
            ['id' => 70, 'seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Good News About Hell', 'airDate' => '2022-02-18', 'hasFile' => false, 'monitored' => true],
        ]),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
        'sonarr.local:8989/api/v3/release*' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response([], 200)
            : Http::response([
                ['guid' => 'guid-1', 'indexerId' => 3, 'title' => 'Severance.S01.2160p.WEB-DL.DDP5.1.Atmos.DV.HDR.H.265-EXAMPLEGROUP', 'indexer' => 'NZBgeek', 'protocol' => 'usenet', 'size' => 9_000_000_000, 'ageHours' => 30, 'rejected' => false, 'rejections' => [], 'quality' => ['quality' => ['name' => 'WEBDL-2160p']]],
                ['guid' => 'guid-2', 'indexerId' => 4, 'title' => 'Severance.S01.720p.HDTV', 'indexer' => 'Tracker', 'protocol' => 'torrent', 'seeders' => 5, 'size' => 3_000_000_000, 'ageHours' => 400, 'rejected' => true, 'rejections' => ['Not an upgrade'], 'quality' => ['quality' => ['name' => 'HDTV-720p']]],
            ]),
        'sonarr.local:8989/*' => Http::response([]),
    ]);
}

beforeEach(function (): void {
    $this->seed(ActionTypeConfigSeeder::class);
    interactiveSearchPhoneFakeSonarr();
});

function interactiveSearchPhoneOpenScript(): string
{
    return confirmDialogWaitUntilScript('document.querySelectorAll("[data-interactive-search-dialog] [data-release-card], [data-interactive-search-dialog] [data-release-row]").length >= 2 && document.querySelector("[data-interactive-search-dialog]").getAnimations().length === 0');
}

test('release cards fit a phone and Grab sends the release', function (): void {
    $this->actingAs(User::factory()->member()->create());
    $first = hash('sha256', 'guid-1');
    $second = hash('sha256', 'guid-2');

    $webpage = visit(route('media.series.show', ['id' => 7], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('Season 1')
        ->click('[data-season-actions="1"] [data-interactive-search]');
    $webpage->script(interactiveSearchPhoneOpenScript());

    $webpage->assertMissing('[data-interactive-search-scroll]')
        ->assertSeeIn("[data-release-card=\"{$first}\"]", 'EXAMPLEGROUP')
        ->assertSeeIn("[data-release-card=\"{$first}\"]", '8.4 GB')
        ->assertSeeIn("[data-release-card=\"{$second}\"]", 'Not an upgrade')
        ->assertSeeIn("[data-release-card=\"{$second}\"] [data-release-card-grab]", 'Grab anyway')
        ->assertScript(phoneOverflowScript('[role="dialog"]'), '')
        ->click("[data-release-card=\"{$first}\"] [data-release-card-grab]")
        ->assertSee('Release sent to the download client.')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/api/v3/release') && $request['guid'] === 'guid-1');
});

test('the phone sort strip reorders the release cards', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.show', ['id' => 7], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('Season 1')
        ->click('[data-season-actions="1"] [data-interactive-search]');
    $webpage->script(interactiveSearchPhoneOpenScript());

    // Default: size, largest first.
    $webpage->assertAttribute('[data-release-card-sort="size"]', 'aria-pressed', 'true')
        ->assertScript('document.querySelector("[data-release-card]").dataset.releaseCard', hash('sha256', 'guid-1'))
        ->click('[data-release-card-sort="age_hours"]')
        ->assertAttribute('[data-release-card-sort="age_hours"]', 'aria-pressed', 'true')
        ->assertScript('document.querySelector("[data-release-card]").dataset.releaseCard', hash('sha256', 'guid-2'));
});

test('at desktop width the interactive search keeps its release table', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.show', ['id' => 7], absolute: false))->assertNoSmoke();
    $webpage->click('Season 1')
        ->click('[data-season-actions="1"] [data-interactive-search]');
    $webpage->script(interactiveSearchPhoneOpenScript());

    $webpage->assertVisible(sprintf('[data-release-row="%s"] [data-release-grab]', hash('sha256', 'guid-1')))
        ->assertMissing('[data-release-cards]');
});
