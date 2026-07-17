<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use Illuminate\Database\Eloquent\Collection;
use App\Enums\SubtitleCaseStatus;
use App\Models\SubtitleCase;
use App\Settings\BazarrAutomationSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class SubtitleCaseReconciler
{
    private const array ACTIVE_STATUSES = [
        SubtitleCaseStatus::Observing,
        SubtitleCaseStatus::BazarrSearching,
        SubtitleCaseStatus::DownloadRequested,
        SubtitleCaseStatus::ReplacementEligible,
        SubtitleCaseStatus::AdvisorRunning,
        SubtitleCaseStatus::ReplacementRequested,
        SubtitleCaseStatus::NeedsReview,
    ];

    public function __construct(
        private BazarrAutomationSettings $bazarrAutomationSettings,
        private SubtitleCaseLifecycle $subtitleCaseLifecycle,
    ) {}

    /**
     * @param  array<string, mixed>  $candidate
     */
    public function reconcile(array $candidate): ?SubtitleCase
    {
        $candidate = $this->validatedCandidate($candidate);
        $lockKey = sprintf(
            'bazarr-subtitle-case:%d:%d:%s',
            $candidate['bazarr_connection_id'],
            $candidate['service_connection_id'],
            hash('sha256', json_encode($candidate['target_ids'], JSON_THROW_ON_ERROR)),
        );

        return Cache::lock($lockKey, 15)->block(
            5,
            fn (): ?SubtitleCase => DB::transaction(
                fn (): ?SubtitleCase => $this->reconcileLocked($candidate),
                attempts: 3,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function reconcileLocked(array $candidate): ?SubtitleCase
    {
        $existing = SubtitleCase::query()
            ->where('bazarr_connection_id', $candidate['bazarr_connection_id'])
            ->where('service_connection_id', $candidate['service_connection_id'])
            ->where('file_fingerprint', $candidate['file_fingerprint'])
            ->where('requirements_fingerprint', $candidate['requirements_fingerprint'])
            ->lockForUpdate()
            ->first();
        $isComplete = $candidate['missing_languages'] === [] || ! $candidate['monitored'];

        if ($existing instanceof SubtitleCase) {
            if ($isComplete && in_array($existing->status, self::ACTIVE_STATUSES, true)) {
                $this->subtitleCaseLifecycle->resolve($existing, [
                    'evidence' => $this->evidence($candidate),
                    'observed_at' => now(),
                ]);

                return $existing;
            }

            if (! $isComplete) {
                $existing->forceFill([
                    'evidence' => $this->evidence($candidate),
                    'observed_at' => now(),
                ])->save();
                $this->advanceElapsedGrace($existing);
            }

            return $existing;
        }

        $activeTargetCases = $this->activeTargetCases($candidate);

        if ($isComplete) {
            foreach ($activeTargetCases as $activeTargetCase) {
                $this->subtitleCaseLifecycle->resolve($activeTargetCase, [
                    'evidence' => $this->evidence($candidate),
                    'observed_at' => now(),
                ]);
            }

            return $activeTargetCases->first();
        }

        foreach ($activeTargetCases as $activeTargetCase) {
            $this->subtitleCaseLifecycle->supersede($activeTargetCase);
        }

        $subtitleCase = SubtitleCase::query()->firstOrCreate(
            [
                'bazarr_connection_id' => $candidate['bazarr_connection_id'],
                'service_connection_id' => $candidate['service_connection_id'],
                'file_fingerprint' => $candidate['file_fingerprint'],
                'requirements_fingerprint' => $candidate['requirements_fingerprint'],
            ],
            [
                'media_type' => $candidate['media_type'],
                'scope' => $candidate['scope'],
                'target_ids' => $candidate['target_ids'],
                'required_languages' => $this->requirements($candidate['required_languages']),
                'status' => SubtitleCaseStatus::Observing,
                'evidence' => $this->evidence($candidate),
                'grace_until' => now()->addHours($this->bazarrAutomationSettings->graceHours($candidate['scope'])),
                'observed_at' => now(),
            ],
        );

        $this->advanceElapsedGrace($subtitleCase);

        return $subtitleCase;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return Collection<int, SubtitleCase>
     */
    private function activeTargetCases(array $candidate): Collection
    {
        return SubtitleCase::query()
            ->where('bazarr_connection_id', $candidate['bazarr_connection_id'])
            ->where('service_connection_id', $candidate['service_connection_id'])
            ->where('media_type', $candidate['media_type'])
            ->whereIn('status', array_map(
                static fn (SubtitleCaseStatus $subtitleCaseStatus): string => $subtitleCaseStatus->value,
                self::ACTIVE_STATUSES,
            ))
            ->lockForUpdate()
            ->get()
            ->filter(fn (SubtitleCase $subtitleCase): bool => $this->sameTarget(
                $subtitleCase->target_ids,
                $candidate['target_ids'],
                $candidate['media_type'],
            ))
            ->values();
    }

    private function advanceElapsedGrace(SubtitleCase $subtitleCase): void
    {
        if ($subtitleCase->status !== SubtitleCaseStatus::Observing
            || $subtitleCase->grace_until === null
            || $subtitleCase->grace_until->isFuture()) {
            return;
        }

        $this->subtitleCaseLifecycle->transition($subtitleCase, SubtitleCaseStatus::BazarrSearching);
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function sameTarget(array $left, array $right, string $mediaType): bool
    {
        return $mediaType === 'episode'
            ? ($left['series_id'] ?? null) === ($right['series_id'] ?? null)
                && ($left['episode_id'] ?? null) === ($right['episode_id'] ?? null)
            : ($left['radarr_id'] ?? null) === ($right['radarr_id'] ?? null);
    }

    /**
     * @param  list<string>  $languages
     * @return list<array{code: string, forced: bool, hearing_impaired: bool}>
     */
    private function requirements(array $languages): array
    {
        return array_map(
            static fn (string $language): array => [
                'code' => $language,
                'forced' => false,
                'hearing_impaired' => false,
            ],
            $languages,
        );
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array{display_name: string, missing_languages: list<string>, current_subtitles: list<string>, monitored: bool}
     */
    private function evidence(array $candidate): array
    {
        return [
            'display_name' => $candidate['display_name'],
            'missing_languages' => $candidate['missing_languages'],
            'current_subtitles' => $candidate['current_subtitles'],
            'monitored' => $candidate['monitored'],
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function validatedCandidate(array $candidate): array
    {
        foreach (['bazarr_connection_id', 'service_connection_id'] as $key) {
            throw_unless(is_int($candidate[$key] ?? null) && $candidate[$key] > 0, InvalidArgumentException::class, 'Subtitle case candidate IDs are invalid.');
        }

        throw_unless(in_array($candidate['media_type'] ?? null, ['episode', 'movie'], true), InvalidArgumentException::class, 'Subtitle case candidate media type is invalid.');
        throw_unless(in_array($candidate['scope'] ?? null, ['anime', 'tv', 'movie'], true), InvalidArgumentException::class, 'Subtitle case candidate scope is invalid.');
        throw_unless(is_array($candidate['target_ids'] ?? null), InvalidArgumentException::class, 'Subtitle case candidate target IDs are invalid.');

        foreach (['file_fingerprint', 'requirements_fingerprint'] as $key) {
            throw_unless(is_string($candidate[$key] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $candidate[$key]) === 1, InvalidArgumentException::class, 'Subtitle case candidate fingerprints are invalid.');
        }

        foreach (['required_languages', 'missing_languages', 'current_subtitles'] as $key) {
            throw_unless(is_array($candidate[$key] ?? null), InvalidArgumentException::class, 'Subtitle case candidate languages are invalid.');
            $candidate[$key] = array_values(array_filter($candidate[$key], is_string(...)));
        }

        $candidate['display_name'] = is_string($candidate['display_name'] ?? null)
            ? mb_substr($candidate['display_name'], 0, 300)
            : 'Subtitle case';
        $candidate['monitored'] = ($candidate['monitored'] ?? false) === true;

        return $candidate;
    }
}
