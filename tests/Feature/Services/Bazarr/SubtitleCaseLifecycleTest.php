<?php

declare(strict_types=1);

use App\Enums\SubtitleCaseStatus;
use App\Events\SubtitleCaseChanged;
use App\Models\SubtitleCase;
use App\Services\Bazarr\SubtitleCaseLifecycle;
use Illuminate\Support\Facades\Event;

/**
 * @return array<string, list<string>>
 */
function subtitleCaseTransitions(): array
{
    return [
        'observing' => ['bazarr_searching', 'resolved', 'dismissed', 'superseded'],
        'bazarr_searching' => ['download_requested', 'replacement_eligible', 'resolved', 'needs_review', 'superseded'],
        'download_requested' => ['resolved', 'replacement_eligible', 'needs_review', 'handled', 'superseded'],
        'replacement_eligible' => ['advisor_running', 'resolved', 'dismissed', 'superseded'],
        'advisor_running' => ['replacement_requested', 'needs_review', 'handled', 'superseded'],
        'replacement_requested' => ['resolved', 'needs_review', 'handled', 'superseded'],
        'needs_review' => ['replacement_eligible', 'resolved', 'dismissed', 'handled', 'superseded'],
        'resolved' => ['superseded'],
        'dismissed' => ['superseded'],
        'handled' => ['superseded'],
        'superseded' => [],
    ];
}

test('only transitions in the lifecycle table are allowed', function (): void {
    $subtitleCaseLifecycle = resolve(SubtitleCaseLifecycle::class);

    foreach (SubtitleCaseStatus::cases() as $from) {
        foreach (SubtitleCaseStatus::cases() as $to) {
            $subtitleCase = SubtitleCase::factory()->create([
                'status' => $from,
                'file_fingerprint' => hash('sha256', $from->value.'-'.$to->value),
            ]);

            if (in_array($to->value, subtitleCaseTransitions()[$from->value], true)) {
                expect($subtitleCaseLifecycle->transition($subtitleCase, $to))
                    ->toBeTrue()
                    ->and($subtitleCase->status)->toBe($to);
            } else {
                expect(fn (): bool => $subtitleCaseLifecycle->transition($subtitleCase, $to))
                    ->toThrow(LogicException::class);
            }
        }
    }
});

test('a stale concurrent transition updates and emits only once', function (): void {
    $subtitleCase = SubtitleCase::factory()->create([
        'status' => SubtitleCaseStatus::BazarrSearching,
    ]);
    $firstWorkerCase = $subtitleCase->fresh();
    $secondWorkerCase = $subtitleCase->fresh();
    Event::fake([SubtitleCaseChanged::class]);

    $subtitleCaseLifecycle = resolve(SubtitleCaseLifecycle::class);

    expect($subtitleCaseLifecycle->needsReview($firstWorkerCase, 'Upstream outcome is unknown.'))->toBeTrue()
        ->and($subtitleCaseLifecycle->needsReview($secondWorkerCase, 'Duplicate worker.'))->toBeFalse()
        ->and($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and($subtitleCase->fresh()->failure_reason)->toBe('Upstream outcome is unknown.');

    Event::assertDispatchedOnce(SubtitleCaseChanged::class);
    Event::assertDispatched(
        fn (SubtitleCaseChanged $subtitleCaseChanged): bool => $subtitleCaseChanged->subtitleCase->is($subtitleCase)
            && $subtitleCaseChanged->from === SubtitleCaseStatus::BazarrSearching
            && $subtitleCaseChanged->subtitleCase->status === SubtitleCaseStatus::NeedsReview,
    );
});

test('lifecycle helpers maintain terminal timestamps', function (): void {
    $subtitleCaseLifecycle = resolve(SubtitleCaseLifecycle::class);
    $resolvedCase = SubtitleCase::factory()->create([
        'status' => SubtitleCaseStatus::DownloadRequested,
        'failure_reason' => 'Previous transient failure.',
    ]);
    $supersededCase = SubtitleCase::factory()->create([
        'status' => SubtitleCaseStatus::Handled,
    ]);

    expect($subtitleCaseLifecycle->resolve($resolvedCase))->toBeTrue()
        ->and($resolvedCase->status)->toBe(SubtitleCaseStatus::Resolved)
        ->and($resolvedCase->resolved_at)->not->toBeNull()
        ->and($resolvedCase->failure_reason)->toBeNull()
        ->and($subtitleCaseLifecycle->supersede($supersededCase))->toBeTrue()
        ->and($supersededCase->status)->toBe(SubtitleCaseStatus::Superseded)
        ->and($supersededCase->superseded_at)->not->toBeNull();
});

test('transition attributes cannot mutate material identity', function (): void {
    $subtitleCase = SubtitleCase::factory()->create([
        'status' => SubtitleCaseStatus::Observing,
    ]);

    expect(fn (): bool => resolve(SubtitleCaseLifecycle::class)->transition(
        $subtitleCase,
        SubtitleCaseStatus::BazarrSearching,
        ['file_fingerprint' => hash('sha256', 'replacement')],
    ))->toThrow(LogicException::class, 'cannot update file_fingerprint');
});
