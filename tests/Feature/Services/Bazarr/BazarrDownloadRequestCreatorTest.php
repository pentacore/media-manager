<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\SubtitleCaseStatus;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\SubtitleCase;
use App\Services\Bazarr\BazarrDownloadRequestCreator;

beforeEach(function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'bazarr_download_best',
        'requires_approval' => true,
        'is_enabled' => true,
    ]);
    $this->case = SubtitleCase::factory()->create([
        'status' => SubtitleCaseStatus::BazarrSearching,
        'target_ids' => [
            'series_id' => 101,
            'episode_id' => 701,
            'episode_file_id' => 501,
        ],
        'evidence' => [
            'display_name' => 'Frieren — Part One',
            'missing_languages' => ['eng'],
        ],
    ]);
    $this->requirement = [
        'code' => 'eng',
        'forced' => false,
        'hearing_impaired' => false,
    ];
});

test('it creates and links one governed Bazarr download request', function (): void {
    $request = resolve(BazarrDownloadRequestCreator::class)->create($this->case, $this->requirement);

    expect($request)->toBeInstanceOf(ActionRequest::class)
        ->and($request->type)->toBe('bazarr_download_best')
        ->and($request->payload)->toBe([
            'title' => 'Download eng subtitles for Frieren — Part One',
            'bazarr_connection_id' => $this->case->bazarr_connection_id,
            'service_connection_id' => $this->case->service_connection_id,
            'subtitle_case_id' => $this->case->id,
            'media_type' => 'episode',
            'target_ids' => $this->case->target_ids,
            'target_fingerprint' => $this->case->file_fingerprint,
            'language' => 'eng',
            'forced' => false,
            'hearing_impaired' => false,
        ])
        ->and($this->case->fresh()->download_action_request_id)->toBe($request->id)
        ->and($this->case->fresh()->status)->toBe(SubtitleCaseStatus::DownloadRequested);
});

test('repeated and stale workers cannot create duplicate requests', function (): void {
    $bazarrDownloadRequestCreator = resolve(BazarrDownloadRequestCreator::class);
    $firstWorkerCase = $this->case->fresh();
    $secondWorkerCase = $this->case->fresh();

    $first = $bazarrDownloadRequestCreator->create($firstWorkerCase, $this->requirement);
    $second = $bazarrDownloadRequestCreator->create($secondWorkerCase, $this->requirement);

    expect($second?->is($first))->toBeTrue()
        ->and(ActionRequest::query()->where('type', 'bazarr_download_best')->count())->toBe(1);
});

test('a terminal historical request is not reported as a newly queued download', function (
    ActionRequestStatus $actionRequestStatus,
): void {
    // The download_requests map survives reconciliation, so a request that has
    // already run must not be handed back as if it were freshly queued: the
    // probe would score Succeeded, no empty probe would accumulate, and the
    // replacement escalation threshold could never be reached.
    $historical = ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'status' => $actionRequestStatus,
        'payload' => ['subtitle_case_id' => $this->case->id, 'language' => 'eng'],
    ]);
    $this->case->forceFill([
        'evidence' => [
            ...$this->case->evidence,
            'download_requests' => ['eng|0|0' => $historical->id],
        ],
    ])->save();

    $request = resolve(BazarrDownloadRequestCreator::class)->create($this->case->fresh(), $this->requirement);

    expect($request)->toBeNull()
        ->and($this->case->fresh()->status)->toBe(SubtitleCaseStatus::BazarrSearching)
        ->and(ActionRequest::query()->where('type', 'bazarr_download_best')->count())->toBe(1);
})->with([
    'completed' => [ActionRequestStatus::Completed],
    'failed' => [ActionRequestStatus::Failed],
    'rejected' => [ActionRequestStatus::Rejected],
]);

test('an in-flight historical request is still reused', function (
    ActionRequestStatus $actionRequestStatus,
): void {
    $historical = ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'status' => $actionRequestStatus,
        'payload' => ['subtitle_case_id' => $this->case->id, 'language' => 'eng'],
    ]);
    $this->case->forceFill([
        'evidence' => [
            ...$this->case->evidence,
            'download_requests' => ['eng|0|0' => $historical->id],
        ],
    ])->save();

    $request = resolve(BazarrDownloadRequestCreator::class)->create($this->case->fresh(), $this->requirement);

    expect($request?->id)->toBe($historical->id)
        ->and(ActionRequest::query()->where('type', 'bazarr_download_best')->count())->toBe(1);
})->with([
    'pending' => [ActionRequestStatus::Pending],
    'approved' => [ActionRequestStatus::Approved],
    'executing' => [ActionRequestStatus::Executing],
]);

test('a disabled action type creates no request and leaves the case searching', function (): void {
    ActionTypeConfig::query()->where('type', 'bazarr_download_best')->update(['is_enabled' => false]);

    $request = resolve(BazarrDownloadRequestCreator::class)->create($this->case, $this->requirement);

    expect($request)->toBeNull()
        ->and($this->case->fresh()->status)->toBe(SubtitleCaseStatus::BazarrSearching)
        ->and(ActionRequest::query()->count())->toBe(0);
});
