<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\WebhookEvent;
use App\Services\DashboardMetrics\DashboardMetricsRepository;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->repo = resolve(DashboardMetricsRepository::class);
});

test('webhookSparkline returns 24 zeros when no webhook_events exist', function (): void {
    expect($this->repo->webhookSparkline())->toHaveCount(24);
    expect(array_sum($this->repo->webhookSparkline()))->toBe(0);
});

test('webhookSparkline buckets webhook events by hour, oldest first', function (): void {
    $now = CarbonImmutable::create(2026, 4, 28, 12, 30, 0, 'UTC');

    // 23 hours ago (oldest), 5 hours ago, and "now".
    WebhookEvent::factory()->create([
        'created_at' => $now->subHours(23),
    ]);
    WebhookEvent::factory()->create([
        'created_at' => $now->subHours(5),
    ]);
    WebhookEvent::factory()->create([
        'created_at' => $now->subHours(5)->addMinutes(20),
    ]);
    WebhookEvent::factory()->create([
        'created_at' => $now,
    ]);

    $spark = $this->repo->webhookSparkline(24, $now);

    expect($spark)->toHaveCount(24);
    expect($spark[0])->toBe(1);
    expect($spark[18])->toBe(2);
    expect($spark[23])->toBe(1);
});

test('actionSparkline counts every action regardless of status', function (): void {
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Failed]);

    expect(array_sum($this->repo->actionSparkline()))->toBe(2);
});

test('failedActionSparkline only counts failed action_requests', function (): void {
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Failed]);
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Failed]);

    expect(array_sum($this->repo->failedActionSparkline()))->toBe(2);
});
