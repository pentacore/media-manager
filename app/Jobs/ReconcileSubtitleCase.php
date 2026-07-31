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
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deliberately without a try cap: the queue counts a RateLimited release as an
 * attempt even though handle() never ran, and a full sweep enqueues far more
 * probe-capable jobs than the 10-per-minute limiter admits. A fixed try count
 * therefore burned itself out on releases alone — jobs failed with
 * MaxAttemptsExceededException without ever contacting Bazarr, and failed() then
 * parked healthy searching cases as "Bazarr probe failed". retryUntil() bounds the
 * work by time instead, so a job survives the queue it is waiting in.
 */
#[Timeout(60)]
final class ReconcileSubtitleCase implements ShouldQueue
{
    use Queueable;

    /**
     * Comfortably longer than draining one full cycle through the probe limiter
     * (max_cases_per_cycle 100 at 10 per minute) plus the retry backoff.
     */
    private const int RETRY_WINDOW_MINUTES = 60;

    /**
     * Identifies this queue message, so only its own retries can reuse the cycle
     * probe slot it consumed. It is serialized with the payload, which makes it
     * stable across retries and distinct from every other dispatch — the sweep,
     * webhooks and action listeners all dispatch this job independently for the
     * same case.
     *
     * Read through reservationIdentity(): a payload queued before this property
     * existed is restored without the constructor and leaves it uninitialized.
     */
    public readonly string $reservationId;

    /**
     * @param  array<string, mixed>  $candidate
     */
    public function __construct(
        public array $candidate,
        public bool $probeAllowed = true,
        public ?int $subtitleCaseId = null,
        public ?int $targetBazarrConnectionId = null,
    ) {
        $this->reservationId = (string) Str::uuid();
    }

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

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::RETRY_WINDOW_MINUTES);
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
        //
        // A retry reuses the reservation its own first attempt consumed, keyed by
        // this queue message. Asking for a second one lets a small budget
        // (max_probes_per_cycle = 1, or a budget other cases have spent) turn the
        // retry into a silent success: the queue would delete the job with tries
        // left and failed() would never park the case. Keying the reuse on the case
        // instead would let every other job dispatched for it — sweep, webhook,
        // action listener — call itself a retry and bypass the cycle budget.
        if (! $this->reservationHeld($subtitleCase->bazarr_connection_id)
            && ! $this->reserveProbeSlot($subtitleCase->bazarr_connection_id, $bazarrAutomationSettings)) {
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
            throw_if($this->isRetryable($throwable), $throwable);

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
        SubtitleInventoryService $subtitleInventoryService,
    ): SubtitleCase {
        try {
            $candidate = $subtitleInventoryService->caseCandidateFor($subtitleCase);
        } catch (Throwable $throwable) {
            // A failed read says nothing about the subtitle. Treating it as "still
            // missing" would move a possibly satisfied case out of the only state
            // this verification looks at, and a satisfied target has also left the
            // bulk missing feed — so nothing would ever look again. Transient
            // failures retry; anything else leaves the case exactly as it was.
            throw_if($this->isRetryable($throwable), $throwable);

            report($throwable);

            return $subtitleCase->fresh() ?? $subtitleCase;
        }

        // An unreadable target (missing mapping, vanished media) is not an
        // authoritative "still missing" either.
        if ($candidate === null) {
            return $subtitleCase->fresh() ?? $subtitleCase;
        }

        $reconciled = $subtitleCaseReconciler->reconcile($candidate);
        $subtitleCase = $reconciled instanceof SubtitleCase
            ? $reconciled
            : ($subtitleCase->fresh() ?? $subtitleCase);

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
     * No linked download is expected to act any more: each of them either completed,
     * or failed with an uncertain outcome that this live read has now verified. A
     * probe queues one request per missing language, so the whole correlated set has
     * to be settled — leaving the waiting state while an earlier language is still
     * pending would strand the case, because that request's later completion no
     * longer finds a `download_requested` case to reconcile.
     */
    private function downloadSettled(SubtitleCase $subtitleCase): bool
    {
        $requestIds = $this->downloadRequestIds($subtitleCase);

        if ($requestIds === []) {
            return false;
        }

        $actionRequests = ActionRequest::query()->findMany($requestIds);

        if ($actionRequests->isEmpty()) {
            return false;
        }

        return $actionRequests->every(fn (ActionRequest $actionRequest): bool => $actionRequest->status === ActionRequestStatus::Completed
            || ($actionRequest->status === ActionRequestStatus::Failed
                && ($actionRequest->result['indeterminate'] ?? false) === true));
    }

    /**
     * The scalar column keeps only the most recent request; every per-language
     * request lives in the evidence map.
     *
     * @return list<int>
     */
    private function downloadRequestIds(SubtitleCase $subtitleCase): array
    {
        $recorded = $subtitleCase->evidence['download_requests'] ?? null;
        $requestIds = is_array($recorded)
            ? array_values(array_filter(array_map(
                static fn (mixed $id): ?int => is_int($id) && $id > 0 ? $id : null,
                $recorded,
            ), static fn (?int $id): bool => $id !== null))
            : [];

        if ($subtitleCase->download_action_request_id !== null) {
            $requestIds[] = $subtitleCase->download_action_request_id;
        }

        return array_values(array_unique($requestIds));
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
        return $this->probeAttemptsWithinSpacing($subtitleCase, $bazarrAutomationSettings)
            ->whereNot('outcome', SubtitleCaseAttemptOutcome::Failed)
            ->exists();
    }

    /**
     * @return Builder<SubtitleCaseAttempt>
     */
    private function probeAttemptsWithinSpacing(
        SubtitleCase $subtitleCase,
        BazarrAutomationSettings $bazarrAutomationSettings,
    ): Builder {
        return SubtitleCaseAttempt::query()
            ->where('subtitle_case_id', $subtitleCase->id)
            ->where('type', SubtitleCaseAttemptType::Probe)
            ->where('started_at', '>', now()->subHours($bazarrAutomationSettings->probeSpacingHours()));
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

                $ttl = now()->addMinutes($bazarrAutomationSettings->reconciliationIntervalMinutes());
                Cache::put($key, $used + 1, $ttl);
                // Records that this queue message owns one of the cycle's slots. It
                // expires with the cycle, so a retry landing in the next cycle asks
                // for admission again against that cycle's fresh budget.
                Cache::put($this->reservationKey($connectionId), true, $ttl);

                return true;
            },
        );
    }

    private function reservationHeld(int $connectionId): bool
    {
        return Cache::get($this->reservationKey($connectionId)) !== null;
    }

    private function reservationKey(int $connectionId): string
    {
        return sprintf('bazarr-probe-cycle-reservation:%d:%s', $connectionId, $this->reservationIdentity());
    }

    /**
     * A payload queued by the previous release restores the four properties it knew
     * about and never runs the constructor, so the identity is derived from the
     * payload itself: stable across that message's own retries, without reading an
     * uninitialized typed property and failing every attempt.
     */
    private function reservationIdentity(): string
    {
        if (! isset($this->reservationId)) {
            $this->reservationId = 'payload:'.hash('sha256', json_encode([
                $this->candidate,
                $this->probeAllowed,
                $this->subtitleCaseId,
                $this->targetBazarrConnectionId,
            ], JSON_THROW_ON_ERROR));
        }

        return $this->reservationId;
    }

    public function failed(?Throwable $throwable): void
    {
        Log::error('Subtitle case reconciliation failed.', [
            'bazarr_connection_id' => $this->candidate['bazarr_connection_id'] ?? null,
            'service_connection_id' => $this->candidate['service_connection_id'] ?? null,
            'exception' => $throwable instanceof Throwable ? $throwable::class : null,
        ]);

        // The retries are spent, so the case that was probing when the last attempt
        // failed is parked for an operator instead of silently dropping out of
        // automation. The queue reconstructs this job from its serialized payload
        // before calling failed(), so the case is resolved from constructor data —
        // anything recorded on the instance that threw is already gone.
        $subtitleCase = $this->probedSubtitleCase();

        if ($subtitleCase instanceof SubtitleCase) {
            resolve(SubtitleCaseLifecycle::class)->needsReview($subtitleCase, 'Bazarr probe failed.');
        }
    }

    /**
     * Only a case still searching was the one this job probed. A case that moved on
     * — a concurrently dispatched reconciliation can queue a download while this job
     * sits in backoff — must not be dragged into review: the completion listener
     * only verifies a case that is still download_requested, so parking it here
     * would strand the download result. The guard applies to targeted jobs too,
     * which are exactly the ones another dispatch can overtake.
     */
    private function probedSubtitleCase(): ?SubtitleCase
    {
        $builder = SubtitleCase::query()->where('status', SubtitleCaseStatus::BazarrSearching);

        if ($this->subtitleCaseId !== null) {
            return $builder->whereKey($this->subtitleCaseId)->first();
        }

        foreach (['bazarr_connection_id', 'service_connection_id', 'file_fingerprint', 'requirements_fingerprint'] as $key) {
            if (! isset($this->candidate[$key])) {
                return null;
            }
        }

        return $builder
            ->where('bazarr_connection_id', $this->candidate['bazarr_connection_id'])
            ->where('service_connection_id', $this->candidate['service_connection_id'])
            ->where('file_fingerprint', $this->candidate['file_fingerprint'])
            ->where('requirements_fingerprint', $this->candidate['requirements_fingerprint'])
            ->first();
    }
}
