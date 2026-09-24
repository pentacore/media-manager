<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionTargets;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function targetsSonarr(): ServiceConnection
{
    return ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'name' => 'Sonarr 4K']);
}

test('a sonarr series resolves from the local index without calling sonarr', function (): void {
    $sonarr = targetsSonarr();
    IndexedSeries::factory()->for($sonarr, 'serviceConnection')->create(['sonarr_id' => 142, 'title' => 'Severance', 'year' => 2022]);

    $target = resolve(ActionTargets::class)->sonarrSeries(142);

    expect($target->verified)->toBeTrue()
        ->and($target->label())->toBe('series "Severance (2022)"')
        ->and($target->details)->toBe([
            ['label' => 'Series', 'value' => 'Severance (2022)'],
            ['label' => 'Sonarr ID', 'value' => '142'],
            ['label' => 'Connection', 'value' => 'Sonarr 4K'],
        ]);

    Http::assertNothingSent();
});

test('a sonarr series missing from the index resolves through the live client', function (): void {
    targetsSonarr();
    Http::fake(['sonarr.local:8989/api/v3/series/7' => Http::response(['id' => 7, 'title' => 'Andor', 'year' => 2022])]);

    $target = resolve(ActionTargets::class)->sonarrSeries(7);

    expect($target->verified)->toBeTrue()->and($target->name)->toBe('Andor (2022)');
});

test('an unresolvable series uses the fallback name and is unverified', function (): void {
    targetsSonarr();
    Http::fake(['sonarr.local:8989/api/v3/series/9' => Http::response([], 404)]);

    $target = resolve(ActionTargets::class)->sonarrSeries(9, fallbackName: 'Old Show');

    expect($target->verified)->toBeFalse()
        ->and($target->name)->toBe('Old Show')
        ->and($target->details)->toBe([['label' => 'ID', 'value' => '9']]);
});

test('a trusted fallback name stays verified', function (): void {
    targetsSonarr();
    Http::fake(['sonarr.local:8989/api/v3/series/9' => Http::response([], 404)]);

    $target = resolve(ActionTargets::class)->sonarrSeries(9, fallbackName: 'Severance', fallbackVerified: true);

    expect($target->verified)->toBeTrue()->and($target->name)->toBe('Severance');
});

test('an unresolvable series without a fallback is marked not found', function (): void {
    $target = resolve(ActionTargets::class)->sonarrSeries(9);

    expect($target->verified)->toBeFalse()->and($target->name)->toBe('#9 (not found)');
});

test('a radarr movie resolves from the index of the pinned connection', function (): void {
    $pinned = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'name' => 'Radarr UHD']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr2.local:7878']);
    IndexedMovie::factory()->for($pinned, 'serviceConnection')->create(['radarr_id' => 55, 'title' => 'Dune', 'year' => 2021]);

    $target = resolve(ActionTargets::class)->radarrMovie(55, ['service_connection_id' => $pinned->id]);

    expect($target->verified)->toBeTrue()
        ->and($target->name)->toBe('Dune (2021)')
        ->and($target->details[2])->toBe(['label' => 'Connection', 'value' => 'Radarr UHD']);
});

test('a series to add resolves through the sonarr lookup', function (): void {
    targetsSonarr();
    Http::fake(['sonarr.local:8989/api/v3/series/lookup*' => Http::response([['title' => 'Shogun', 'year' => 2024, 'tvdbId' => 999]])]);

    $target = resolve(ActionTargets::class)->sonarrLookup(999);

    expect($target->name)->toBe('Shogun (2024)')
        ->and($target->details[1])->toBe(['label' => 'TVDB ID', 'value' => '999']);
    Http::assertSent(fn ($request): bool => str_contains(urldecode($request->url()), 'term=tvdb:999'));
});

test('a seerr request resolves its media title and requester', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055']);
    Http::fake([
        'seerr.local:5055/api/v1/request/12' => Http::response([
            'id' => 12,
            'type' => 'movie',
            'media' => ['tmdbId' => 438631, 'mediaType' => 'movie'],
            'requestedBy' => ['displayName' => 'Alex'],
        ]),
        'seerr.local:5055/api/v1/movie/438631' => Http::response(['title' => 'Dune', 'releaseDate' => '2021-10-22']),
    ]);

    $target = resolve(ActionTargets::class)->seerrRequest(12);

    expect($target->verified)->toBeTrue()
        ->and($target->name)->toBe('Dune (2021)')
        ->and($target->details)->toBe([
            ['label' => 'Title', 'value' => 'Dune (2021)'],
            ['label' => 'Media type', 'value' => 'Movie'],
            ['label' => 'Requested by', 'value' => 'Alex'],
            ['label' => 'Request ID', 'value' => '12'],
        ]);
});

test('a download resolves its release from the arr queue', function (): void {
    targetsSonarr();
    Http::fake(['sonarr.local:8989/api/v3/queue*' => Http::response(['records' => [
        ['id' => 1, 'downloadId' => 'OTHER', 'title' => 'Wrong.Release'],
        ['id' => 2, 'downloadId' => 'ABC123', 'title' => 'Severance.S02E01.1080p', 'series' => ['title' => 'Severance'], 'downloadClient' => 'SABnzbd'],
    ]])]);

    $target = resolve(ActionTargets::class)->download(ServiceType::Sonarr, 'ABC123');

    expect($target->verified)->toBeTrue()
        ->and($target->label())->toBe('download "Severance.S02E01.1080p"')
        ->and($target->details)->toBe([
            ['label' => 'Release', 'value' => 'Severance.S02E01.1080p'],
            ['label' => 'Media', 'value' => 'Severance'],
            ['label' => 'Download client', 'value' => 'SABnzbd'],
            ['label' => 'Download ID', 'value' => 'ABC123'],
        ]);
});

test('the emby library target names the active emby server and never needs a lookup', function (): void {
    ServiceConnection::factory()->emby()->create(['name' => 'Living Room']);

    expect(resolve(ActionTargets::class)->embyLibrary())
        ->name->toBe('Living Room')
        ->verified->toBeTrue();
});

test('the emby library target stays verified without an emby connection', function (): void {
    expect(resolve(ActionTargets::class)->embyLibrary())
        ->name->toBe('Emby')
        ->verified->toBeTrue();
});

test('a quality profile id resolves to its name', function (): void {
    targetsSonarr();
    Http::fake(['sonarr.local:8989/api/v3/qualityprofile*' => Http::response([['id' => 4, 'name' => 'HD-1080p']])]);

    expect(resolve(ActionTargets::class)->qualityProfileName(ServiceType::Sonarr, 4))->toBe('HD-1080p')
        ->and(resolve(ActionTargets::class)->qualityProfileName(ServiceType::Sonarr, 99))->toBeNull();
});
