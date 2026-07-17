<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\SubtitleAdvisorAgent;
use App\Ai\SubtitleAdvisor\SubtitleAdvisorRunContext;
use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Enums\SubtitleCaseStatus;
use App\Enums\UserRole;
use App\Models\ActionRequest;
use App\Models\SubtitleCase;
use App\Models\SubtitleCaseAttempt;
use App\Models\User;
use App\Notifications\SubtitleCaseNeedsReview;
use App\Providers\AIServiceProvider;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiBudget\AiBudgetGuard;
use App\Services\Bazarr\SubtitleCaseLifecycle;
use App\Settings\AiSettings;
use App\Settings\BazarrAutomationSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

#[Timeout(180)]
#[Tries(1)]
final class RunSubtitleAdvisor implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $subtitleCaseId) {}

    public function uniqueId(): string
    {
        return 'subtitle-advisor:'.$this->subtitleCaseId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('bazarr-advisor')->releaseAfter(60)];
    }

    public function handle(
        BazarrAutomationSettings $bazarrAutomationSettings,
        SubtitleCaseLifecycle $subtitleCaseLifecycle,
        AiBudgetGuard $aiBudgetGuard,
        AiSettings $aiSettings,
    ): void {
        if (! AIServiceProvider::enabled() || ! $bazarrAutomationSettings->enabled()) {
            return;
        }

        $subtitleCase = SubtitleCase::query()->find($this->subtitleCaseId);

        if (! $subtitleCase instanceof SubtitleCase
            || $subtitleCase->status !== SubtitleCaseStatus::ReplacementEligible
            || ! $subtitleCaseLifecycle->transition($subtitleCase, SubtitleCaseStatus::AdvisorRunning)) {
            return;
        }

        $subtitleCaseAttempt = SubtitleCaseAttempt::query()->create([
            'subtitle_case_id' => $subtitleCase->id,
            'type' => SubtitleCaseAttemptType::Advisor,
            'outcome' => SubtitleCaseAttemptOutcome::Started,
            'summary' => ['result' => 'started'],
            'started_at' => now(),
        ]);

        try {
            $aiBudgetGuard->enforce();
        } catch (AiBudgetExceededException $aiBudgetExceededException) {
            $this->finishWithReview(
                $subtitleCase,
                $subtitleCaseAttempt,
                $subtitleCaseLifecycle,
                'AI budget cap prevented Advisor investigation.',
                'budget_exceeded',
                $aiBudgetExceededException,
            );

            return;
        }

        $subtitleAdvisorRunContext = new SubtitleAdvisorRunContext(caseId: $subtitleCase->id, maxActions: 1);
        app()->instance(SubtitleAdvisorRunContext::class, $subtitleAdvisorRunContext);

        try {
            $subtitleAdvisorAgent = new SubtitleAdvisorAgent;
            $providerChain = $aiSettings->providerChainWithModel($subtitleAdvisorAgent->model());
            $prompt = $this->prompt($subtitleCase);
            $response = $providerChain === null
                ? $subtitleAdvisorAgent->prompt($prompt)
                : $subtitleAdvisorAgent->prompt($prompt, provider: $providerChain);
            $summary = trim($response->text) !== ''
                ? trim($response->text)
                : 'The Advisor produced no audit summary.';
        } catch (Throwable $throwable) {
            Log::warning('Subtitle Advisor run failed.', [
                'subtitle_case_id' => $subtitleCase->id,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
            $this->finishWithReview(
                $subtitleCase,
                $subtitleCaseAttempt,
                $subtitleCaseLifecycle,
                'The Media Advisor could not complete its investigation.',
                'agent_failure',
                $throwable,
            );

            return;
        } finally {
            app()->forgetInstance(SubtitleAdvisorRunContext::class);
        }

        $actionRequestId = $subtitleAdvisorRunContext->actionRequestId();

        if ($actionRequestId === null) {
            $this->finishWithReview(
                $subtitleCase,
                $subtitleCaseAttempt,
                $subtitleCaseLifecycle,
                $summary,
                'no_automatic_candidate',
            );

            return;
        }

        $actionRequest = ActionRequest::query()->find($actionRequestId);

        if (! $actionRequest instanceof ActionRequest
            || (int) ($actionRequest->payload['subtitle_case_id'] ?? 0) !== $subtitleCase->id) {
            $this->finishWithReview(
                $subtitleCase,
                $subtitleCaseAttempt,
                $subtitleCaseLifecycle,
                'The Advisor action could not be correlated to this subtitle case.',
                'action_correlation_failed',
            );

            return;
        }

        $transitioned = $subtitleCaseLifecycle->transition(
            $subtitleCase->fresh(),
            SubtitleCaseStatus::ReplacementRequested,
            ['replacement_action_request_id' => $actionRequest->id],
        );

        if (! $transitioned) {
            return;
        }

        $subtitleCaseAttempt->forceFill([
            'action_request_id' => $actionRequest->id,
            'candidate_count' => 1,
            'eligible_candidate_count' => 1,
            'summary' => [
                'result' => 'replacement_requested',
                'summary' => $this->boundedSummary($summary),
            ],
            'outcome' => SubtitleCaseAttemptOutcome::Succeeded,
            'completed_at' => now(),
        ])->save();
    }

    public function failed(?Throwable $throwable): void
    {
        $subtitleCase = SubtitleCase::query()->find($this->subtitleCaseId);

        if (! $subtitleCase instanceof SubtitleCase) {
            return;
        }

        $subtitleCaseLifecycle = resolve(SubtitleCaseLifecycle::class);

        if ($subtitleCase->status === SubtitleCaseStatus::ReplacementEligible) {
            $subtitleCaseLifecycle->transition($subtitleCase, SubtitleCaseStatus::AdvisorRunning);
            $subtitleCase->refresh();
        }

        if ($subtitleCase->status !== SubtitleCaseStatus::AdvisorRunning) {
            return;
        }

        $attempt = SubtitleCaseAttempt::query()
            ->where('subtitle_case_id', $subtitleCase->id)
            ->where('type', SubtitleCaseAttemptType::Advisor)
            ->latest('id')
            ->first();
        $attempt ??= SubtitleCaseAttempt::query()->create([
            'subtitle_case_id' => $subtitleCase->id,
            'type' => SubtitleCaseAttemptType::Advisor,
            'outcome' => SubtitleCaseAttemptOutcome::Started,
            'summary' => ['result' => 'started'],
            'started_at' => now(),
        ]);

        $this->finishWithReview(
            $subtitleCase,
            $attempt,
            $subtitleCaseLifecycle,
            'The Media Advisor worker stopped before completing the investigation.',
            'worker_failure',
            $throwable,
        );
    }

    private function prompt(SubtitleCase $subtitleCase): string
    {
        return sprintf(
            'Investigate subtitle case %d. Bazarr exhausted its configured retries without satisfying the required subtitles. Inspect this exact case once, queue only its unique automatic candidate if one exists, then provide a concise audit summary.',
            $subtitleCase->id,
        );
    }

    private function finishWithReview(
        SubtitleCase $subtitleCase,
        SubtitleCaseAttempt $subtitleCaseAttempt,
        SubtitleCaseLifecycle $subtitleCaseLifecycle,
        string $summary,
        string $errorCategory,
        ?Throwable $throwable = null,
    ): void {
        $summary = $this->boundedSummary($summary);
        $subtitleCaseAttempt->forceFill([
            'summary' => [
                'result' => 'needs_review',
                'summary' => $summary,
            ],
            'outcome' => $errorCategory === 'no_automatic_candidate'
                ? SubtitleCaseAttemptOutcome::NeedsReview
                : SubtitleCaseAttemptOutcome::Failed,
            'error_category' => $errorCategory,
            'completed_at' => now(),
        ])->save();

        $freshCase = $subtitleCase->fresh();

        if ($freshCase->status === SubtitleCaseStatus::AdvisorRunning) {
            $subtitleCaseLifecycle->needsReview($freshCase, $summary);
        }

        $this->notifyNeedsReview($freshCase, $summary, $errorCategory);

        report_if($throwable instanceof Throwable, $throwable);
    }

    private function notifyNeedsReview(
        SubtitleCase $subtitleCase,
        string $summary,
        string $category,
    ): void {
        $admins = User::query()->where('role', UserRole::Admin)->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new SubtitleCaseNeedsReview(
            subtitleCaseId: $subtitleCase->id,
            displayName: is_string($subtitleCase->evidence['display_name'] ?? null)
                ? $subtitleCase->evidence['display_name']
                : 'Subtitle case #'.$subtitleCase->id,
            summary: Str::limit($summary, 500, '…'),
            category: $category,
        ));
    }

    private function boundedSummary(string $summary): string
    {
        return Str::limit(trim($summary), 3_800, '');
    }
}
