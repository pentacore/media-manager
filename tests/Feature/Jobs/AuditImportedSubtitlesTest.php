<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\AuditImportedSubtitles;
use App\Jobs\ProcessWebhookEvent;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\MediaReplacement\ImportedSubtitleAuditor;
use App\Settings\MediaReplacementSettings;
use App\Settings\WebhookSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

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

/**
 * A stored Download event for the series `fakeAuditArrForJob()` describes.
 */
function importedDownloadEvent(ServiceConnection $serviceConnection): WebhookEvent
{
    return WebhookEvent::factory()->create([
        'service_connection_id' => $serviceConnection->id,
        'event_type' => 'Download',
        'payload' => [
            'eventType' => 'Download',
            'series' => ['id' => 42, 'title' => 'Tagged Show'],
            'episodes' => [['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1]],
            'downloadId' => 'DL-ORGANIC',
        ],
    ]);
}

/**
 * The job as the handlers queue it: event id, connection id and payload, all
 * read off the event at dispatch time the way queueFor() does.
 */
function auditJobFor(WebhookEvent $webhookEvent): AuditImportedSubtitles
{
    return new AuditImportedSubtitles(
        $webhookEvent->id,
        $webhookEvent->service_connection_id,
        $webhookEvent->payload,
    );
}

test('it delegates a tagged import to the auditor', function (): void {
    // Delegation is observed through its effect: with the connection tagged and
    // the imported file missing a required language, the auditor dispatches a
    // replace_media_file ActionRequest. Asserting the effect rather than a mock
    // call is forced here — ImportedSubtitleAuditor is final readonly — and is
    // the stronger assertion anyway.
    $serviceConnection = taggedSonarrConnection();
    fakeAuditArrForJob(['subtitles' => 'Japanese']);

    $webhookEvent = importedDownloadEvent($serviceConnection);

    auditJobFor($webhookEvent)->handle(resolve(ImportedSubtitleAuditor::class));

    $actionRequest = ActionRequest::query()->where('type', 'replace_media_file')->sole();

    // The event is asserted too: the job must pass it through so the request is
    // traceable back to the import that caused it.
    expect($actionRequest->webhook_event_id)->toBe($webhookEvent->id)
        ->and($actionRequest->payload['auto_check_key'])->toBe(sprintf('sonarr:%d:42-101', $serviceConnection->id));
});

test('it audits from the carried state when the event row has been pruned', function (): void {
    // The event row is not guaranteed to outlive the 30 second delay, so the job
    // carries the connection id and payload rather than re-reading them. This is
    // the case that makes it necessary; the capture-off test below is the same
    // thing through the real code path that deletes the row.
    $serviceConnection = taggedSonarrConnection();
    fakeAuditArrForJob(['subtitles' => 'Japanese']);

    $webhookEvent = importedDownloadEvent($serviceConnection);
    $auditImportedSubtitles = auditJobFor($webhookEvent);

    $webhookEvent->delete();

    $auditImportedSubtitles->handle(resolve(ImportedSubtitleAuditor::class));

    $actionRequest = ActionRequest::query()->where('type', 'replace_media_file')->sole();

    // Losing the link is the whole cost of a pruned event: webhook_event_id is
    // nullable and the database nulls it on delete anyway. The connection stays
    // pinned in the payload, so the executor still acts on the right instance.
    expect($actionRequest->webhook_event_id)->toBeNull()
        ->and($actionRequest->payload['service_connection_id'])->toBe($serviceConnection->id);
});

test('it drops the audit with a warning when the connection is gone', function (): void {
    // The only remaining bail, and now a reachable one: deleting the connection
    // cascades its webhook events away, so neither the carried id nor the event
    // resolves. It must not be silent — a dropped check that logged nothing is
    // exactly what made the capture-off case invisible.
    $serviceConnection = taggedSonarrConnection();
    fakeAuditArrForJob(['subtitles' => 'Japanese']);

    $webhookEvent = importedDownloadEvent($serviceConnection);
    $auditImportedSubtitles = auditJobFor($webhookEvent);
    $connectionId = $serviceConnection->id;

    $serviceConnection->delete();

    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Automatic subtitle check dropped: the service connection no longer exists.'
            && $context['service_connection_id'] === $connectionId);

    $auditImportedSubtitles->handle(resolve(ImportedSubtitleAuditor::class));

    // The arr surface is faked, so nothing happening can only be the guard: an
    // unguarded run would reach the inspection and dispatch a request.
    expect(ActionRequest::query()->where('type', 'replace_media_file')->count())->toBe(0);
    Http::assertNothingSent();
});

test('the audit still runs when webhook capture is off', function (): void {
    // With capture off, ProcessWebhookEvent deletes the event row as soon as the
    // handler returns — 30 seconds before this job runs. Every earlier consumer
    // of a WebhookEvent runs inline, before that delete, so nothing caught this:
    // re-reading the event here made the whole feature a silent no-op for any
    // operator with capture turned off.
    $serviceConnection = taggedSonarrConnection();
    fakeAuditArrForJob(['subtitles' => 'Japanese']);
    resolve(WebhookSettings::class)->setCaptureEnabled(false);

    ActionTypeConfig::factory()->create([
        'type' => 'emby_library_scan',
        'requires_approval' => false,
        'is_enabled' => true,
    ]);

    $webhookEvent = importedDownloadEvent($serviceConnection);

    new ProcessWebhookEvent($webhookEvent)->handle();

    expect(WebhookEvent::query()->whereKey($webhookEvent->id)->exists())->toBeFalse();

    // The job is faked in tests/Pest.php, so the handler's dispatch is captured
    // instead of running inline while the row still exists — which is also the
    // ordering production gets from a real queue.
    Queue::pushed(AuditImportedSubtitles::class)->sole()->handle(resolve(ImportedSubtitleAuditor::class));

    expect(ActionRequest::query()->where('type', 'replace_media_file')->sole()->webhook_event_id)->toBeNull();
});
