<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ActionRequest;
use App\Enums\ServiceType;
use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Enums\SubtitleCaseStatus;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\SubtitleCaseAttempt;
use App\Services\Bazarr\BazarrClient;
use App\Services\Bazarr\BazarrDownloadRequestCreator;
use App\Services\Bazarr\SubtitleCandidateEligibility;
use App\Services\Bazarr\SubtitleCaseLifecycle;
use App\Services\Bazarr\SubtitleCaseReconciler;
use App\Settings\BazarrAutomationSettings;
use App\Settings\MediaReplacementSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Timeout(60)]
#[Tries(3)]
final class ReconcileSubtitleCase implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $candidate
     */
    public function __construct(
        public array $candidate,
        public bool $probeAllowed = true,
        public ?int $subtitleCaseId = null,
        public ?int $targetBazarrConnectionId = null,
    ) {}

    public static function forCase(SubtitleCase $subtitleCase): self
    {
        return new self(
            candidate: [],
            subtitleCaseId: $subtitleCase->id,
            targetBazarrConnectionId: $subtitleCase->bazarr_connection_id,
        );
    }

    public function bazarrConnectionId(): string
    {
        return (string) ($this->targetBazarrConnectionId ?? $this->candidate['bazarr_connection_id'] ?? 'unknown');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('bazarr-probes')->releaseAfter(60)];
    }

    public function handle(
        SubtitleCaseReconciler $subtitleCaseReconciler,
        SubtitleCandidateEligibility $subtitleCandidateEligibility,
        BazarrDownloadRequestCreator $bazarrDownloadRequestCreator,
        SubtitleCaseLifecycle $subtitleCaseLifecycle,
        BazarrAutomationSettings $bazarrAutomationSettings,
        MediaReplacementSettings $mediaReplacementSettings,
    ): void {
        $subtitleCase = $this->subtitleCaseId === null
            ? $subtitleCaseReconciler->reconcile($this->candidate)
            : SubtitleCase::query()->find($this->subtitleCaseId);

        if (! $this->probeAllowed
            || ! $subtitleCase instanceof SubtitleCase
            || $subtitleCase->status !== SubtitleCaseStatus::BazarrSearching) {
            return;
        }

        Cache::lock('bazarr-subtitle-probe:'.$subtitleCase->id, 60)->block(
            5,
            fn () => $this->probe(
                $subtitleCase,
                $subtitleCandidateEligibility,
                $bazarrDownloadRequestCreator,
                $subtitleCaseLifecycle,
                $bazarrAutomationSettings,
                $mediaReplacementSettings,
            ),
        );
    }

    private function probe(
        SubtitleCase $subtitleCase,
        SubtitleCandidateEligibility $subtitleCandidateEligibility,
        BazarrDownloadRequestCreator $bazarrDownloadRequestCreator,
        SubtitleCaseLifecycle $subtitleCaseLifecycle,
        BazarrAutomationSettings $bazarrAutomationSettings,
        MediaReplacementSettings $mediaReplacementSettings,
    ): void {
        $subtitleCase->refresh();

        if ($subtitleCase->status !== SubtitleCaseStatus::BazarrSearching
            || SubtitleCaseAttempt::query()
                ->where('subtitle_case_id', $subtitleCase->id)
                ->where('type', SubtitleCaseAttemptType::Probe)
                ->where('started_at', '>', now()->subHours($bazarrAutomationSettings->probeSpacingHours()))
                ->exists()) {
            return;
        }

        $startedAt = now();

        try {
            $bazarrConnection = ServiceConnection::query()->find($subtitleCase->bazarr_connection_id);

            if (! $bazarrConnection instanceof ServiceConnection
                || $bazarrConnection->type !== ServiceType::Bazarr
                || ! $bazarrConnection->is_active) {
                return;
            }

            $bazarrClient = new BazarrClient($bazarrConnection);
            $mediaId = $subtitleCase->media_type === 'episode'
                ? (int) ($subtitleCase->target_ids['episode_id'] ?? 0)
                : (int) ($subtitleCase->target_ids['radarr_id'] ?? 0);
            $candidates = $subtitleCase->media_type === 'episode'
                ? $bazarrClient->searchEpisode($mediaId)
                : $bazarrClient->searchMovie($mediaId);
            $providers = $bazarrClient->getProviders()['data'];
            $availableProviders = array_values(array_filter(array_map(
                static fn (array $provider): ?string => is_string($provider['name'] ?? null)
                    && in_array($provider['status'] ?? null, ['healthy', 'available', 'enabled'], true)
                    && ! is_string($provider['throttled_until'] ?? null)
                        ? $provider['name']
                        : null,
                $providers,
            )));
            $context = [
                'minimum_score' => $mediaReplacementSettings->automaticSelectionThreshold(),
                'available_providers' => $availableProviders,
                'threshold_available' => $candidates === [] || collect($candidates)->every(
                    static fn (array $candidate): bool => is_numeric($candidate['score'] ?? null),
                ),
            ];
            $requirements = $this->missingRequirements($subtitleCase);
            $counts = $this->emptyClassificationCounts();
            $eligibleRequirement = null;

            foreach ($candidates as $candidate) {
                foreach ($requirements as $requirement) {
                    $classification = $subtitleCandidateEligibility->classify($candidate, $requirement, $context);
                    $counts[$classification]++;

                    if ($classification === 'eligible' && $eligibleRequirement === null) {
                        $eligibleRequirement = $requirement;
                    }
                }
            }

            $actionRequest = $eligibleRequirement === null
                ? null
                : $bazarrDownloadRequestCreator->create($subtitleCase, $eligibleRequirement);
            $outcome = $actionRequest instanceof ActionRequest
                ? (SubtitleCaseAttemptOutcome::Succeeded)
                : ($counts['capability_limited'] > 0 ? SubtitleCaseAttemptOutcome::Indeterminate : SubtitleCaseAttemptOutcome::Empty);
            SubtitleCaseAttempt::query()->create([
                'subtitle_case_id' => $subtitleCase->id,
                'action_request_id' => $actionRequest?->id,
                'type' => SubtitleCaseAttemptType::Probe,
                'candidate_count' => count($candidates),
                'eligible_candidate_count' => $counts['eligible'],
                'summary' => $counts,
                'outcome' => $outcome,
                'error_category' => $outcome === SubtitleCaseAttemptOutcome::Indeterminate ? 'capability_limited' : null,
                'started_at' => $startedAt,
                'completed_at' => now(),
            ]);

            if ($outcome === SubtitleCaseAttemptOutcome::Empty
                && SubtitleCaseAttempt::query()
                    ->where('subtitle_case_id', $subtitleCase->id)
                    ->where('type', SubtitleCaseAttemptType::Probe)
                    ->where('outcome', SubtitleCaseAttemptOutcome::Empty)
                    ->count() >= $bazarrAutomationSettings->emptyProbeThreshold()) {
                $subtitleCaseLifecycle->transition(
                    $subtitleCase->fresh(),
                    SubtitleCaseStatus::ReplacementEligible,
                );
            }
        } catch (Throwable $throwable) {
            SubtitleCaseAttempt::query()->create([
                'subtitle_case_id' => $subtitleCase->id,
                'type' => SubtitleCaseAttemptType::Probe,
                'candidate_count' => 0,
                'eligible_candidate_count' => 0,
                'summary' => ['eligible' => 0],
                'outcome' => SubtitleCaseAttemptOutcome::Failed,
                'error_category' => 'upstream_failure',
                'started_at' => $startedAt,
                'completed_at' => now(),
            ]);

            $subtitleCaseLifecycle->needsReview($subtitleCase->fresh(), 'Bazarr probe failed.');
            report($throwable);
        }
    }

    /**
     * @return list<array{code: string, forced: bool, hearing_impaired: bool}>
     */
    private function missingRequirements(SubtitleCase $subtitleCase): array
    {
        $missingLanguages = is_array($subtitleCase->evidence['missing_languages'] ?? null)
            ? $subtitleCase->evidence['missing_languages']
            : [];

        return array_values(array_filter(
            $subtitleCase->required_languages,
            static fn (mixed $requirement): bool => is_array($requirement)
                && in_array($requirement['code'] ?? null, $missingLanguages, true),
        ));
    }

    /**
     * @return array<string, int>
     */
    private function emptyClassificationCounts(): array
    {
        return [
            'eligible' => 0,
            'wrong_language' => 0,
            'wrong_qualifier' => 0,
            'provider_unavailable' => 0,
            'below_threshold' => 0,
            'malformed' => 0,
            'capability_limited' => 0,
        ];
    }

    public function failed(?Throwable $throwable): void
    {
        Log::error('Subtitle case reconciliation failed.', [
            'bazarr_connection_id' => $this->candidate['bazarr_connection_id'] ?? null,
            'service_connection_id' => $this->candidate['service_connection_id'] ?? null,
            'exception' => $throwable instanceof Throwable ? $throwable::class : null,
        ]);
    }
}
