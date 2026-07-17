<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\MediaReplacementAttempt;
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
