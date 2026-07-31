<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bazarr;

use App\Enums\ServiceType;
use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Enums\SubtitleCaseStatus;
use App\Http\Controllers\Controller;
use App\Jobs\RunSubtitleAdvisor;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\SubtitleCaseAttempt;
use App\Models\User;
use App\Providers\AIServiceProvider;
use App\Services\Bazarr\SubtitleCaseLifecycle;
use App\Settings\BazarrAutomationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class AdvisorController extends Controller
{
    public function __invoke(
        Request $request,
        SubtitleCase $subtitleCase,
        SubtitleCaseLifecycle $subtitleCaseLifecycle,
        BazarrAutomationSettings $bazarrAutomationSettings,
    ): RedirectResponse {
        // RunSubtitleAdvisor returns immediately when either gate is closed — the
        // default state — without recording an attempt or restoring the case.
        // Accepting the retry anyway would move the case out of needs_review, tell
        // the operator it was queued, and then do nothing at all.
        if (! AIServiceProvider::enabled() || ! $bazarrAutomationSettings->enabled()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Media Advisor is unavailable while AI features or Bazarr automation are disabled.'),
            ]);

            return back();
        }

        $connectionId = $request->integer('connection');
        $connectionExists = ServiceConnection::query()
            ->whereKey($connectionId)
            ->where('type', ServiceType::Bazarr)
            ->where('is_active', true)
            ->exists();

        abort_unless($connectionExists, 404);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        DB::transaction(function () use (
            $connectionId,
            $request,
            $subtitleCase,
            $subtitleCaseLifecycle,
            $user,
        ): void {
            $lockedCase = SubtitleCase::query()
                ->whereKey($subtitleCase->id)
                ->where('bazarr_connection_id', $connectionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCase->status === SubtitleCaseStatus::NeedsReview) {
                $request->validate([
                    'confirm_retry' => ['required', 'accepted'],
                ]);

                if (! $subtitleCaseLifecycle->transition($lockedCase, SubtitleCaseStatus::ReplacementEligible)) {
                    return;
                }

                SubtitleCaseAttempt::query()->create([
                    'subtitle_case_id' => $lockedCase->id,
                    'type' => SubtitleCaseAttemptType::Reconciliation,
                    'outcome' => SubtitleCaseAttemptOutcome::Succeeded,
                    'summary' => [
                        'result' => 'manual_retry_requested',
                        'requested_by_user_id' => $user->id,
                    ],
                    'started_at' => now(),
                    'completed_at' => now(),
                ]);
            } else {
                abort_unless($lockedCase->status === SubtitleCaseStatus::ReplacementEligible, 409);
            }

            dispatch(new RunSubtitleAdvisor($lockedCase->id))->afterCommit();
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Media Advisor investigation queued.'),
        ]);

        return back();
    }
}
