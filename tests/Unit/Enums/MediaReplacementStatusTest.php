<?php

declare(strict_types=1);

use App\Enums\MediaReplacementStatus;

test('isTerminal classifies every status', function (MediaReplacementStatus $mediaReplacementStatus, bool $isTerminal): void {
    expect($mediaReplacementStatus->isTerminal())->toBe($isTerminal);
})->with([
    'requested is still in flight' => [MediaReplacementStatus::Requested, false],
    'downloading is still in flight' => [MediaReplacementStatus::Downloading, false],
    // Imported is deliberately non-terminal: subtitle verification has not run
    // yet, so the attempt is still ours to act on.
    'imported still awaits verification' => [MediaReplacementStatus::Imported, false],
    'verified has settled' => [MediaReplacementStatus::Verified, true],
    'failed has settled' => [MediaReplacementStatus::Failed, true],
    'needs_attention has settled' => [MediaReplacementStatus::NeedsAttention, true],
]);

/**
 * The dataset above must enumerate the enum, not a snapshot of it: a case added
 * without a decision here would otherwise stay untested, and its exhaustive
 * match arm would only blow up at runtime.
 */
test('every status is covered by the isTerminal dataset', function (): void {
    $classified = [
        MediaReplacementStatus::Requested,
        MediaReplacementStatus::Downloading,
        MediaReplacementStatus::Imported,
        MediaReplacementStatus::Verified,
        MediaReplacementStatus::Failed,
        MediaReplacementStatus::NeedsAttention,
    ];

    expect(MediaReplacementStatus::cases())->toBe($classified);
});

/**
 * The query-builder helper must stay derived from the predicate, so that the
 * conditional `whereNotIn('status', ...)` transitions and the in-memory checks
 * can never disagree about what has settled.
 */
test('terminalValues lists exactly the values of the terminal cases', function (): void {
    expect(MediaReplacementStatus::terminalValues())->toBe(['verified', 'failed', 'needs_attention'])
        ->and(MediaReplacementStatus::terminalValues())->toBe(array_values(array_map(
            static fn (MediaReplacementStatus $mediaReplacementStatus): string => $mediaReplacementStatus->value,
            array_filter(
                MediaReplacementStatus::cases(),
                static fn (MediaReplacementStatus $mediaReplacementStatus): bool => $mediaReplacementStatus->isTerminal(),
            ),
        )));
});

test('every status has a human label', function (): void {
    expect(MediaReplacementStatus::NeedsAttention->label())->toBe('Needs attention')
        ->and(MediaReplacementStatus::Downloading->label())->toBe('Downloading')
        ->and(array_map(static fn (MediaReplacementStatus $status): string => $status->label(), MediaReplacementStatus::cases()))
        ->each->not->toBe('');
});
