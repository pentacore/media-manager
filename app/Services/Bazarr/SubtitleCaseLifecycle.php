<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Enums\SubtitleCaseStatus;
use App\Events\SubtitleCaseChanged;
use App\Models\SubtitleCase;
use Illuminate\Support\Str;
use LogicException;

final class SubtitleCaseLifecycle
{
    /** @var array<string, list<string>> */
    private const array TRANSITIONS = [
        'observing' => ['bazarr_searching', 'resolved', 'dismissed', 'superseded'],
        'bazarr_searching' => ['download_requested', 'replacement_eligible', 'resolved', 'needs_review', 'superseded'],
        'download_requested' => ['bazarr_searching', 'resolved', 'replacement_eligible', 'needs_review', 'handled', 'superseded'],
        'replacement_eligible' => ['advisor_running', 'resolved', 'dismissed', 'superseded'],
        'advisor_running' => ['replacement_requested', 'needs_review', 'handled', 'superseded'],
        'replacement_requested' => ['resolved', 'needs_review', 'handled', 'superseded'],
        'needs_review' => ['replacement_eligible', 'resolved', 'dismissed', 'handled', 'superseded'],
        'resolved' => ['superseded'],
        'dismissed' => ['superseded'],
        'handled' => ['superseded'],
        'superseded' => [],
    ];

    private const array MUTABLE_ATTRIBUTES = [
        'download_action_request_id',
        'replacement_action_request_id',
        'evidence',
        'failure_reason',
        'grace_until',
        'observed_at',
        'resolved_at',
        'superseded_at',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function transition(
        SubtitleCase $subtitleCase,
        SubtitleCaseStatus $subtitleCaseStatus,
        array $attributes = [],
    ): bool {
        $from = $subtitleCase->status;

        throw_unless(
            in_array($subtitleCaseStatus->value, self::TRANSITIONS[$from->value], true),
            LogicException::class,
            sprintf('Subtitle case cannot transition from %s to %s.', $from->value, $subtitleCaseStatus->value),
        );

        foreach (array_keys($attributes) as $attribute) {
            throw_unless(
                in_array($attribute, self::MUTABLE_ATTRIBUTES, true),
                LogicException::class,
                sprintf('Subtitle case transition cannot update %s.', $attribute),
            );
        }

        $updates = $this->prepareUpdates($subtitleCase, ['status' => $subtitleCaseStatus, ...$attributes]);
        $updated = SubtitleCase::query()
            ->whereKey($subtitleCase->id)
            ->where('status', $from->value)
            ->update($updates) === 1;

        if (! $updated) {
            return false;
        }

        $subtitleCase->refresh();
        event(new SubtitleCaseChanged($subtitleCase, $from));

        return true;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function resolve(SubtitleCase $subtitleCase, array $attributes = []): bool
    {
        return $this->transition($subtitleCase, SubtitleCaseStatus::Resolved, [
            'resolved_at' => now(),
            'failure_reason' => null,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function needsReview(
        SubtitleCase $subtitleCase,
        ?string $reason = null,
        array $attributes = [],
    ): bool {
        return $this->transition($subtitleCase, SubtitleCaseStatus::NeedsReview, [
            'failure_reason' => $reason === null ? null : Str::substr($reason, 0, 500),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function supersede(SubtitleCase $subtitleCase, array $attributes = []): bool
    {
        return $this->transition($subtitleCase, SubtitleCaseStatus::Superseded, [
            'superseded_at' => now(),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function prepareUpdates(SubtitleCase $subtitleCase, array $attributes): array
    {
        $preparedAttributes = $subtitleCase->newInstance()
            ->forceFill($attributes)
            ->getAttributes();

        return array_intersect_key($preparedAttributes, $attributes);
    }
}
