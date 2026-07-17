<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\BazarrServiceRole;
use App\Jobs\ExecuteActionRequest;
use App\Jobs\PruneSubtitleUploads;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\BazarrServiceLink;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\SubtitleUpload;
use App\Models\User;
use App\Services\Bazarr\BazarrMediaFingerprint;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
    $this->radarr = ServiceConnection::factory()->radarr()->create();
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
            '/api/swagger.json' => Http::response([
                'swagger' => '2.0',
                'basePath' => '/api',
                'info' => ['title' => 'Bazarr', 'version' => '1.6.0'],
                'paths' => [
                    '/movies/subtitles' => [
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
        ->and($actionRequest->payload)->toMatchArray([
            'subtitle_upload_id' => $subtitleUpload->id,
            'language' => 'eng',
            'forced' => false,
            'hearing_impaired' => false,
            'target_fingerprint' => $this->targetFingerprint,
        ])
        ->and($actionRequest->payload)->not->toHaveKey('path')
        ->and($actionRequest->payload)->not->toHaveKey('subtitle_file');

    Storage::disk('local')->assertExists($subtitleUpload->path);
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

test('failed action creation rolls back database state and deletes the staged file', function (): void {
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

    expect(SubtitleCase::query()->count())->toBe(0)
        ->and(SubtitleUpload::query()->count())->toBe(0)
        ->and(ActionRequest::query()->count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('bazarr-subtitle-uploads');
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

    $job = new PruneSubtitleUploads();
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
