<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\BazarrServiceRole;
use App\Enums\SubtitleCaseStatus;
use App\Jobs\ExecuteActionRequest;
use App\Jobs\PruneSubtitleUploads;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\BazarrServiceLink;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\SubtitleUpload;
use App\Models\User;
use App\Services\Bazarr\BazarrActions;
use App\Services\Bazarr\BazarrMediaFingerprint;
use App\Services\Bazarr\SubtitleCaseReconciler;
use App\Settings\BazarrAutomationSettings;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('u', 32)));
    Http::preventStrayRequests();
    Storage::fake('local');
    $this->seed(ActionTypeConfigSeeder::class);

    $this->bazarr = ServiceConnection::factory()->bazarr()->create([
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);
    $this->radarr = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.test',
        'api_key' => 'radarr-secret',
    ]);
    BazarrServiceLink::factory()->create([
        'bazarr_connection_id' => $this->bazarr->id,
        'related_connection_id' => $this->radarr->id,
        'role' => BazarrServiceRole::Radarr,
    ]);
    $this->movie = [
        'radarrId' => 801,
        'title' => 'Example Movie',
        'sceneName' => 'Example.Movie.2024.1080p',
        'path' => '/media/movies/Example Movie (2024)',
        'monitored' => true,
        'subtitles' => [],
    ];
    $this->targetFingerprint = new BazarrMediaFingerprint()->make('movie', $this->movie);

    Http::fake(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match ($path) {
            '/api/movies' => Http::response(['data' => [$this->movie], 'total' => 1]),
            '/api/movies/history' => Http::response(['data' => [], 'total' => 0]),
            // The linked case is keyed by the live Radarr file identity, exactly as
            // reconciliation keys its own cases.
            '/api/v3/movie/801' => Http::response(['id' => 801, 'title' => 'Example Movie', 'movieFileId' => 91]),
            '/api/v3/moviefile/91' => Http::response([
                'id' => 91,
                'size' => 8_589_934_592,
                'dateAdded' => '2026-07-20T08:00:00Z',
                'sceneName' => 'Example.Movie.2024.1080p',
                'path' => '/media/movies/Example Movie (2024)/movie.mkv',
            ]),
            '/api/swagger.json' => Http::response([
                'swagger' => '2.0',
                'basePath' => '/api',
                'info' => ['title' => 'Bazarr', 'version' => '1.6.0'],
                'paths' => [
                    // Upload capability requires the endpoint on both media types.
                    '/movies/subtitles' => [
                        'post' => ['responses' => ['204' => ['description' => 'OK']]],
                    ],
                    '/episodes/subtitles' => [
                        'post' => ['responses' => ['204' => ['description' => 'OK']]],
                    ],
                ],
            ]),
            default => Http::response(['data' => []]),
        };
    });
});

test('viewers cannot stage subtitle uploads', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('bazarr.uploads.store'), [
            ...uploadPayload($this),
            'subtitle_file' => validSubtitleUpload(),
        ])
        ->assertForbidden();

    expect(SubtitleUpload::query()->count())->toBe(0)
        ->and(ActionRequest::query()->count())->toBe(0);
});

test('members privately stage a validated upload and approval-gated action atomically', function (): void {
    $contents = file_get_contents(base_path('tests/Fixtures/Subtitles/valid-en.srt'));

    $this->actingAs(User::factory()->member()->create())
        ->withHeader('Accept', 'application/json')
        ->post(route('bazarr.uploads.store'), [
            ...uploadPayload($this),
            'subtitle_file' => UploadedFile::fake()->createWithContent('My English Subtitle.srt', (string) $contents),
        ])
        ->assertCreated()
        ->assertJsonPath('status', ActionRequestStatus::Pending->value)
        ->assertJsonPath('type', 'bazarr_upload_subtitle');

    $subtitleUpload = SubtitleUpload::query()->sole();
    $actionRequest = ActionRequest::query()->sole();

    expect($subtitleUpload->path)
        ->toMatch('/^bazarr-subtitle-uploads\/[0-9a-f-]{36}\.srt$/')
        ->and($subtitleUpload->display_name)->toBe('My English Subtitle.srt')
        ->and($subtitleUpload->checksum)->toBe(hash('sha256', (string) $contents))
        ->and($subtitleUpload->size_bytes)->toBe(strlen((string) $contents))
        ->and($subtitleUpload->action_request_id)->toBe($actionRequest->id)
        // The linked payload carries the case's own Arr file identity — the Bazarr
        // media fingerprint the browser sent only gates staleness at request time.
        ->and($actionRequest->payload)->toMatchArray([
            'subtitle_upload_id' => $subtitleUpload->id,
            'language' => 'eng',
            'forced' => false,
            'hearing_impaired' => false,
            'target_fingerprint' => SubtitleCase::query()->sole()->file_fingerprint,
        ])
        ->and($actionRequest->payload['target_fingerprint'])->not->toBe($this->targetFingerprint)
        ->and($actionRequest->payload)->not->toHaveKey('path')
        ->and($actionRequest->payload)->not->toHaveKey('subtitle_file');

    Storage::disk('local')->assertExists($subtitleUpload->path);
});

test('subtitle uploads honor the configured size limit and staging expiry', function (): void {
    resolve(BazarrAutomationSettings::class)->setConfiguration([
        'upload_max_kilobytes' => 64,
        'upload_expiry_hours' => 12,
    ]);

    $this->actingAs(User::factory()->member()->create())
        ->withHeader('Accept', 'application/json')
        ->post(route('bazarr.uploads.store'), [
            ...uploadPayload($this),
            'subtitle_file' => UploadedFile::fake()->createWithContent(
                'too-large.srt',
                "1\n00:00:01,000 --> 00:00:02,000\nHello\n".str_repeat('a', 70 * 1024),
            ),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('subtitle_file');

    $this->actingAs(User::factory()->member()->create())
        ->withHeader('Accept', 'application/json')
        ->post(route('bazarr.uploads.store'), [
            ...uploadPayload($this),
            'subtitle_file' => validSubtitleUpload(),
        ])
        ->assertCreated();

    expect(SubtitleUpload::query()->sole()->expires_at->diffInHours(now(), true))
        ->toEqualWithDelta(12, 0.1);
});

test('subtitle uploads enforce extension mime size and text content validation', function (UploadedFile $uploadedFile): void {
    $this->actingAs(User::factory()->member()->create())
        ->withHeader('Accept', 'application/json')
        ->post(route('bazarr.uploads.store'), [
            ...uploadPayload($this),
            'subtitle_file' => $uploadedFile,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('subtitle_file');

    expect(SubtitleUpload::query()->count())->toBe(0)
        ->and(ActionRequest::query()->count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('bazarr-subtitle-uploads');
})->with([
    'unsupported extension' => fn (): UploadedFile => UploadedFile::fake()->createWithContent('subtitle.txt', "Hello\n"),
    'mismatched detected mime' => fn (): UploadedFile => UploadedFile::fake()->createWithContent('subtitle.srt', '<html><body>Not a subtitle</body></html>'),
    'binary content' => fn (): UploadedFile => UploadedFile::fake()->createWithContent('subtitle.srt', "1\0binary"),
    'larger than five megabytes' => fn (): UploadedFile => UploadedFile::fake()->create('subtitle.srt', 5121, 'text/plain'),
]);

test('failed action creation cancels the staged upload and deletes the staged file', function (): void {
    ActionTypeConfig::query()
        ->where('type', 'bazarr_upload_subtitle')
        ->update(['is_enabled' => false]);

    $this->actingAs(User::factory()->member()->create())
        ->withHeader('Accept', 'application/json')
        ->post(route('bazarr.uploads.store'), [
            ...uploadPayload($this),
            'subtitle_file' => validSubtitleUpload(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('subtitle_file');

    // The row survives the failure as the record of a staged file, marked cancelled
    // and cleaned because the file really went away.
    $subtitleUpload = SubtitleUpload::query()->sole();

    expect($subtitleUpload->action_request_id)->toBeNull()
        ->and($subtitleUpload->cancelled_at)->not->toBeNull()
        ->and($subtitleUpload->cleaned_up_at)->not->toBeNull()
        ->and(ActionRequest::query()->count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('bazarr-subtitle-uploads');
});

test('a refused upload leaves a case reconciliation can eventually advance', function (): void {
    ActionTypeConfig::query()
        ->where('type', 'bazarr_upload_subtitle')
        ->update(['is_enabled' => false]);

    $this->actingAs(User::factory()->member()->create())
        ->withHeader('Accept', 'application/json')
        ->post(route('bazarr.uploads.store'), [
            ...uploadPayload($this),
            'subtitle_file' => validSubtitleUpload(),
        ])
        ->assertUnprocessable();

    // Without a grace deadline advanceElapsedGrace() never moves an observing case
    // on, so this row would sit here forever while the subtitle stays missing.
    $subtitleCase = SubtitleCase::query()->sole();

    expect($subtitleCase->status)->toBe(SubtitleCaseStatus::Observing)
        ->and($subtitleCase->grace_until)->not->toBeNull();

    Date::setTestNow($subtitleCase->grace_until->addMinute());
    resolve(SubtitleCaseReconciler::class)->reconcile([
        'bazarr_connection_id' => $this->bazarr->id,
        'service_connection_id' => $this->radarr->id,
        'service' => 'radarr',
        'media_type' => 'movie',
        'scope' => $subtitleCase->scope,
        'target_ids' => $subtitleCase->target_ids,
        'display_name' => 'Example Movie',
        'required_languages' => ['eng'],
        'missing_languages' => ['eng'],
        'current_subtitles' => [],
        'monitored' => true,
        'file_fingerprint' => $subtitleCase->file_fingerprint,
        'requirements_fingerprint' => $subtitleCase->requirements_fingerprint,
    ]);

    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::BazarrSearching);
});

test('a failure staging the durable rows removes the staged file', function (): void {
    // The case row cannot be written, so nothing commits — and with no row for the
    // prune sweep to find, the file has to go now.
    Schema::drop('subtitle_uploads');

    $this->actingAs(User::factory()->member()->create())
        ->withHeader('Accept', 'application/json')
        ->post(route('bazarr.uploads.store'), [
            ...uploadPayload($this),
            'subtitle_file' => validSubtitleUpload(),
        ])
        ->assertStatus(500);

    Storage::disk('local')->assertDirectoryEmpty('bazarr-subtitle-uploads');
});

test('a staged upload whose rollback cannot delete the file stays prunable', function (): void {
    ActionTypeConfig::query()
        ->where('type', 'bazarr_upload_subtitle')
        ->update(['is_enabled' => false]);
    failLocalDiskDeletes();

    $this->actingAs(User::factory()->member()->create())
        ->withHeader('Accept', 'application/json')
        ->post(route('bazarr.uploads.store'), [
            ...uploadPayload($this),
            'subtitle_file' => validSubtitleUpload(),
        ])
        ->assertUnprocessable();

    // A transient unlink failure must leave the row claimable, or the staged file
    // becomes an untracked orphan no prune cycle can ever find.
    $subtitleUpload = SubtitleUpload::query()->sole();

    expect($subtitleUpload->cancelled_at)->not->toBeNull()
        ->and($subtitleUpload->cleaned_up_at)->toBeNull();
});

test('auto approved upload execution is deferred until its transaction commits', function (): void {
    Queue::fake();
    ActionTypeConfig::query()
        ->where('type', 'bazarr_upload_subtitle')
        ->update(['requires_approval' => false]);

    $this->actingAs(User::factory()->member()->create())
        ->withHeader('Accept', 'application/json')
        ->post(route('bazarr.uploads.store'), [
            ...uploadPayload($this),
            'subtitle_file' => validSubtitleUpload(),
        ])
        ->assertCreated()
        ->assertJsonPath('status', ActionRequestStatus::Approved->value);

    Queue::assertPushed(
        ExecuteActionRequest::class,
        fn (ExecuteActionRequest $executeActionRequest): bool => $executeActionRequest->afterCommit === true
            && $executeActionRequest->actionRequest->id === ActionRequest::query()->sole()->id,
    );
});

test('a staged upload executes end to end once its action is approved', function (): void {
    ActionTypeConfig::query()
        ->where('type', 'bazarr_upload_subtitle')
        ->update(['requires_approval' => false]);

    $this->actingAs(User::factory()->member()->create())
        ->withHeader('Accept', 'application/json')
        ->post(route('bazarr.uploads.store'), [
            ...uploadPayload($this),
            'subtitle_file' => validSubtitleUpload(),
        ])
        ->assertCreated();

    $actionRequest = ActionRequest::query()->sole();
    $subtitleUpload = SubtitleUpload::query()->sole();

    // The executor revalidates a linked case against the live Arr file identity
    // before writing, so a case keyed any other way would be rejected here and the
    // subtitle would never reach Bazarr.
    new ExecuteActionRequest($actionRequest)->handle();

    expect($actionRequest->fresh()->status)->toBe(ActionRequestStatus::Completed)
        ->and($subtitleUpload->refresh()->consumed_at)->not->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://bazarr.test/api/movies/subtitles');
});

test('pruning idempotently cleans expired consumed cancelled and denied uploads', function (): void {
    $case = SubtitleCase::factory()->create();
    $deniedAction = ActionRequest::factory()->create(['status' => ActionRequestStatus::Rejected]);
    $activeAction = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);

    $uploads = collect([
        'expired' => SubtitleUpload::factory()->for($case)->create(['expires_at' => now()->subMinute()]),
        'consumed' => SubtitleUpload::factory()->for($case)->create(['consumed_at' => now()]),
        'cancelled' => SubtitleUpload::factory()->for($case)->create(['cancelled_at' => now()]),
        'denied' => SubtitleUpload::factory()->for($case)->create(['action_request_id' => $deniedAction->id]),
        'active' => SubtitleUpload::factory()->for($case)->create([
            'action_request_id' => $activeAction->id,
            'expires_at' => now()->addHour(),
        ]),
    ]);

    $uploads->each(function (SubtitleUpload $subtitleUpload): void {
        Storage::disk('local')->put($subtitleUpload->path, 'subtitle');
    });

    $job = new PruneSubtitleUploads;
    $job->handle();
    $job->handle();

    foreach (['expired', 'consumed', 'cancelled', 'denied'] as $key) {
        $upload = $uploads[$key]->refresh();

        expect($upload->cleaned_up_at)->not->toBeNull();
        Storage::disk('local')->assertMissing($upload->path);
    }

    expect($uploads['active']->refresh()->cleaned_up_at)->toBeNull();
    Storage::disk('local')->assertExists($uploads['active']->path);
});

test('pruning skips an upload whose execution claim lock is held so a mid-write file survives', function (): void {
    config()->set('cache.default', 'array');
    Cache::store('array')->flush();

    $case = SubtitleCase::factory()->create();
    $upload = SubtitleUpload::factory()->for($case)->create(['expires_at' => now()->subMinute()]);
    Storage::disk('local')->put($upload->path, 'subtitle');

    // The executor is mid-write and holds the per-upload claim lock. Even though
    // the upload has expired, prune must not delete the staged file under it.
    $lock = Cache::lock('subtitle-upload:'.$upload->id, 120);
    expect($lock->get())->toBeTrue();

    try {
        new PruneSubtitleUploads()->handle();
    } finally {
        $lock->release();
    }

    expect($upload->refresh()->cleaned_up_at)->toBeNull();
    Storage::disk('local')->assertExists($upload->path);

    // Once the executor releases the lock, a later prune cycle cleans it up.
    new PruneSubtitleUploads()->handle();

    expect($upload->refresh()->cleaned_up_at)->not->toBeNull();
    Storage::disk('local')->assertMissing($upload->path);
});

test('an upload action already pruned by the cleanup job aborts cleanly without a remote call', function (): void {
    config()->set('cache.default', 'array');
    Cache::store('array')->flush();

    // Prune already ran: the row is marked cleaned and its staged file is gone.
    $upload = SubtitleUpload::factory()->create([
        'consumed_at' => now(),
        'cleaned_up_at' => now(),
    ]);

    $request = ActionRequest::factory()->create([
        'type' => 'bazarr_upload_subtitle',
        'payload' => [
            'title' => 'Upload subtitle',
            'bazarr_connection_id' => $this->bazarr->id,
            'service_connection_id' => $this->radarr->id,
            'media_type' => 'movie',
            'target_ids' => ['radarr_id' => 801, 'movie_file_id' => 5],
            'target_fingerprint' => $this->targetFingerprint,
            'subtitle_upload_id' => $upload->id,
            'language' => 'eng',
            'forced' => false,
            'hearing_impaired' => false,
        ],
    ]);

    expect(fn (): array => resolve(BazarrActions::class)->execute($request))
        ->toThrow(InvalidArgumentException::class, 'unavailable');

    Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['POST', 'PATCH', 'DELETE'], true));
});

test('pruning leaves an upload uncleaned when its staged file could not be deleted', function (): void {
    config()->set('cache.default', 'array');
    Cache::store('array')->flush();

    $upload = SubtitleUpload::factory()->create(['expires_at' => now()->subMinute()]);
    Storage::disk('local')->put($upload->path, 'subtitle');

    // A transient filesystem failure must not record cleanup: prune only revisits
    // rows with a null cleaned_up_at, so the staged subtitle would otherwise stay
    // on disk as an untracked orphan forever.
    failLocalDiskDeletes();

    new PruneSubtitleUploads()->handle();

    expect($upload->refresh()->cleaned_up_at)->toBeNull();
});

test('an executed upload whose staged file could not be deleted stays claimable for prune', function (): void {
    config()->set('cache.default', 'array');
    Cache::store('array')->flush();

    $upload = SubtitleUpload::factory()->create([
        'checksum' => hash('sha256', 'subtitle'),
        'expires_at' => now()->addHour(),
    ]);
    Storage::disk('local')->put($upload->path, 'subtitle');

    failLocalDiskDeletes();

    $request = ActionRequest::factory()->create([
        'type' => 'bazarr_upload_subtitle',
        'payload' => [
            'title' => 'Upload subtitle',
            'bazarr_connection_id' => $this->bazarr->id,
            'service_connection_id' => $this->radarr->id,
            'media_type' => 'movie',
            'target_ids' => ['radarr_id' => 801],
            'target_fingerprint' => $this->targetFingerprint,
            'subtitle_upload_id' => $upload->id,
            'language' => 'eng',
            'forced' => false,
            'hearing_impaired' => false,
        ],
    ]);

    resolve(BazarrActions::class)->execute($request);

    // Bazarr accepted the subtitle, so the upload is consumed — but cleanup is
    // only recorded once the file really went away.
    expect($upload->refresh()->consumed_at)->not->toBeNull()
        ->and($upload->refresh()->cleaned_up_at)->toBeNull();
});

/**
 * Swap the local disk for one whose delete() reports failure, as a filesystem
 * error would. The variable name is what Rector derives from Mockery's return
 * type; it is confined to this helper.
 */
function failLocalDiskDeletes(): void
{
    $legacyMock = Mockery::mock(Storage::disk('local'))->makePartial();
    $legacyMock->shouldReceive('delete')->once()->andReturnFalse();
    Storage::set('local', $legacyMock);
}

/**
 * @return array<string, mixed>
 */
function uploadPayload(object $test): array
{
    return [
        'connection' => $test->bazarr->id,
        'media_type' => 'movie',
        'media_id' => 801,
        'target_fingerprint' => $test->targetFingerprint,
        'language' => 'eng',
        'forced' => false,
        'hearing_impaired' => false,
    ];
}

function validSubtitleUpload(): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        'valid-en.srt',
        "1\n00:00:00,000 --> 00:00:02,000\nHello\n",
    );
}
