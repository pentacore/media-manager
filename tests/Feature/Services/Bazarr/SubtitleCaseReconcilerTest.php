<?php

declare(strict_types=1);

use App\Enums\SubtitleCaseStatus;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Services\Bazarr\SubtitleCaseReconciler;
use App\Settings\BazarrAutomationSettings;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * @return array<string, mixed>
 */
function subtitleCaseCandidate(ServiceConnection $bazarr, ServiceConnection $sonarr, array $overrides = []): array
{
    return [
        'bazarr_connection_id' => $bazarr->id,
        'service_connection_id' => $sonarr->id,
        'service' => 'sonarr',
        'media_type' => 'episode',
        'scope' => 'anime',
        'target_ids' => [
            'series_id' => 101,
            'episode_id' => 701,
            'episode_file_id' => 501,
        ],
        'display_name' => 'Frieren — Part One',
        'required_languages' => ['eng'],
        'missing_languages' => ['eng'],
        'current_subtitles' => ['jpn'],
        'monitored' => true,
        'file_fingerprint' => hash('sha256', 'file-v1'),
        'requirements_fingerprint' => hash('sha256', 'requirements-v1'),
        ...$overrides,
    ];
}

beforeEach(function (): void {
    $this->bazarr = ServiceConnection::factory()->bazarr()->create();
    $this->sonarr = ServiceConnection::factory()->sonarr()->create();
    resolve(BazarrAutomationSettings::class)->setConfiguration([
        'enabled' => true,
        'grace_hours' => ['anime' => 24, 'tv' => 72, 'movie' => 72],
    ]);
});

test('a missing monitored file creates one observing case with compact evidence', function (): void {
    Date::setTestNow('2026-07-17 10:00:00');

    $case = resolve(SubtitleCaseReconciler::class)->reconcile(
        subtitleCaseCandidate($this->bazarr, $this->sonarr),
    );

    expect($case)->toBeInstanceOf(SubtitleCase::class)
        ->and($case->status)->toBe(SubtitleCaseStatus::Observing)
        ->and($case->required_languages)->toBe([[
            'code' => 'eng',
            'forced' => false,
            'hearing_impaired' => false,
        ]])
        ->and($case->evidence)->toBe([
            'display_name' => 'Frieren — Part One',
            'missing_languages' => ['eng'],
            'current_subtitles' => ['jpn'],
            'monitored' => true,
        ])
        ->and($case->grace_until?->toISOString())->toBe('2026-07-18T10:00:00.000000Z');
});

test('the same material identity is idempotent and terminal identities stay closed', function (SubtitleCaseStatus $subtitleCaseStatus): void {
    $candidate = subtitleCaseCandidate($this->bazarr, $this->sonarr);
    $subtitleCaseReconciler = resolve(SubtitleCaseReconciler::class);
    $case = $subtitleCaseReconciler->reconcile($candidate);
    $case->forceFill(['status' => $subtitleCaseStatus])->save();

    expect($subtitleCaseReconciler->reconcile($candidate)?->is($case))->toBeTrue()
        ->and(SubtitleCase::query()->count())->toBe(1)
        ->and($case->fresh()->status)->toBe($subtitleCaseStatus);
})->with([
    SubtitleCaseStatus::NeedsReview,
    SubtitleCaseStatus::Dismissed,
    SubtitleCaseStatus::Handled,
]);

test('a closed identity coming back still retires the active case for its target', function (): void {
    $candidate = subtitleCaseCandidate($this->bazarr, $this->sonarr);
    $subtitleCaseReconciler = resolve(SubtitleCaseReconciler::class);

    // Identity A is dismissed by an operator.
    $dismissed = $subtitleCaseReconciler->reconcile($candidate);
    $dismissed->forceFill(['status' => SubtitleCaseStatus::Dismissed])->save();

    // The file changes, so identity B becomes the active case for the same target.
    $replacementCandidate = [...$candidate, 'file_fingerprint' => hash('sha256', 'file-v2')];
    $active = $subtitleCaseReconciler->reconcile($replacementCandidate);

    expect($active?->status)->toBe(SubtitleCaseStatus::Observing);

    // The file reverts to A. A stays dismissed, but B no longer describes anything
    // installed, so it must not keep offering actions against a missing file.
    $result = $subtitleCaseReconciler->reconcile($candidate);

    expect($result?->is($dismissed))->toBeTrue()
        ->and($dismissed->fresh()->status)->toBe(SubtitleCaseStatus::Dismissed)
        ->and($active->fresh()->status)->toBe(SubtitleCaseStatus::Superseded)
        ->and($active->fresh()->superseded_at)->not->toBeNull();
});

test('a complete requirement resolves the stale active case of a closed identity', function (): void {
    $candidate = subtitleCaseCandidate($this->bazarr, $this->sonarr);
    $subtitleCaseReconciler = resolve(SubtitleCaseReconciler::class);
    $handled = $subtitleCaseReconciler->reconcile($candidate);
    $handled->forceFill(['status' => SubtitleCaseStatus::Handled])->save();
    $active = $subtitleCaseReconciler->reconcile([
        ...$candidate,
        'file_fingerprint' => hash('sha256', 'file-v2'),
    ]);

    $result = $subtitleCaseReconciler->reconcile([
        ...$candidate,
        'missing_languages' => [],
        'current_subtitles' => ['eng', 'jpn'],
    ]);

    expect($result?->is($handled))->toBeTrue()
        ->and($handled->fresh()->status)->toBe(SubtitleCaseStatus::Handled)
        ->and($active->fresh()->status)->toBe(SubtitleCaseStatus::Resolved);
});

test('reconciling one target neither reads nor locks another target rows', function (): void {
    $firstCandidate = subtitleCaseCandidate($this->bazarr, $this->sonarr);
    $secondCandidate = [
        ...$firstCandidate,
        'target_ids' => ['series_id' => 101, 'episode_id' => 702, 'episode_file_id' => 502],
        'file_fingerprint' => hash('sha256', 'second-episode-file'),
    ];
    $subtitleCaseReconciler = resolve(SubtitleCaseReconciler::class);
    $firstCase = $subtitleCaseReconciler->reconcile($firstCandidate);
    $statements = [];
    DB::listen(function (QueryExecuted $queryExecuted) use (&$statements): void {
        $statements[] = $queryExecuted->sql;
    });

    $secondCase = $subtitleCaseReconciler->reconcile($secondCandidate);

    // Both cases survive: the second reconciliation must not treat the first
    // episode's case as a stale target of its own.
    expect($firstCase->fresh()->status)->toBe(SubtitleCaseStatus::Observing)
        ->and($secondCase?->status)->toBe(SubtitleCaseStatus::Observing);

    // The target-wide lock is scoped in SQL rather than by filtering afterwards —
    // that is what stops two workers on different titles from locking each other's
    // rows. (The identity lookup locks its single row by fingerprint instead.)
    $targetLockStatements = array_values(array_filter(
        $statements,
        static fn (string $sql): bool => str_contains($sql, 'for update')
            && str_contains($sql, 'subtitle_cases')
            && str_contains($sql, 'media_type'),
    ));

    expect($targetLockStatements)->not->toBeEmpty();

    foreach ($targetLockStatements as $targetLockStatement) {
        expect($targetLockStatement)->toContain('target_ids');
    }
});

test('a superseded identity is observed again when it reappears missing', function (): void {
    $candidate = subtitleCaseCandidate($this->bazarr, $this->sonarr);
    $subtitleCaseReconciler = resolve(SubtitleCaseReconciler::class);
    $case = $subtitleCaseReconciler->reconcile($candidate);
    $case->forceFill(['status' => SubtitleCaseStatus::Superseded, 'superseded_at' => now()])->save();

    $reopened = $subtitleCaseReconciler->reconcile($candidate);

    expect($reopened?->is($case))->toBeFalse()
        ->and($reopened?->status)->toBe(SubtitleCaseStatus::Observing)
        ->and($subtitleCaseReconciler->reconcile($candidate)?->is($reopened))->toBeTrue()
        ->and(SubtitleCase::query()->count())->toBe(2);
});

test('a resolved identity that goes missing again is superseded and observed afresh', function (): void {
    $candidate = subtitleCaseCandidate($this->bazarr, $this->sonarr);
    $subtitleCaseReconciler = resolve(SubtitleCaseReconciler::class);
    $case = $subtitleCaseReconciler->reconcile($candidate);
    $subtitleCaseReconciler->reconcile([
        ...$candidate,
        'missing_languages' => [],
        'current_subtitles' => ['eng', 'jpn'],
    ]);

    expect($case->fresh()->status)->toBe(SubtitleCaseStatus::Resolved);

    // The subtitle was deleted again while the media file and language profile
    // stayed put. A resolved row cannot re-enter an active state, so leaving it in
    // place would keep this file out of subtitle automation forever.
    $reopened = $subtitleCaseReconciler->reconcile($candidate);

    expect($case->fresh()->status)->toBe(SubtitleCaseStatus::Superseded)
        ->and($case->fresh()->superseded_at)->not->toBeNull()
        ->and($reopened?->is($case))->toBeFalse()
        ->and($reopened?->status)->toBe(SubtitleCaseStatus::Observing)
        ->and(SubtitleCase::query()->count())->toBe(2);
});

test('a reopened identity starts a clean round without the earlier download requests', function (): void {
    $candidate = subtitleCaseCandidate($this->bazarr, $this->sonarr);
    $subtitleCaseReconciler = resolve(SubtitleCaseReconciler::class);
    $case = $subtitleCaseReconciler->reconcile($candidate);
    $case->forceFill([
        'evidence' => [...$case->evidence, 'download_requests' => ['eng|0|0' => 42]],
    ])->save();
    $subtitleCaseReconciler->reconcile([
        ...$candidate,
        'missing_languages' => [],
        'current_subtitles' => ['eng', 'jpn'],
    ]);

    $reopened = $subtitleCaseReconciler->reconcile($candidate);

    expect($reopened?->evidence)->not->toHaveKey('download_requests');
});

test('changed material identity supersedes the active case and creates a new case', function (string $field): void {
    $candidate = subtitleCaseCandidate($this->bazarr, $this->sonarr);
    $subtitleCaseReconciler = resolve(SubtitleCaseReconciler::class);
    $original = $subtitleCaseReconciler->reconcile($candidate);
    $changed = [
        ...$candidate,
        $field => hash('sha256', 'changed-'.$field),
    ];

    $replacement = $subtitleCaseReconciler->reconcile($changed);

    expect($original->fresh()->status)->toBe(SubtitleCaseStatus::Superseded)
        ->and($original->fresh()->superseded_at)->not->toBeNull()
        ->and($replacement?->is($original))->toBeFalse()
        ->and(SubtitleCase::query()->count())->toBe(2);
})->with(['file_fingerprint', 'requirements_fingerprint']);

test('complete requirements resolve an existing active case without creating a new one', function (): void {
    $candidate = subtitleCaseCandidate($this->bazarr, $this->sonarr);
    $subtitleCaseReconciler = resolve(SubtitleCaseReconciler::class);
    $case = $subtitleCaseReconciler->reconcile($candidate);

    $resolved = $subtitleCaseReconciler->reconcile([
        ...$candidate,
        'missing_languages' => [],
        'current_subtitles' => ['eng', 'jpn'],
    ]);

    expect($resolved?->is($case))->toBeTrue()
        ->and($case->fresh()->status)->toBe(SubtitleCaseStatus::Resolved)
        ->and($case->fresh()->resolved_at)->not->toBeNull()
        ->and(SubtitleCase::query()->count())->toBe(1);
});

test('complete requirements without an existing case create nothing', function (): void {
    $result = resolve(SubtitleCaseReconciler::class)->reconcile(
        subtitleCaseCandidate($this->bazarr, $this->sonarr, ['missing_languages' => []]),
    );

    expect($result)->toBeNull()
        ->and(SubtitleCase::query()->count())->toBe(0);
});

test('an elapsed grace period advances observing cases to Bazarr searching', function (): void {
    Date::setTestNow('2026-07-17 10:00:00');
    $candidate = subtitleCaseCandidate($this->bazarr, $this->sonarr);
    $subtitleCaseReconciler = resolve(SubtitleCaseReconciler::class);
    $case = $subtitleCaseReconciler->reconcile($candidate);

    Date::setTestNow('2026-07-18 10:01:00');
    $subtitleCaseReconciler->reconcile($candidate);

    expect($case->fresh()->status)->toBe(SubtitleCaseStatus::BazarrSearching);
});
