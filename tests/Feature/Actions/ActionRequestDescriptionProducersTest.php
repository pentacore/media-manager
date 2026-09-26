<?php

declare(strict_types=1);

use App\Enums\SubtitleCaseStatus;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\WebhookEvent;
use App\Services\Bazarr\BazarrDownloadRequestCreator;
use App\Services\Emby\EmbyWebhookHandler;
use App\Services\Radarr\RadarrWebhookHandler;
use App\Services\Seerr\SeerrWebhookHandler;
use App\Services\Sonarr\SonarrWebhookHandler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
});

function producersLibraryScanRequest(): ActionRequest
{
    return ActionRequest::query()->where('type', 'emby_library_scan')->sole();
}

test('an emby library delete queues a described series delete', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'delete_series', 'requires_approval' => true, 'is_enabled' => true]);
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    IndexedSeries::factory()->for($sonarr, 'serviceConnection')->create(['sonarr_id' => 142, 'title' => 'Severance', 'year' => 2022]);
    $emby = ServiceConnection::factory()->emby()->create(['name' => 'Living Room']);
    $webhookEvent = WebhookEvent::factory()->for($emby, 'serviceConnection')->create([
        'event_type' => 'library.deleted',
        'payload' => ['Event' => 'library.deleted', 'Item' => ['Type' => 'Series', 'Name' => 'Severance', 'ProviderIds' => ['SonarrSeriesId' => '142']]],
    ]);

    resolve(EmbyWebhookHandler::class)->handle($webhookEvent);

    $actionRequest = ActionRequest::sole();
    expect($actionRequest->title)->toBe('Delete series "Severance (2022)"')
        ->and($actionRequest->description)->toBe('Emby reported "Severance" was removed from the library. Sonarr will delete the series and its files from disk.')
        ->and($actionRequest->details)->toContain(['label' => 'Triggered by', 'value' => 'Emby › Living Room'])
        ->and($actionRequest->description_verified)->toBeTrue();
});

test('an emby library delete without an item name uses an unquoted fallback noun', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'delete_series', 'requires_approval' => true, 'is_enabled' => true]);
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    IndexedSeries::factory()->for($sonarr, 'serviceConnection')->create(['sonarr_id' => 142, 'title' => 'Severance', 'year' => 2022]);
    $emby = ServiceConnection::factory()->emby()->create();
    $webhookEvent = WebhookEvent::factory()->for($emby, 'serviceConnection')->create([
        'event_type' => 'library.deleted',
        'payload' => ['Event' => 'library.deleted', 'Item' => ['Type' => 'Series', 'ProviderIds' => ['SonarrSeriesId' => '142']]],
    ]);

    resolve(EmbyWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::sole()->description)->toBe('Emby reported a series was removed from the library. Sonarr will delete the series and its files from disk.');
});

test('an emby delete for a series missing everywhere still names it from emby and stays verified', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'delete_movie', 'requires_approval' => true, 'is_enabled' => true]);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);
    Http::fake(['radarr.local:7878/api/v3/movie/77' => Http::response([], 404)]);
    $emby = ServiceConnection::factory()->emby()->create();
    $webhookEvent = WebhookEvent::factory()->for($emby, 'serviceConnection')->create([
        'event_type' => 'library.deleted',
        'payload' => ['Event' => 'library.deleted', 'Item' => ['Type' => 'Movie', 'Name' => 'Tenet', 'ProviderIds' => ['RadarrMovieId' => '77']]],
    ]);

    resolve(EmbyWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::sole())
        ->title->toBe('Delete movie "Tenet"')
        ->description->toBe('Emby reported "Tenet" was removed from the library. Radarr will delete the movie and its files from disk.')
        ->description_verified->toBeTrue();
});

test('a radarr import queues a described emby library scan', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'emby_library_scan', 'requires_approval' => true, 'is_enabled' => true]);
    ServiceConnection::factory()->emby()->create(['name' => 'Living Room']);
    $radarr = ServiceConnection::factory()->radarr()->create(['name' => 'Radarr']);
    $webhookEvent = WebhookEvent::factory()->for($radarr, 'serviceConnection')->create([
        'event_type' => 'Download',
        'payload' => ['eventType' => 'Download', 'movie' => ['id' => 5, 'title' => 'Dune']],
    ]);

    resolve(RadarrWebhookHandler::class)->handle($webhookEvent);

    $actionRequest = producersLibraryScanRequest();
    expect($actionRequest->title)->toBe('Scan the Emby library')
        ->and($actionRequest->description)->toBe('Radarr imported "Dune". Emby server "Living Room" will rescan its libraries to pick up changes.')
        ->and($actionRequest->details)->toContain(['label' => 'Triggered by', 'value' => 'Radarr › Radarr'])
        ->and($actionRequest->description_verified)->toBeTrue();
});

test('a radarr movie delete queues a described emby library scan', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'emby_library_scan', 'requires_approval' => true, 'is_enabled' => true]);
    ServiceConnection::factory()->emby()->create(['name' => 'Living Room']);
    $radarr = ServiceConnection::factory()->radarr()->create(['name' => 'Radarr 4K']);
    $webhookEvent = WebhookEvent::factory()->for($radarr, 'serviceConnection')->create([
        'event_type' => 'MovieDelete',
        'payload' => ['eventType' => 'MovieDelete', 'movie' => ['id' => 5, 'title' => 'Dune']],
    ]);

    resolve(RadarrWebhookHandler::class)->handle($webhookEvent);

    $actionRequest = producersLibraryScanRequest();
    expect($actionRequest->title)->toBe('Scan the Emby library')
        ->and($actionRequest->description)->toBe('Radarr deleted "Dune". Emby server "Living Room" will rescan its libraries to pick up changes.')
        ->and($actionRequest->details)->toContain(['label' => 'Triggered by', 'value' => 'Radarr › Radarr 4K']);
});

test('a sonarr import queues a described emby library scan', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'emby_library_scan', 'requires_approval' => true, 'is_enabled' => true]);
    ServiceConnection::factory()->emby()->create(['name' => 'Living Room']);
    $sonarr = ServiceConnection::factory()->sonarr()->create(['name' => 'Sonarr']);
    $webhookEvent = WebhookEvent::factory()->for($sonarr, 'serviceConnection')->create([
        'event_type' => 'Download',
        'payload' => ['eventType' => 'Download', 'series' => ['id' => 9, 'title' => 'Severance'], 'episodes' => [['id' => 1]]],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    $actionRequest = producersLibraryScanRequest();
    expect($actionRequest->title)->toBe('Scan the Emby library')
        ->and($actionRequest->description)->toBe('Sonarr imported "Severance". Emby server "Living Room" will rescan its libraries to pick up changes.')
        ->and($actionRequest->details)->toContain(['label' => 'Triggered by', 'value' => 'Sonarr › Sonarr'])
        ->and($actionRequest->description_verified)->toBeTrue();
});

test('a sonarr series delete queues a described emby library scan', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'emby_library_scan', 'requires_approval' => true, 'is_enabled' => true]);
    ServiceConnection::factory()->emby()->create(['name' => 'Living Room']);
    $sonarr = ServiceConnection::factory()->sonarr()->create(['name' => 'Sonarr Anime']);
    $webhookEvent = WebhookEvent::factory()->for($sonarr, 'serviceConnection')->create([
        'event_type' => 'SeriesDelete',
        'payload' => ['eventType' => 'SeriesDelete', 'series' => ['id' => 9, 'title' => 'Severance']],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    $actionRequest = producersLibraryScanRequest();
    expect($actionRequest->title)->toBe('Scan the Emby library')
        ->and($actionRequest->description)->toBe('Sonarr deleted "Severance". Emby server "Living Room" will rescan its libraries to pick up changes.')
        ->and($actionRequest->details)->toContain(['label' => 'Triggered by', 'value' => 'Sonarr › Sonarr Anime']);
});

test('a seerr media available notification queues a described emby library scan', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'emby_library_scan', 'requires_approval' => true, 'is_enabled' => true]);
    ServiceConnection::factory()->emby()->create(['name' => 'Living Room']);
    $seerr = ServiceConnection::factory()->seerr()->create(['name' => 'Seerr']);
    $webhookEvent = WebhookEvent::factory()->for($seerr, 'serviceConnection')->create([
        'event_type' => 'MEDIA_AVAILABLE',
        'payload' => ['notification_type' => 'MEDIA_AVAILABLE', 'subject' => 'Dune: Part Two (2024)'],
    ]);

    resolve(SeerrWebhookHandler::class)->handle($webhookEvent);

    $actionRequest = producersLibraryScanRequest();
    expect($actionRequest->title)->toBe('Scan the Emby library')
        ->and($actionRequest->description)->toBe('Seerr reported "Dune: Part Two (2024)" is now available. Emby server "Living Room" will rescan its libraries to pick up changes.')
        ->and($actionRequest->details)->toContain(['label' => 'Triggered by', 'value' => 'Seerr › Seerr'])
        ->and($actionRequest->description_verified)->toBeTrue();
});

test('a seerr media available notification without a subject uses an unquoted fallback noun', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'emby_library_scan', 'requires_approval' => true, 'is_enabled' => true]);
    ServiceConnection::factory()->emby()->create(['name' => 'Living Room']);
    $seerr = ServiceConnection::factory()->seerr()->create(['name' => 'Seerr']);
    $webhookEvent = WebhookEvent::factory()->for($seerr, 'serviceConnection')->create([
        'event_type' => 'MEDIA_AVAILABLE',
        'payload' => ['notification_type' => 'MEDIA_AVAILABLE'],
    ]);

    resolve(SeerrWebhookHandler::class)->handle($webhookEvent);

    expect(producersLibraryScanRequest()->description)->toBe('Seerr reported a title is now available. Emby server "Living Room" will rescan its libraries to pick up changes.');
});

test('an automatic subtitle download is described with the case that queued it', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'bazarr_download_best', 'requires_approval' => true, 'is_enabled' => true]);
    $subtitleCase = SubtitleCase::factory()->create([
        'status' => SubtitleCaseStatus::BazarrSearching,
        'evidence' => ['display_name' => 'Frieren — Part One', 'missing_languages' => ['eng']],
    ]);

    resolve(BazarrDownloadRequestCreator::class)->create($subtitleCase, ['code' => 'eng', 'forced' => false, 'hearing_impaired' => true]);

    $actionRequest = ActionRequest::sole();
    expect($actionRequest->title)->toBe('Download the best subtitle for Frieren — Part One')
        ->and($actionRequest->description)->toBe(sprintf('Queued automatically for subtitle case #%d. Bazarr will search its providers and download the best-scoring subtitle.', $subtitleCase->id))
        ->and($actionRequest->details)->toBe([
            ['label' => 'Media', 'value' => 'Frieren — Part One'],
            ['label' => 'Language', 'value' => 'eng'],
            ['label' => 'Forced', 'value' => 'No'],
            ['label' => 'Hearing impaired', 'value' => 'Yes'],
        ])
        ->and($actionRequest->description_verified)->toBeTrue();
});
