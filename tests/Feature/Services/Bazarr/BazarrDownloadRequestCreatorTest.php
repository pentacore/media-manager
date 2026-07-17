<?php

declare(strict_types=1);

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

test('a disabled action type creates no request and leaves the case searching', function (): void {
    ActionTypeConfig::query()->where('type', 'bazarr_download_best')->update(['is_enabled' => false]);

    $request = resolve(BazarrDownloadRequestCreator::class)->create($this->case, $this->requirement);

    expect($request)->toBeNull()
        ->and($this->case->fresh()->status)->toBe(SubtitleCaseStatus::BazarrSearching)
        ->and(ActionRequest::query()->count())->toBe(0);
});
