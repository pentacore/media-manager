<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\AuditImportedSubtitles;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\MediaReplacement\ImportedSubtitleAuditor;
use App\Settings\MediaReplacementSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Cache::flush();
    Notification::fake();

    User::factory()->create(['role' => UserRole::Admin]);

    ActionTypeConfig::factory()->create([
        'type' => 'replace_media_file',
        'requires_approval' => false,
        'is_enabled' => true,
    ]);
});

/**
 * A Sonarr connection whose series carries a configured subtitle-check tag,
 * with the replacement settings the audit needs to reach a dispatch. Mirrors
 * the fixture in tests/Feature/Services/MediaReplacement/ImportedSubtitleAuditorTest.php.
 */
function taggedSonarrConnection(): ServiceConnection
{
    $serviceConnection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
        'settings' => [
            'subtitle_check_tags' => ['sub-check'],
            'sonarr_root_folders' => [['root_folder_id' => 1, 'path' => '/tv', 'scope' => 'tv']],
        ],
    ]);

    resolve(MediaReplacementSettings::class)->setConfiguration([
        'automatic_selection_enabled' => true,
        'automatic_selection_threshold' => 90,
        'global_languages' => ['English'],
        'scoped_languages' => ['anime' => null, 'tv' => null, 'movie' => null],
        'season_pack_policy' => 'allowed',
        'subtitle_check' => ['enabled' => true, 'max_attempts_per_target' => 1, 'cooldown_hours' => 24],
        'guidance' => [
            'anime' => ['notes' => '', 'rules' => []],
            'tv' => [
                'notes' => '',
                'rules' => [
                    [
                        'name' => 'CR', 'enabled' => true, 'strength' => 'guarantee', 'languages' => ['English'],
                        'conditions' => [['field' => 'title', 'value' => 'CR']],
                    ],
                ],
            ],
            'movie' => ['notes' => '', 'rules' => []],
        ],
    ]);

    return $serviceConnection;
}

/**
 * Fakes the arr surface the audit touches, so the job's delegation can be
 * observed through the ActionRequest the audit dispatches. `subtitles` drives
 * the imported file's mediaInfo.
 *
 * @param  array{subtitles?: string}  $opts
 */
function fakeAuditArrForJob(array $opts = []): void
{
    Http::preventStrayRequests();

    Http::fake([
        'sonarr.local:8989/api/v3/tag' => Http::response([
            ['id' => 1, 'label' => 'sub-check'],
            ['id' => 2, 'label' => 'other'],
        ]),
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42, 'title' => 'Tagged Show', 'path' => '/tv/Tagged Show',
            'monitored' => true, 'tags' => [1], 'seriesType' => 'standard',
        ]),
        // episodefile must precede the episode wildcard: Http::fake matches in
        // declaration order and `episode*` also matches `episodefile/501`.
        'sonarr.local:8989/api/v3/episodefile/501' => Http::response([
            'id' => 501, 'seriesId' => 42, 'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
            'size' => 100, 'sceneName' => 'Tagged.Show.S01E01.OLD', 'releaseGroup' => 'OLD',
            'mediaInfo' => ['subtitles' => $opts['subtitles'] ?? 'Japanese'],
        ]),
        'sonarr.local:8989/api/v3/episode*' => Http::response([[
            'id' => 101, 'seriesId' => 42, 'seasonNumber' => 1, 'episodeNumber' => 1,
            'episodeFileId' => 501, 'monitored' => true, 'hasFile' => true, 'title' => 'Ep 1',
        ]]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => [[
            'id' => 9001, 'eventType' => 'downloadFolderImported', 'episodeId' => 101, 'episodeFileId' => 501,
        ]]]),
        'sonarr.local:8989/api/v3/release*' => Http::response([[
            'guid' => 'g1', 'indexerId' => 10, 'title' => 'Tagged.Show.S01E01.CR',
            'episodeIds' => [101], 'downloadAllowed' => true, 'rejections' => [], 'fullSeason' => false,
            'customFormatScore' => 0, 'qualityWeight' => 100, 'seeders' => 5, 'ageMinutes' => 60,
            'downloadUrl' => 'http://sonarr.local/download/g1',
        ]]),
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([]),
    ]);
}

test('it delegates a tagged import to the auditor', function (): void {
    // Delegation is observed through its effect: with the connection tagged and
    // the imported file missing a required language, the auditor dispatches a
    // replace_media_file ActionRequest. Asserting the effect rather than a mock
    // call is forced here — ImportedSubtitleAuditor is final readonly — and is
    // the stronger assertion anyway.
    $connection = taggedSonarrConnection();
    fakeAuditArrForJob(['subtitles' => 'Japanese']);

    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'event_type' => 'Download',
        'payload' => [
            'eventType' => 'Download',
            'series' => ['id' => 42, 'title' => 'Tagged Show'],
            'episodes' => [['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1]],
            'downloadId' => 'DL-ORGANIC',
        ],
    ]);

    new AuditImportedSubtitles($webhookEvent->id)->handle(resolve(ImportedSubtitleAuditor::class));

    $actionRequest = ActionRequest::query()->where('type', 'replace_media_file')->sole();

    // The event is asserted too: the job must pass it through so the request is
    // traceable back to the import that caused it.
    expect($actionRequest->webhook_event_id)->toBe($webhookEvent->id)
        ->and($actionRequest->payload['auto_check_key'])->toBe(sprintf('sonarr:%d:42-101', $connection->id));
});

test('it does nothing when the webhook event has been pruned', function (): void {
    // No auditor collaborator needed: the guard returns before it is used, and
    // preventStrayRequests would catch any arr call.
    Http::preventStrayRequests();

    new AuditImportedSubtitles(999_999)->handle(resolve(ImportedSubtitleAuditor::class));

    expect(ActionRequest::query()->where('type', 'replace_media_file')->count())->toBe(0);
    Http::assertNothingSent();
});

test('it does nothing when the connection was removed while the job waited', function (): void {
    // This is the real shape of the "no connection" case, and the reason the
    // delay needs a guard at all: webhook_events.service_connection_id is NOT
    // NULL and cascades on delete, so deleting the connection during the 30
    // second wait takes the event row with it rather than leaving a
    // connection-less event behind. The job's own ServiceConnection check is
    // therefore defensive; this exercises the pruned-event guard.
    $connection = taggedSonarrConnection();
    fakeAuditArrForJob(['subtitles' => 'Japanese']);

    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'event_type' => 'Download',
        'payload' => [
            'eventType' => 'Download',
            'series' => ['id' => 42, 'title' => 'Tagged Show'],
            'episodes' => [['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1]],
            'downloadId' => 'DL-ORGANIC',
        ],
    ]);

    $connection->delete();

    new AuditImportedSubtitles($webhookEvent->id)->handle(resolve(ImportedSubtitleAuditor::class));

    // The arr surface is faked, so nothing happening can only be the guard: an
    // unguarded run would reach the inspection and dispatch a request.
    expect(ActionRequest::query()->where('type', 'replace_media_file')->count())->toBe(0);
    Http::assertNothingSent();
});
