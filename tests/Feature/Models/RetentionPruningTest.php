<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\SubtitleCaseStatus;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\AiPriceRefreshRun;
use App\Models\MediaReplacementAttempt;
use App\Models\SubtitleCase;
use App\Models\SubtitleCaseAttempt;
use App\Models\SubtitleUpload;
use App\Models\WebhookEvent;

test('model:prune removes rows past their retention window and keeps fresh ones', function (): void {
    config()->set('mediamanager.retention.webhook_events_days', 90);
    config()->set('mediamanager.retention.activity_logs_days', 180);

    $oldEvent = WebhookEvent::factory()->create();
    WebhookEvent::query()->whereKey($oldEvent->id)->update(['created_at' => now()->subDays(120)]);
    $freshEvent = WebhookEvent::factory()->create();

    $oldLog = ActivityLog::factory()->create();
    ActivityLog::query()->whereKey($oldLog->id)->update(['created_at' => now()->subDays(200)]);

    $this->artisan('model:prune', [
        '--model' => [WebhookEvent::class, ActivityLog::class],
    ])->assertSuccessful();

    expect(WebhookEvent::query()->whereKey($oldEvent->id)->exists())->toBeFalse()
        ->and(WebhookEvent::query()->whereKey($freshEvent->id)->exists())->toBeTrue()
        ->and(ActivityLog::query()->whereKey($oldLog->id)->exists())->toBeFalse();
});

test('a retention of zero disables pruning for that table', function (): void {
    config()->set('mediamanager.retention.webhook_events_days', 0);

    $event = WebhookEvent::factory()->create();
    WebhookEvent::query()->whereKey($event->id)->update(['created_at' => now()->subYears(5)]);

    $this->artisan('model:prune', ['--model' => [WebhookEvent::class]])->assertSuccessful();

    expect(WebhookEvent::query()->whereKey($event->id)->exists())->toBeTrue();
});

test('in-flight media replacement attempts are never pruned', function (): void {
    config()->set('mediamanager.retention.media_replacement_attempts_days', 30);

    $inFlight = MediaReplacementAttempt::factory()->create(['completed_at' => null]);
    MediaReplacementAttempt::query()->whereKey($inFlight->id)->update(['created_at' => now()->subDays(120)]);

    $terminal = MediaReplacementAttempt::factory()->create(['completed_at' => now()->subDays(120)]);

    $this->artisan('model:prune', ['--model' => [MediaReplacementAttempt::class]])->assertSuccessful();

    expect(MediaReplacementAttempt::query()->whereKey($inFlight->id)->exists())->toBeTrue()
        ->and(MediaReplacementAttempt::query()->whereKey($terminal->id)->exists())->toBeFalse();
});

test('model:prune removes old terminal action requests and never touches live ones', function (): void {
    config()->set('mediamanager.retention.action_requests_days', 180);

    $oldCompleted = ActionRequest::factory()->completed()->create();
    $oldRejected = ActionRequest::factory()->create(['status' => ActionRequestStatus::Rejected]);
    $oldPending = ActionRequest::factory()->create();
    $oldExecuting = ActionRequest::factory()->create(['status' => ActionRequestStatus::Executing]);
    $freshFailed = ActionRequest::factory()->create(['status' => ActionRequestStatus::Failed]);
    ActionRequest::query()
        ->whereKey([$oldCompleted->id, $oldRejected->id, $oldPending->id, $oldExecuting->id])
        ->update(['updated_at' => now()->subDays(200)]);

    $this->artisan('model:prune', ['--model' => [ActionRequest::class]])->assertSuccessful();

    expect(ActionRequest::query()->orderBy('id')->pluck('id')->all())
        ->toBe([$oldPending->id, $oldExecuting->id, $freshFailed->id]);
});

test('an old terminal action request that live rows still point at is kept', function (): void {
    config()->set('mediamanager.retention.action_requests_days', 180);

    [$downloadLinked, $replacementLinked, $attemptLinked, $uploadLinked, $mediaReplacementLinked, $unlinked] = ActionRequest::factory()
        ->completed()
        ->count(6)
        ->create()
        ->all();
    SubtitleCase::factory()->create(['download_action_request_id' => $downloadLinked->id]);
    SubtitleCase::factory()->create(['replacement_action_request_id' => $replacementLinked->id]);
    SubtitleCaseAttempt::factory()->create(['action_request_id' => $attemptLinked->id]);
    SubtitleUpload::factory()->create(['action_request_id' => $uploadLinked->id]);
    MediaReplacementAttempt::factory()->create(['action_request_id' => $mediaReplacementLinked->id, 'completed_at' => now()->subDays(200)]);
    ActionRequest::query()->update(['updated_at' => now()->subDays(200)]);

    $this->artisan('model:prune', ['--model' => [ActionRequest::class]])->assertSuccessful();

    expect(ActionRequest::query()->whereKey($unlinked->id)->exists())->toBeFalse()
        ->and(ActionRequest::query()->whereKey([
            $downloadLinked->id, $replacementLinked->id, $attemptLinked->id, $uploadLinked->id, $mediaReplacementLinked->id,
        ])->count())->toBe(5);
});

test('a zero action request retention keeps every row', function (): void {
    config()->set('mediamanager.retention.action_requests_days', 0);

    $completed = ActionRequest::factory()->completed()->create();
    ActionRequest::query()->whereKey($completed->id)->update(['updated_at' => now()->subYears(5)]);

    $this->artisan('model:prune', ['--model' => [ActionRequest::class]])->assertSuccessful();

    expect(ActionRequest::query()->whereKey($completed->id)->exists())->toBeTrue();
});

test('model:prune removes old resolved and superseded subtitle cases with their history but keeps closed decisions and live cases', function (): void {
    config()->set('mediamanager.retention.subtitle_cases_days', 180);

    $resolved = SubtitleCase::factory()->create(['status' => SubtitleCaseStatus::Resolved, 'resolved_at' => now()->subDays(200)]);
    $superseded = SubtitleCase::factory()->create(['status' => SubtitleCaseStatus::Superseded, 'superseded_at' => now()->subDays(200)]);
    $recentlyResolved = SubtitleCase::factory()->create(['status' => SubtitleCaseStatus::Resolved, 'resolved_at' => now()->subDays(10)]);
    $dismissed = SubtitleCase::factory()->create(['status' => SubtitleCaseStatus::Dismissed]);
    $handled = SubtitleCase::factory()->create(['status' => SubtitleCaseStatus::Handled]);
    $observing = SubtitleCase::factory()->create();
    SubtitleCase::query()->whereKey([$dismissed->id, $handled->id, $observing->id])->update(['updated_at' => now()->subDays(400)]);
    $attempt = SubtitleCaseAttempt::factory()->create(['subtitle_case_id' => $resolved->id]);
    $cleanedUpload = SubtitleUpload::factory()->create(['subtitle_case_id' => $superseded->id, 'cleaned_up_at' => now()->subDays(199)]);

    $this->artisan('model:prune', ['--model' => [SubtitleCase::class]])->assertSuccessful();

    expect(SubtitleCase::query()->orderBy('id')->pluck('id')->all())
        ->toBe([$recentlyResolved->id, $dismissed->id, $handled->id, $observing->id])
        ->and(SubtitleCaseAttempt::query()->whereKey($attempt->id)->exists())->toBeFalse()
        ->and(SubtitleUpload::query()->whereKey($cleanedUpload->id)->exists())->toBeFalse();
});

test('a subtitle case whose staged upload file is not cleaned up yet is kept', function (): void {
    config()->set('mediamanager.retention.subtitle_cases_days', 180);

    $resolved = SubtitleCase::factory()->create(['status' => SubtitleCaseStatus::Resolved, 'resolved_at' => now()->subDays(200)]);
    SubtitleUpload::factory()->create(['subtitle_case_id' => $resolved->id, 'cleaned_up_at' => null]);

    $this->artisan('model:prune', ['--model' => [SubtitleCase::class]])->assertSuccessful();

    expect(SubtitleCase::query()->whereKey($resolved->id)->exists())->toBeTrue();
});

test('model:prune removes old finished price refresh runs and keeps running ones', function (): void {
    config()->set('mediamanager.retention.ai_price_refresh_runs_days', 90);

    $oldFinished = AiPriceRefreshRun::factory()->create(['status' => 'completed', 'started_at' => now()->subDays(100), 'completed_at' => now()->subDays(100)]);
    $oldRunning = AiPriceRefreshRun::factory()->create(['status' => 'running', 'started_at' => now()->subDays(100), 'completed_at' => null]);
    $recentFinished = AiPriceRefreshRun::factory()->create(['status' => 'failed', 'started_at' => now()->subDays(10), 'completed_at' => now()->subDays(10)]);

    $this->artisan('model:prune', ['--model' => [AiPriceRefreshRun::class]])->assertSuccessful();

    expect(AiPriceRefreshRun::query()->orderBy('id')->pluck('id')->all())->toBe([$oldRunning->id, $recentFinished->id])
        ->and(AiPriceRefreshRun::query()->whereKey($oldFinished->id)->exists())->toBeFalse();
});

test('pruning a case directly never deletes an upload whose file is still staged', function (): void {
    $resolved = SubtitleCase::factory()->create(['status' => SubtitleCaseStatus::Resolved, 'resolved_at' => now()->subDays(200)]);
    $attempt = SubtitleCaseAttempt::factory()->create(['subtitle_case_id' => $resolved->id]);
    $cleaned = SubtitleUpload::factory()->create(['subtitle_case_id' => $resolved->id, 'cleaned_up_at' => now()->subDay()]);
    $staged = SubtitleUpload::factory()->create(['subtitle_case_id' => $resolved->id, 'cleaned_up_at' => null]);

    expect(fn (): ?bool => $resolved->prune())->toThrow(RuntimeException::class, 'still has a staged upload file');

    // The whole prune rolled back: nothing about the case is gone.
    $this->assertModelExists($resolved);
    $this->assertModelExists($attempt);
    $this->assertModelExists($cleaned);
    $this->assertModelExists($staged);
});
