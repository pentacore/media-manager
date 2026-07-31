<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ActionRequestStatus;
use App\Enums\ServiceType;
use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Enums\SubtitleCaseStatus;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\SubtitleCaseAttempt;
use App\Providers\AIServiceProvider;
use App\Services\Bazarr\BazarrClient;
use App\Services\Bazarr\BazarrDownloadRequestCreator;
use App\Services\Bazarr\BazarrSettingsAdapter;
use App\Services\Bazarr\SubtitleCandidateEligibility;
use App\Services\Bazarr\SubtitleCaseLifecycle;
use App\Services\Bazarr\SubtitleCaseReconciler;
use App\Services\Bazarr\SubtitleInventoryService;
use App\Settings\BazarrAutomationSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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

    /**
     * The case this attempt was probing, so failed() can park it once the tries
     * are exhausted. Set during the attempt rather than constructed, because a
     * candidate-based job only resolves its case while running.
     */
    private ?int $probedSubtitleCaseId = null;

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
        // Only a probing run touches provider endpoints, so the probe rate limiter
        // must not throttle reconcile-only (probeAllowed = false) work.
        if (! $this->probeAllowed) {
            return [];
        }

        return [new RateLimited('bazarr-probes')->releaseAfter(60)];
    }

    public function handle(
        SubtitleCaseReconciler $subtitleCaseReconciler,
        SubtitleCandidateEligibility $subtitleCandidateEligibility,
        BazarrDownloadRequestCreator $bazarrDownloadRequestCreator,
        SubtitleCaseLifecycle $subtitleCaseLifecycle,
        BazarrAutomationSettings $bazarrAutomationSettings,
        BazarrSettingsAdapter $bazarrSettingsAdapter,
        SubtitleInventoryService $subtitleInventoryService,
    ): void {
        $subtitleCase = $this->subtitleCaseId === null
            ? $subtitleCaseReconciler->reconcile($this->candidate)
            : SubtitleCase::query()->find($this->subtitleCaseId);

        // Targeted reconciliation must verify the real outcome of a case that is
        // waiting on Bazarr: refresh the actual installed file so a satisfied
        // requirement resolves, and a completed-but-ineffective download re-enters
        // probing to reach the replacement path.
        if ($this->subtitleCaseId !== null
            && $subtitleCase instanceof SubtitleCase
            && in_array($subtitleCase->status, [
                SubtitleCaseStatus::DownloadRequested,
                SubtitleCaseStatus::BazarrSearching,
            ], true)) {
            $subtitleCase = $this->verifyTargetedOutcome(
                $subtitleCase,
                $subtitleCaseReconciler,
                $subtitleCaseLifecycle,
                $bazarrAutomationSettings,
                $subtitleInventoryService,
            );
        }

        if ($subtitleCase instanceof SubtitleCase
            && $subtitleCase->status === SubtitleCaseStatus::ReplacementEligible) {
            $this->dispatchAdvisor($subtitleCase, $bazarrAutomationSettings);

            return;
        }

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
                $bazarrSettingsAdapter,
            ),
        );
    }

    private function probe(
        SubtitleCase $subtitleCase,
        SubtitleCandidateEligibility $subtitleCandidateEligibility,
        BazarrDownloadRequestCreator $bazarrDownloadRequestCreator,
        SubtitleCaseLifecycle $subtitleCaseLifecycle,
        BazarrAutomationSettings $bazarrAutomationSettings,
        BazarrSettingsAdapter $bazarrSettingsAdapter,
    ): void {
        $subtitleCase->refresh();

        if ($subtitleCase->status !== SubtitleCaseStatus::BazarrSearching
            || $this->recentProbeExists($subtitleCase, $bazarrAutomationSettings)) {
            return;
        }

        // Consume a shared per-connection cycle probe slot immediately before any
        // provider search, regardless of origin (scheduled sweep or webhook forCase),
        // so notification-triggered probes cannot exceed the per-cycle budget. With
        // no slot left, deterministic reconciliation (already done) stands and the
        // provider search is skipped until the next cycle.
        if (! $this->reserveProbeSlot($subtitleCase->bazarr_connection_id, $bazarrAutomationSettings)) {
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
            $minimumScore = $candidates === []
                ? null
                : $bazarrSettingsAdapter->effectiveMinimumScore($bazarrConnection, $subtitleCase->media_type);
            $context = [
                'minimum_score' => $minimumScore,
                'available_providers' => $availableProviders,
                'threshold_available' => $minimumScore !== null,
            ];
            $requirements = $this->missingRequirements($subtitleCase);
            $counts = $this->emptyClassificationCounts();
            $eligibleRequirements = [];

            foreach ($candidates as $candidate) {
                foreach ($requirements as $requirement) {
                    $classification = $subtitleCandidateEligibility->classify($candidate, $requirement, $context);
                    $counts[$classification]++;

                    if ($classification === 'eligible') {
                        $eligibleRequirements[$this->requirementKey($requirement)] ??= $requirement;
                    }
                }
            }

            // Every eligible missing language gets its own download request; the
            // creator enforces per-case/language uniqueness under its own lock.
            $actionRequest = null;

            foreach ($eligibleRequirements as $eligibleRequirement) {
                $created = $bazarrDownloadRequestCreator->create($subtitleCase, $eligibleRequirement);
                $actionRequest ??= $created;
            }

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
                $transitioned = $subtitleCaseLifecycle->transition(
                    $subtitleCase->fresh(),
                    SubtitleCaseStatus::ReplacementEligible,
                );

                if ($transitioned) {
                    $this->dispatchAdvisor($subtitleCase->fresh(), $bazarrAutomationSettings);
                }
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

            // A brief Bazarr or provider outage is the most likely probe failure,
            // and swallowing it here spent none of the configured tries while
            // permanently removing an otherwise automatable case from probing.
            // Retryable failures are rethrown so the backoff applies; failed()
            // parks the case once the last attempt is exhausted.
            if ($this->isRetryable($throwable)) {
                $this->probedSubtitleCaseId = $subtitleCase->id;

                throw $throwable;
            }

            $subtitleCaseLifecycle->needsReview($subtitleCase->fresh(), 'Bazarr probe failed.');
            report($throwable);
        }
    }

    /**
     * Upstream connectivity, server errors and throttling are expected to clear
     * on their own; anything else (bad data, a definite 4xx) will fail the same
     * way on every attempt and is parked immediately instead.
     */
    private function isRetryable(Throwable $throwable): bool
    {
        if ($throwable instanceof ConnectionException) {
            return true;
        }

        return $throwable instanceof RequestException
            && ($throwable->response->serverError() || $throwable->response->status() === 429);
    }

    private function verifyTargetedOutcome(
        SubtitleCase $subtitleCase,
        SubtitleCaseReconciler $subtitleCaseReconciler,
        SubtitleCaseLifecycle $subtitleCaseLifecycle,
        BazarrAutomationSettings $bazarrAutomationSettings,
        SubtitleInventoryService $subtitleInventoryService,
    ): SubtitleCase {
        try {
            $candidate = $subtitleInventoryService->caseCandidateFor($subtitleCase);
        } catch (Throwable $throwable) {
            report($throwable);
            $candidate = null;
        }

        if ($candidate !== null) {
            $reconciled = $subtitleCaseReconciler->reconcile($candidate);
            $subtitleCase = $reconciled instanceof SubtitleCase
                ? $reconciled
                : ($subtitleCase->fresh() ?? $subtitleCase);
        } else {
            $subtitleCase = $subtitleCase->fresh() ?? $subtitleCase;
        }

        // A settled download that left the requirement unmet re-enters probing so
        // the normal empty-probe path can escalate the case to replacement. Probe
        // spacing is not consulted here: the download was requested moments after a
        // probe, so with a 24-hour window this verification could never advance the
        // case. probe() enforces the spacing itself, so the case simply waits in
        // bazarr_searching until the window elapses.
        if ($subtitleCase->status === SubtitleCaseStatus::DownloadRequested
            && $this->downloadSettled($subtitleCase)
            && $subtitleCaseLifecycle->transition($subtitleCase, SubtitleCaseStatus::BazarrSearching)) {
            return $subtitleCase->fresh() ?? $subtitleCase;
        }

        return $subtitleCase;
    }

    /**
     * The linked download is no longer expected to act: it either completed, or it
     * failed with an uncertain outcome that this live read has now verified. Both
     * mean the case must stop waiting on it.
     */
    private function downloadSettled(SubtitleCase $subtitleCase): bool
    {
        if ($subtitleCase->download_action_request_id === null) {
            return false;
        }

        $actionRequest = ActionRequest::query()->find($subtitleCase->download_action_request_id);

        if (! $actionRequest instanceof ActionRequest) {
            return false;
        }

        return $actionRequest->status === ActionRequestStatus::Completed
            || ($actionRequest->status === ActionRequestStatus::Failed
                && ($actionRequest->result['indeterminate'] ?? false) === true);
    }

    /**
     * Probe spacing counts attempts that actually reached the providers. A failed
     * attempt is recorded before the exception is rethrown, so counting it would
     * make the queued retry return without contacting Bazarr: the remaining tries
     * would go unspent, failed() would never park the case, and the case would
     * wait out the whole spacing window instead.
     */
    private function recentProbeExists(
        SubtitleCase $subtitleCase,
        BazarrAutomationSettings $bazarrAutomationSettings,
    ): bool {
        return SubtitleCaseAttempt::query()
            ->where('subtitle_case_id', $subtitleCase->id)
            ->where('type', SubtitleCaseAttemptType::Probe)
            ->whereNot('outcome', SubtitleCaseAttemptOutcome::Failed)
            ->where('started_at', '>', now()->subHours($bazarrAutomationSettings->probeSpacingHours()))
            ->exists();
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
     * @param  array{code: string, forced: bool, hearing_impaired: bool}  $requirement
     */
    private function requirementKey(array $requirement): string
    {
        return sprintf('%s|%d|%d', $requirement['code'], (int) $requirement['forced'], (int) $requirement['hearing_impaired']);
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

    private function dispatchAdvisor(
        SubtitleCase $subtitleCase,
        BazarrAutomationSettings $bazarrAutomationSettings,
    ): void {
        $maximum = $bazarrAutomationSettings->maxAdvisorEscalationsPerCycle();

        if (! AIServiceProvider::enabled()
            || ! $bazarrAutomationSettings->enabled()
            || $maximum < 1) {
            return;
        }

        Cache::lock('bazarr-advisor-cycle-lock:'.$subtitleCase->bazarr_connection_id, 10)->block(
            5,
            function () use ($subtitleCase, $bazarrAutomationSettings, $maximum): void {
                $ttl = now()->addMinutes($bazarrAutomationSettings->reconciliationIntervalMinutes());
                $slotKey = sprintf('bazarr-advisor-cycle-slot:%d:%d', $subtitleCase->bazarr_connection_id, $subtitleCase->id);

                // One escalation per case per cycle. A case already escalated this
                // cycle must not consume another slot, so repeated reconciliation of
                // the same eligible case cannot starve other cases of the cap.
                if (Cache::get($slotKey) !== null) {
                    return;
                }

                $key = 'bazarr-advisor-cycle-count:'.$subtitleCase->bazarr_connection_id;
                $used = (int) Cache::get($key, 0);

                if ($used >= $maximum) {
                    return;
                }

                Cache::put($slotKey, true, $ttl);
                Cache::put($key, $used + 1, $ttl);
                dispatch(new RunSubtitleAdvisor($subtitleCase->id));
            },
        );
    }

    private function reserveProbeSlot(int $connectionId, BazarrAutomationSettings $bazarrAutomationSettings): bool
    {
        return (bool) Cache::lock('bazarr-probe-cycle-lock:'.$connectionId, 10)->block(
            5,
            function () use ($connectionId, $bazarrAutomationSettings): bool {
                $key = 'bazarr-probe-cycle-count:'.$connectionId;
                $used = (int) Cache::get($key, 0);

                if ($used >= $bazarrAutomationSettings->maxProbesPerCycle()) {
                    return false;
                }

                Cache::put(
                    $key,
                    $used + 1,
                    now()->addMinutes($bazarrAutomationSettings->reconciliationIntervalMinutes()),
                );

                return true;
            },
        );
    }

    public function failed(?Throwable $throwable): void
    {
        Log::error('Subtitle case reconciliation failed.', [
            'bazarr_connection_id' => $this->candidate['bazarr_connection_id'] ?? null,
            'service_connection_id' => $this->candidate['service_connection_id'] ?? null,
            'exception' => $throwable instanceof Throwable ? $throwable::class : null,
        ]);

        // The retries are spent, so the case that was probing when the last
        // attempt failed is parked for an operator instead of silently dropping
        // out of automation.
        $subtitleCaseId = $this->probedSubtitleCaseId ?? $this->subtitleCaseId;

        if ($subtitleCaseId === null) {
            return;
        }

        $subtitleCase = SubtitleCase::query()->find($subtitleCaseId);

        if ($subtitleCase instanceof SubtitleCase) {
            resolve(SubtitleCaseLifecycle::class)->needsReview($subtitleCase, 'Bazarr probe failed.');
        }
    }
}
