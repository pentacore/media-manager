<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Enums\SubtitleCaseStatus;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\SubtitleCaseAttempt;
use App\Models\SubtitleUpload;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('subtitle workflow enums expose the complete persistence vocabulary', function (): void {
    expect(array_column(SubtitleCaseStatus::cases(), 'value'))->toBe([
        'observing',
        'bazarr_searching',
        'download_requested',
        'replacement_eligible',
        'advisor_running',
        'replacement_requested',
        'needs_review',
        'resolved',
        'dismissed',
        'handled',
        'superseded',
    ])->and(array_column(SubtitleCaseAttemptType::cases(), 'value'))->toBe([
        'probe',
        'download',
        'advisor',
        'reconciliation',
    ])->and(array_column(SubtitleCaseAttemptOutcome::cases(), 'value'))->toBe([
        'started',
        'succeeded',
        'empty',
        'failed',
        'indeterminate',
        'needs_review',
    ]);
});

test('subtitle workflow records cast their durable state', function (): void {
    $case = SubtitleCase::factory()->create([
        'status' => SubtitleCaseStatus::BazarrSearching,
        'target_ids' => ['series_id' => 12, 'episode_id' => 34, 'episode_file_id' => 56],
        'required_languages' => [['code' => 'eng', 'forced' => false, 'hearing_impaired' => false]],
        'evidence' => ['missing_languages' => ['eng']],
        'grace_until' => now()->addHour(),
        'observed_at' => now(),
        'resolved_at' => now(),
        'superseded_at' => now(),
    ]);

    $attempt = SubtitleCaseAttempt::factory()->for($case)->create([
        'type' => SubtitleCaseAttemptType::Probe,
        'outcome' => SubtitleCaseAttemptOutcome::Empty,
        'candidate_count' => 4,
        'eligible_candidate_count' => 0,
        'summary' => ['below_threshold' => 4],
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
    ]);

    $upload = SubtitleUpload::factory()->for($case)->create([
        'expires_at' => now()->addHour(),
        'consumed_at' => now(),
        'cancelled_at' => now(),
        'cleaned_up_at' => now(),
    ]);

    expect($case->fresh()->status)->toBe(SubtitleCaseStatus::BazarrSearching)
        ->and($case->fresh()->target_ids)->toBeArray()
        ->and($case->fresh()->required_languages)->toBeArray()
        ->and($case->fresh()->evidence)->toBeArray()
        ->and($case->fresh()->grace_until)->toBeInstanceOf(CarbonImmutable::class)
        ->and($case->fresh()->observed_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($case->fresh()->resolved_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($case->fresh()->superseded_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($attempt->fresh()->type)->toBe(SubtitleCaseAttemptType::Probe)
        ->and($attempt->fresh()->outcome)->toBe(SubtitleCaseAttemptOutcome::Empty)
        ->and($attempt->fresh()->candidate_count)->toBe(4)
        ->and($attempt->fresh()->eligible_candidate_count)->toBe(0)
        ->and($attempt->fresh()->summary)->toBe(['below_threshold' => 4])
        ->and($attempt->fresh()->started_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($attempt->fresh()->completed_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($upload->fresh()->expires_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($upload->fresh()->consumed_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($upload->fresh()->cancelled_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($upload->fresh()->cleaned_up_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('attempt count defaults are available before persistence', function (): void {
    $attempt = new SubtitleCaseAttempt();

    expect($attempt->candidate_count)->toBe(0)
        ->and($attempt->eligible_candidate_count)->toBe(0);
});

test('bounded evidence retains nested language details', function (): void {
    $encodedOverhead = strlen(json_encode(['note' => ''], JSON_THROW_ON_ERROR));
    $evidence = [
        'note' => str_repeat('x', 4_000 - $encodedOverhead),
    ];

    expect(strlen(json_encode($evidence, JSON_THROW_ON_ERROR)))->toBe(4_000);

    $case = SubtitleCase::factory()->create(['evidence' => $evidence]);

    expect($case->fresh()->evidence)->toBe($evidence);
});

test('oversized case evidence is rejected at assignment', function (): void {
    $encodedOverhead = strlen(json_encode(['note' => ''], JSON_THROW_ON_ERROR));

    expect(fn (): SubtitleCase => SubtitleCase::factory()->create([
        'evidence' => ['note' => str_repeat('x', 4_001 - $encodedOverhead)],
    ]))->toThrow(InvalidArgumentException::class, 'Subtitle case evidence cannot exceed 4000 encoded bytes.');
});

test('attempt summaries accept only bounded scalar objects', function (): void {
    $summary = [
        'candidate_count' => 4,
        'eligible_candidate_count' => 1,
        'provider' => 'opensubtitles',
        'threshold_met' => true,
        'error_category' => null,
    ];

    $attempt = SubtitleCaseAttempt::factory()->create(['summary' => $summary]);

    expect($attempt->fresh()->summary)->toBe($summary);
});

test('nullable compact JSON attributes round trip', function (): void {
    $case = SubtitleCase::factory()->create(['evidence' => null]);
    $attempt = SubtitleCaseAttempt::factory()->for($case)->create(['summary' => null]);

    expect($case->fresh()->evidence)->toBeNull()
        ->and($attempt->fresh()->summary)->toBeNull();
});

test('empty attempt summaries round trip through the array cast', function (): void {
    $attempt = SubtitleCaseAttempt::factory()->create(['summary' => []]);

    expect($attempt->fresh()->summary)->toBe([]);
});

test('non JSON encodable compact records are rejected at assignment', function (string $attribute, string $message): void {
    $operation = match ($attribute) {
        'evidence' => fn (): SubtitleCase => SubtitleCase::factory()->create([
            'evidence' => ['score' => NAN],
        ]),
        'summary' => fn (): SubtitleCaseAttempt => SubtitleCaseAttempt::factory()->create([
            'summary' => ['score' => NAN],
        ]),
    };

    expect($operation)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'case evidence' => ['evidence', 'Subtitle case evidence must be JSON encodable.'],
    'attempt summary' => ['summary', 'Subtitle case attempt summary must be JSON encodable.'],
]);

test('oversized attempt summaries are rejected at assignment', function (): void {
    $encodedOverhead = strlen(json_encode(['note' => ''], JSON_THROW_ON_ERROR));

    expect(fn (): SubtitleCaseAttempt => SubtitleCaseAttempt::factory()->create([
        'summary' => ['note' => str_repeat('x', 4_001 - $encodedOverhead)],
    ]))->toThrow(InvalidArgumentException::class, 'Subtitle case attempt summary cannot exceed 4000 encoded bytes.');
});

test('nested attempt summary values are rejected at assignment', function (array $summary): void {
    expect(fn (): SubtitleCaseAttempt => SubtitleCaseAttempt::factory()->create([
        'summary' => $summary,
    ]))->toThrow(
        InvalidArgumentException::class,
        'Subtitle case attempt summary values must be scalar or null.',
    );
})->with([
    'candidate list' => [[['provider' => 'opensubtitles']]],
    'nested payload' => [['payload' => ['provider' => 'opensubtitles']]],
]);

test('attempt summary lists are rejected at assignment', function (): void {
    expect(fn (): SubtitleCaseAttempt => SubtitleCaseAttempt::factory()->create([
        'summary' => ['eligible', 'empty'],
    ]))->toThrow(
        InvalidArgumentException::class,
        'Subtitle case attempt summary must be an object.',
    );
});

test('a case owns compact attempts and private uploads', function (): void {
    $case = SubtitleCase::factory()->create();
    $attempt = SubtitleCaseAttempt::factory()->for($case)->create();
    $upload = SubtitleUpload::factory()->for($case)->create();

    expect($case->attempts)->toHaveCount(1)
        ->and($case->attempts->sole()->is($attempt))->toBeTrue()
        ->and($case->uploads)->toHaveCount(1)
        ->and($case->uploads->sole()->is($upload))->toBeTrue()
        ->and($case->bazarrConnection)->toBeInstanceOf(ServiceConnection::class)
        ->and($case->bazarrConnection->type)->toBe(ServiceType::Bazarr)
        ->and($case->serviceConnection)->toBeInstanceOf(ServiceConnection::class)
        ->and($case->serviceConnection->type)->toBe(ServiceType::Sonarr)
        ->and($attempt->subtitleCase->is($case))->toBeTrue()
        ->and($upload->subtitleCase->is($case))->toBeTrue()
        ->and($upload->owner)->toBeInstanceOf(User::class);
});

test('action request relationships are explicitly linked', function (): void {
    $download = ActionRequest::factory()->create(['type' => 'bazarr_download_best']);
    $replacement = ActionRequest::factory()->create(['type' => 'replace_media_file']);
    $attemptAction = ActionRequest::factory()->create(['type' => 'bazarr_search']);
    $uploadAction = ActionRequest::factory()->create(['type' => 'bazarr_upload']);

    $case = SubtitleCase::factory()->create([
        'download_action_request_id' => $download->id,
        'replacement_action_request_id' => $replacement->id,
    ]);
    $attempt = SubtitleCaseAttempt::factory()->for($case)->create(['action_request_id' => $attemptAction->id]);
    $upload = SubtitleUpload::factory()->for($case)->create(['action_request_id' => $uploadAction->id]);

    expect($case->downloadActionRequest->is($download))->toBeTrue()
        ->and($case->replacementActionRequest->is($replacement))->toBeTrue()
        ->and($attempt->actionRequest->is($attemptAction))->toBeTrue()
        ->and($upload->actionRequest->is($uploadAction))->toBeTrue();
});

test('materially identical subtitle cases are unique', function (): void {
    $case = SubtitleCase::factory()->create();

    $caught = null;

    try {
        DB::transaction(fn (): SubtitleCase => SubtitleCase::factory()->create([
            'bazarr_connection_id' => $case->bazarr_connection_id,
            'service_connection_id' => $case->service_connection_id,
            'file_fingerprint' => $case->file_fingerprint,
            'requirements_fingerprint' => $case->requirements_fingerprint,
        ]));
    } catch (QueryException $queryException) {
        $caught = $queryException;
    }

    expect($caught)->toBeInstanceOf(QueryException::class)
        ->and((string) $caught?->getCode())->toBe('23505');
});

test('workflow lookup indexes are present', function (): void {
    $caseIndexes = collect(Schema::getIndexes('subtitle_cases'));
    $uploadIndexes = collect(Schema::getIndexes('subtitle_uploads'));

    expect($caseIndexes->contains(
        fn (array $index): bool => $index['name'] === 'subtitle_cases_material_identity_unique'
            && $index['unique']
            && $index['columns'] === [
                'bazarr_connection_id',
                'service_connection_id',
                'file_fingerprint',
                'requirements_fingerprint',
            ],
    ))->toBeTrue()
        ->and($caseIndexes->contains(
            fn (array $index): bool => ! $index['unique']
                && $index['columns'] === ['status', 'grace_until'],
        ))->toBeTrue()
        ->and($caseIndexes->contains(
            fn (array $index): bool => ! $index['unique']
                && $index['columns'] === ['service_connection_id'],
        ))->toBeTrue()
        ->and($uploadIndexes->contains(
            fn (array $index): bool => ! $index['unique']
                && $index['columns'] === ['user_id'],
        ))->toBeTrue();
});

test('private upload paths are not serialized', function (): void {
    $upload = SubtitleUpload::factory()->create([
        'path' => 'bazarr-subtitle-uploads/private-file.srt',
    ]);

    expect($upload->toArray())
        ->not->toHaveKey('path')
        ->and($upload->path)->toBe('bazarr-subtitle-uploads/private-file.srt');
});

test('nullable audit links survive deleted users and action requests', function (): void {
    $case = SubtitleCase::factory()->create();
    $attemptAction = ActionRequest::factory()->create(['type' => 'bazarr_search']);
    $uploadAction = ActionRequest::factory()->create(['type' => 'bazarr_upload']);
    $attempt = SubtitleCaseAttempt::factory()->for($case)->create(['action_request_id' => $attemptAction->id]);
    $upload = SubtitleUpload::factory()->for($case)->create(['action_request_id' => $uploadAction->id]);

    $attemptAction->delete();
    $uploadAction->delete();
    $upload->owner->delete();

    expect($attempt->fresh())->not->toBeNull()
        ->and($attempt->fresh()->action_request_id)->toBeNull()
        ->and($upload->fresh())->not->toBeNull()
        ->and($upload->fresh()->action_request_id)->toBeNull()
        ->and($upload->fresh()->user_id)->toBeNull();
});

test('a case with a :dataset cannot be deleted directly', function (string $childModel): void {
    $case = SubtitleCase::factory()->create();
    $child = $childModel::factory()->for($case)->create();
    $caught = null;

    try {
        DB::transaction(fn (): ?bool => $case->delete());
    } catch (QueryException $queryException) {
        $caught = $queryException;
    }

    expect($caught)->toBeInstanceOf(QueryException::class)
        ->and((string) $caught?->getCode())->toBe('23001');
    $this->assertModelExists($case);
    $this->assertModelExists($child);
})->with([
    'subtitle attempt' => SubtitleCaseAttempt::class,
    'subtitle upload' => SubtitleUpload::class,
]);
